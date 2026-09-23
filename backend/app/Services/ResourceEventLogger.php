<?php

namespace App\Services;

use App\Models\ResourceEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Single entry point for writing rows to the resource_events audit log.
 *
 * Callers should use the named helpers (nameAccepted, autoApproved, …) when
 * available so the event_type string stays consistent across the codebase.
 * For one-off cases there is a generic log() escape hatch.
 *
 * Event-type vocabulary (extend here as new sites get instrumented):
 *
 *   Suggestion lifecycle (per-field, optionally tied to a system_file):
 *     name_accepted, name_dismissed, name_auto_approved
 *     description_accepted, description_dismissed, description_auto_approved
 *     tags_accepted, tags_dismissed, tags_auto_approved
 *
 *   Auto-approve session (workspace-scoped):
 *     auto_approve_started, auto_approve_completed, auto_approve_failed
 *
 *   AITY worker:
 *     aity_enrichment_started, aity_enrichment_failed, aity_enrichment_retried
 *
 *   Resource-level:
 *     resource_created, resource_updated, resource_state_changed,
 *     status_transitioned
 *
 *   Processing failures:
 *     embedding_failed   (payload: error, reason, driver, model)
 */
class ResourceEventLogger
{
    public const ACTOR_USER = 'user';

    public const ACTOR_AITY = 'aity';

    public const ACTOR_SYSTEM = 'system';

    public const TARGET_SYSTEM_FILE = 'system_file';

    public const TARGET_FILE = 'file';

    public const TARGET_RESOURCE = 'resource';

    public const TARGET_WORKSPACE = 'workspace';

    /**
     * Lowest-level write. Returns the persisted row.
     *
     * Resolves the actor automatically when $actorType / $actorId are omitted:
     *   - if a Laravel auth user is present → actor_type=user, actor_id=auth id
     *   - otherwise → actor_type=system, actor_id=null
     * Callers writing on behalf of AITY must pass ACTOR_AITY explicitly.
     */
    public function log(
        string $resourceId,
        string $eventType,
        ?string $actorType = null,
        ?string $actorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $payload = null,
    ): ResourceEvent {
        if ($actorType === null) {
            $authId = Auth::id();
            $actorType = $authId ? self::ACTOR_USER : self::ACTOR_SYSTEM;
            $actorId = $authId ?: null;
        }

        return ResourceEvent::create([
            'id' => (string) Str::orderedUuid(),
            'resource_id' => $resourceId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a user (or AITY, when applied_by_aity=true) applying an AI suggestion.
     * $field is one of: 'name' | 'description' | 'tags'.
     * $byAity decides between *_accepted and *_auto_approved naming.
     */
    public function suggestionApplied(
        string $resourceId,
        string $field,
        bool $byAity,
        ?string $systemFileId = null,
        ?array $payload = null,
    ): ResourceEvent {
        $eventType = "{$field}_".($byAity ? 'auto_approved' : 'accepted');

        return $this->log(
            resourceId: $resourceId,
            eventType: $eventType,
            actorType: $byAity ? self::ACTOR_AITY : null,
            targetType: $systemFileId ? self::TARGET_SYSTEM_FILE : null,
            targetId: $systemFileId,
            payload: $payload,
        );
    }

    /**
     * Record a pending suggestion that was cleared without being applied —
     * either explicitly dismissed via the UI or flushed by the "save = reviewed"
     * pass on resource update.
     */
    public function suggestionDismissed(
        string $resourceId,
        string $field,
        ?string $systemFileId = null,
        ?array $payload = null,
    ): ResourceEvent {
        return $this->log(
            resourceId: $resourceId,
            eventType: "{$field}_dismissed",
            targetType: $systemFileId ? self::TARGET_SYSTEM_FILE : null,
            targetId: $systemFileId,
            payload: $payload,
        );
    }
}
