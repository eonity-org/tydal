<?php

namespace App\Jobs;

use App\Enums\AityStatus;
use App\Enums\FileRole;
use App\Models\Workspace;
use App\Services\AutoApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched when the user chooses "Upload & Auto-accept" in the wizard.
 *
 * Mirrors the Python upload.py --phase accept + --phase tags pipeline:
 *   1. Poll workspace resources until all AiTy statuses are terminal
 *      (suggestions_made | user_review_done | not_applicable).
 *   2. Call AutoApprovalService::approveWorkspace() once across the full workspace
 *      so cross-resource tag dedup runs over the complete batch.
 *
 * The job runs in the background queue — the frontend closes immediately after dispatch.
 */
class AutoApproveWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Each attempt = one readiness check. Re-queued with $pollInterval delay when not ready.
    // 120 attempts × 10s default interval = 20 min max wait before giving up and proceeding.
    public int $tries = 120;

    public int $timeout = 60;   // single attempt must complete well within a minute

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $orgId,
        private readonly array $options = [],
    ) {}

    public function handle(AutoApprovalService $service): void
    {
        $workspace = Workspace::where('id', $this->workspaceId)
            ->where('organization_id', $this->orgId)
            ->first();

        if (! $workspace) {
            Log::error("AutoApproveWorkspaceJob: workspace {$this->workspaceId} not found in org {$this->orgId}");

            return;
        }

        $pollInterval = (int) ($this->options['poll_interval'] ?? 10);
        $processing = [AityStatus::QUEUED->value, AityStatus::AITY_IN_PROGRESS->value];

        // Load resources with files once — used for both the readiness check and completion logging.
        $allResources = $workspace->resources()->with('files')->get();
        $total = $allResources->count();
        $notReady = $allResources->filter(fn ($r) => in_array((string) $r->aity_status, $processing))->count();

        // ── Detect and log newly-completed resources (runs on every attempt) ──────
        // This must happen before the notReady check so that resources completing in
        // the same attempt that clears the queue are still captured in the log.
        $existing = $workspace->auto_approve_log ?? [];
        $lines = $existing['log'] ?? [];
        $loggedIds = $existing['_logged_ids'] ?? [];

        $newlyDone = $allResources->filter(
            fn ($r) => ! in_array((string) $r->aity_status, $processing)
                   && ! in_array($r->id, $loggedIds)
        );

        foreach ($newlyDone as $resource) {
            $loggedIds[] = $resource->id;
            $name = $resource->name ? "\"{$resource->name}\"" : '(untitled)';
            $files = $resource->files;
            $fileCount = $files->count();

            $hasCanonical = $files->contains(
                fn ($f) => $f->role === FileRole::CANONICAL && $f->is_active
            );
            $resType = $hasCanonical
                ? 'canonical'
                : ($fileCount > 1 ? 'multi-component' : 'single-file');

            if ((string) $resource->aity_status === AityStatus::NOT_APPLICABLE->value) {
                $lines[] = "  ✗ {$name} — no AI support · {$resType} · {$fileCount} file(s)";
            } else {
                $mimes = $files->pluck('mime_type');
                $methods = [];
                if ($mimes->contains(fn ($m) => str_starts_with((string) $m, 'image/'))) {
                    $methods[] = 'vision AI';
                }
                if ($mimes->contains(fn ($m) => ! str_starts_with((string) $m, 'image/') && $m !== null)) {
                    $methods[] = 'text analysis';
                }
                $aiHint = $methods ? ' · '.implode(' + ', $methods) : '';
                $lines[] = "  ✓ {$name} — {$resType} · {$fileCount} file(s){$aiHint}";
            }
        }

        if ($notReady > 0) {
            $ready = $total - $notReady;
            Log::info("AutoApproveWorkspaceJob: waiting — {$notReady}/{$total} resource(s) still processing (attempt {$this->attempts()})");

            $lines[] = "Analyzing... {$ready}/{$total} resource(s) ready.";

            $workspace->updateQuietly([
                'auto_approve_status' => 'pending',
                'auto_approve_log' => [
                    'log' => $lines,
                    '_logged_ids' => $loggedIds,
                    'partial' => true,
                    'resources_processed' => 0,
                    'names_applied' => 0,
                    'descriptions_applied' => 0,
                    'tags_applied' => 0,
                    'tags_skipped' => 0,
                    'dry_run' => false,
                ],
            ]);
            $this->release($pollInterval);

            return;
        }

        Log::info("AutoApproveWorkspaceJob: all resources ready — running auto-approval for workspace {$this->workspaceId}");
        $workspace->updateQuietly(['auto_approve_status' => 'running']);

        try {
            // $lines already holds the full analysis-phase log (including the final batch's completion
            // lines detected above). Append the approval phase on top without re-reading from DB.
            $partialLines = $lines;
            $partialLines[] = '';
            $partialLines[] = '── Auto-approval starting ──';
            $service->setLogCallback(function (string $line) use ($workspace, &$partialLines): void {
                $partialLines[] = $line;
                if (count($partialLines) % 5 === 0) {
                    $workspace->updateQuietly([
                        'auto_approve_log' => ['log' => $partialLines, 'partial' => true],
                    ]);
                }
            });

            $result = $service->approveWorkspace($this->workspaceId, $this->orgId, $this->options);
            // Replace the service's partial log with the full history (analysis phase + approval phase)
            $result['log'] = $partialLines;
            $workspace->updateQuietly([
                'auto_approve_status' => 'done',
                'auto_approve_log' => $result,
            ]);
            Log::info("AutoApproveWorkspaceJob: workspace {$this->workspaceId} auto-approved successfully");
        } catch (\Throwable $e) {
            Log::error("AutoApproveWorkspaceJob: workspace {$this->workspaceId} failed: ".$e->getMessage());
            $partialLines[] = '';
            $partialLines[] = 'ERROR: '.$e->getMessage();
            $workspace->updateQuietly([
                'auto_approve_status' => 'failed',
                'auto_approve_log' => ['log' => $partialLines, 'partial' => false],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("AutoApproveWorkspaceJob: permanently failed for workspace {$this->workspaceId}: ".$exception->getMessage());

        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $existing = $workspace->auto_approve_log ?? [];
        $lines = $existing['log'] ?? [];
        $lines[] = '';
        $lines[] = 'FAILED: '.$exception->getMessage();

        $workspace->updateQuietly([
            'auto_approve_status' => 'failed',
            'auto_approve_log' => array_merge($existing, ['log' => $lines, 'partial' => false]),
        ]);
    }
}
