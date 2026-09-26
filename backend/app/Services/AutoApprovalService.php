<?php

namespace App\Services;

use App\Enums\FileRole;
use App\Enums\SystemFilePurpose;
use App\Enums\TagReviewer;
use App\Enums\TagVocabulary;
use App\Jobs\IndexResourceToElasticsearch;
use App\Jobs\UpsertResourceMetadataChunk;
use App\Models\Resource;
use App\Models\SemanticTag;
use App\Models\SystemFile;
use App\Models\Workspace;
use App\Services\LLM\Contracts\LlmServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Automatic approval of AI suggestions for a workspace or resource set.
 *
 * Originally ported from the (since removed) tools/bulk_uploader/upload.py
 * `--phase accept` + `--phase tags`, so it runs from the UI without a CLI tool.
 *
 * Pipeline:
 *  1. Collect AI-suggested name / description / tags from active SystemFiles
 *  2. Apply suggested name and description to each resource
 *  3. Build cross-resource tag frequency map
 *  4. (optional) LLM dedup: reclassify entity types + merge duplicate labels
 *  5. Per-resource accept/skip decisions using confidence + frequency thresholds
 *  6. (optional) LLM top-N pruning per resource
 *  7. Apply accepted tags, clear suggestion SystemFiles, update workspace membership
 */
class AutoApprovalService
{
    // Ported verbatim from upload.py _DEDUP_SYSTEM / _TOP_TAGS_SYSTEM
    private const DEDUP_SYSTEM = <<<'PROMPT'
You are a semantic data analyst helping to clean up a tag taxonomy for a digital asset management system.

You will receive a JSON list of candidate tags. Each tag has:
  label    — tag text
  type     — entity type: person | organization | place | thing | tag
             ('tag' means the system could not identify the entity type)
  freq     — how many documents in the batch suggested this tag
  avg_conf — average AI confidence (0.0–1.0)
  in       — up to 5 document names this tag appears in

Your tasks:

1. RECLASSIFY — For any tag with type "tag", suggest the correct entity type if you can identify it.
   Valid types: person | organization | place | thing | tag (keep "tag" only if truly unclassifiable).

2. MERGE — Identify groups of 2+ tags that refer to the same concept and should be merged.
   This includes abbreviations (ML vs Machine Learning), spelling variants, synonyms, near-duplicates.
   Merging rules:
   - Only merge tags of the SAME entity type (apply reclassifications first when evaluating).
   - Choose the best canonical label (prefer full names, proper casing, most descriptive).
   - List the OTHER labels (not the canonical) as duplicates.
   - Be conservative: only group tags you are confident refer to the same concept.
   - Do NOT merge tags that are related but distinct (e.g. "Finance" and "Financial Report").

Return ONLY a valid JSON object — no explanation, no markdown fences:
{
  "reclassify": [
    {"label": "Python", "type": "thing"}
  ],
  "merges": [
    {"canonical": "Machine Learning", "type": "thing", "duplicates": ["ML", "AI"], "reason": "..."}
  ]
}
Use empty arrays if nothing applies.
PROMPT;

    private const TOP_TAGS_SYSTEM = <<<'PROMPT'
You are a tag curator for a digital asset management system.
You will receive a resource (name and description) and a list of candidate tags with their confidence scores.
Your task: select the most relevant and specific tags for this resource, up to the limit in max_tags.

Rules:
- Prefer specific over generic tags.
- Prefer tags clearly relevant to the resource's content, not tangentially related.
- Respect max_tags. If fewer candidates are clearly relevant, return fewer.
- Return ONLY a valid JSON array of selected label strings — no explanation, no markdown.

Example: ["Machine Learning", "Python", "Tutorial"]
PROMPT;

    private const SYNTHESIZE_MULTI_SYSTEM = <<<'PROMPT'
You are a metadata specialist for a digital asset management system.
You will receive a JSON array of AI-generated suggestions, one entry per component file of a single multi-file resource.
Each entry contains the name, description, and tags suggested for that individual file.

Your task: produce unified metadata that ENCOMPASSES the content of ALL components together.

Rules:
- name: a single concise title that represents the complete multi-component asset, not just one file.
- description: a short paragraph summarising what the full resource contains across all components.
- tags: a combined, deduplicated set of the most relevant tags covering all components.
  Each tag object: {"label": "...", "type": "person|organization|place|thing|tag", "confidence": 0.0–1.0, "description": "one-line explanation"}
- Be specific and accurate; do not add generic padding tags.
- Return ONLY a valid JSON object with no explanation or markdown fences:
{
  "name": "unified name",
  "description": "unified description",
  "tags": [{"label": "...", "type": "...", "confidence": 0.9, "description": "..."}, ...]
}
PROMPT;

    private const VALID_ENTITY_TYPES = ['person', 'organization', 'place', 'thing', 'tag'];

    private const HR = '─────────────────────────────────────────────────';

    /** Accumulated log lines for the current run — reset at the start of each approve() call. */
    private array $log = [];

    /** Optional callback invoked immediately for every log line (used by streaming endpoint). */
    private ?\Closure $logCallback = null;

    public function setLogCallback(?callable $callback): void
    {
        $this->logCallback = $callback ? \Closure::fromCallable($callback) : null;
    }

    public function __construct(
        private readonly LlmServiceInterface $llm,
        private readonly ResourceEventLogger $eventLogger,
    ) {}

    // =========================================================================
    // PUBLIC ENTRY POINTS
    // =========================================================================

    /**
     * @throws \InvalidArgumentException when the workspace is not found in the given org
     */
    public function approveWorkspace(int $workspaceId, string $orgId, array $options = []): array
    {
        $workspace = Workspace::where('id', $workspaceId)
            ->where('organization_id', $orgId)
            ->first();

        if (! $workspace) {
            throw new \InvalidArgumentException('Workspace not found');
        }

        $resources = $workspace->resources()
            ->where('resources.organization_id', $orgId)
            ->with('files')
            ->get();

        return $this->approve($resources, $orgId, $options);
    }

    /** @param Collection<int, resource> $resources */
    public function approve(Collection $resources, string $orgId, array $options = []): array
    {
        $this->log = [];

        $confidence = (float) ($options['confidence'] ?? 0.80);
        $midConf = (float) ($options['mid_confidence'] ?? 0.65);
        $minFreq = (int) ($options['min_frequency'] ?? 3);
        $dedup = (bool) ($options['dedup'] ?? true);
        $maxTags = (int) ($options['max_tags'] ?? 4);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $applyName = (bool) ($options['apply_name'] ?? true);
        $applyDesc = (bool) ($options['apply_description'] ?? true);
        $applyTags = (bool) ($options['apply_tags'] ?? true);
        $applyMetadata = (bool) ($options['apply_metadata'] ?? ($applyName || $applyDesc));

        $this->log(sprintf(
            'Collecting suggestions from %d resource(s)... [name: %s, desc: %s, tags: %s%s%s]',
            $resources->count(),
            $applyName ? 'on' : 'off',
            $applyDesc ? 'on' : 'off',
            $applyTags ? sprintf('on (conf≥%d%%, freq≥%d', (int) ($confidence * 100), $minFreq).($dedup ? ', clustering: on' : ', clustering: off').')' : 'off',
            '',
            ''
        ));
        $this->log(self::HR);

        // ── Step 1: Collect suggestions ────────────────────────────────────────
        $allSuggestions = []; // resource_id => ['name', 'description', 'tags']
        $nameMap = []; // resource_id => display name (for LLM context)
        $descMap = []; // resource_id => description (for LLM context)
        $total = $resources->count();

        foreach ($resources as $idx => $resource) {
            $n = $idx + 1;
            $sug = $this->collectSuggestions($resource);

            $display = $resource->name ? "\"{$resource->name}\"" : '(untitled)';
            $this->log("[{$n}/{$total}] {$display}  (resource ".substr($resource->id, 0, 8).')');

            if (! $sug['name'] && ! $sug['description'] && empty($sug['tags']) && empty($sug['metadata'])) {
                $this->log('  No suggestions found — skipped.');

                continue;
            }

            $allSuggestions[$resource->id] = $sug;
            $nameMap[$resource->id] = $resource->name ?? 'Untitled';
            $descMap[$resource->id] = '';

            if ($sug['name']) {
                $this->log('  Name:        "'.$sug['name'].'"');
            } else {
                $this->log('  Name:        (no suggestion)');
            }
            if ($sug['description']) {
                $preview = mb_substr($sug['description'], 0, 80).(mb_strlen($sug['description']) > 80 ? '…' : '');
                $this->log("  Description: \"{$preview}\"");
            } else {
                $this->log('  Description: (no suggestion)');
            }
            $this->log('  Tags:        '.count($sug['tags']).' suggestion(s)');
        }

        if (empty($allSuggestions)) {
            $this->log(self::HR);
            $this->log('No suggestions to process. All done.');

            return $this->buildResult(0, 0, 0, 0, 0, $dryRun);
        }

        // ── Step 2: Apply name and description ────────────────────────────────
        $this->log(self::HR);
        $this->log('Applying names and descriptions'.($dryRun ? ' [DRY RUN]' : '').'...');

        $namesApplied = 0;
        $descriptionsApplied = 0;

        foreach ($allSuggestions as $resourceId => $sug) {
            $resource = $resources->firstWhere('id', $resourceId);
            if (! $resource) {
                continue;
            }

            $display = "\"{$nameMap[$resourceId]}\"";
            $update = [];

            if ($applyName && ! empty($sug['name'])) {
                $update['name'] = $sug['name'];
                $update['name_origin'] = $sug['name_origin'] ?? Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $update['name_source_file_id'] = $sug['name_source_file_id'] ?? null;
                $update['name_set_by'] = Resource::FIELD_AGENT_AITY;
                $update['name_set_at'] = now();
                $nameMap[$resourceId] = $sug['name'];
                $namesApplied++;
                $this->log("  {$display}  →  name: \"{$sug['name']}\"");
            }
            if ($applyDesc && ! empty($sug['description'])) {
                $update['description'] = $sug['description'];
                $update['description_origin'] = $sug['description_origin'] ?? Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $update['description_source_file_id'] = $sug['description_source_file_id'] ?? null;
                $update['description_set_by'] = Resource::FIELD_AGENT_AITY;
                $update['description_set_at'] = now();
                $descMap[$resourceId] = $sug['description'];
                $descriptionsApplied++;
                $preview = mb_substr($sug['description'], 0, 60).'…';
                $this->log("  {$display}  →  description: \"{$preview}\"");
            }

            if ($applyMetadata && ! empty($sug['metadata'])) {
                $fill = $this->validatedMetadataFill($resource, $sug['metadata']);
                if ($fill !== []) {
                    $update['metadata'] = array_merge($resource->metadata ?? [], $fill);
                    foreach ($fill as $key => $value) {
                        $this->log("  {$display}  →  metadata.{$key}: ".$this->payloadValueExcerpt($value));
                    }
                }
            }

            if (! $dryRun && ! empty($update)) {
                // update() triggers IndexResourceToElasticsearch via model boot hooks
                $resource->update($update);
            }
        }

        if ($namesApplied === 0 && $descriptionsApplied === 0) {
            $this->log('  Nothing to apply.');
        }

        // Early exit when tag application is disabled
        if (! $applyTags) {
            $this->log(self::HR);
            $this->log('Tag application disabled — skipping tag steps.');
            // Mark whatever was actually applied (name/desc when their flags were on) as
            // processed, and dismiss the tag suggestions since the user opted out.
            $purposesDecided = [
                SystemFilePurpose::AI_SUGGESTED_TAGS->value,
                SystemFilePurpose::AI_GENERATED_TAGS->value,
            ];
            if ($applyName) {
                $purposesDecided[] = SystemFilePurpose::AI_SUGGESTED_NAME->value;
                $purposesDecided[] = SystemFilePurpose::AI_GENERATED_NAME->value;
            }
            if ($applyDesc) {
                $purposesDecided[] = SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value;
                $purposesDecided[] = SystemFilePurpose::AI_GENERATED_DESCRIPTION->value;
            }
            if ($applyMetadata) {
                $purposesDecided[] = SystemFilePurpose::AI_SUGGESTED_METADATA->value;
            }
            foreach ($resources as $resource) {
                if (! isset($allSuggestions[$resource->id])) {
                    continue;
                }
                $fileIds = $resource->files()->pluck('files.id');
                $this->applySuggestionsAndLog(
                    resource: $resource,
                    query: SystemFile::where(function ($q) use ($fileIds, $resource) {
                        $q->whereIn('source_file_id', $fileIds)
                            ->orWhere(function ($q2) use ($resource) {
                                $q2->where('resource_id', $resource->id)->whereNull('source_file_id');
                            });
                    })->whereIn('purpose', $purposesDecided),
                );
                $resource->recomputeAndSaveAityStatus();
            }
            $this->logSummary(count($allSuggestions), $namesApplied, $descriptionsApplied, 0, 0, $dryRun);

            return $this->buildResult(count($allSuggestions), $namesApplied, $descriptionsApplied, 0, 0, $dryRun);
        }

        // ── Step 3: Cross-resource frequency map ──────────────────────────────
        // key = "label|entity_type"
        $freqMap = [];
        $tagResourceNames = [];

        foreach ($allSuggestions as $resourceId => $sug) {
            $resName = $nameMap[$resourceId] ?? 'Untitled';
            foreach ($sug['tags'] as $tag) {
                $label = trim($tag['label'] ?? '');
                $entityType = $tag['type'] ?? 'tag';
                if (! $label) {
                    continue;
                }
                $key = "{$label}|{$entityType}";
                if (! isset($freqMap[$key])) {
                    $freqMap[$key] = ['count' => 0, 'total_confidence' => 0.0, 'label' => $label, 'type' => $entityType];
                }
                $freqMap[$key]['count']++;
                $freqMap[$key]['total_confidence'] += (float) ($tag['confidence'] ?? 0.0);
                if (! in_array($resName, $tagResourceNames[$key] ?? [])) {
                    $tagResourceNames[$key][] = $resName;
                }
            }
        }

        $this->log(self::HR);
        $this->log(sprintf(
            'Cross-resource tag frequency  (%d unique tag(s), %d resource(s)):',
            count($freqMap),
            count($allSuggestions)
        ));

        uasort($freqMap, fn ($a, $b) => $b['count'] <=> $a['count'] ?: $b['total_confidence'] <=> $a['total_confidence']);
        foreach ($freqMap as $info) {
            $avg = $info['count'] > 0 ? (int) round($info['total_confidence'] / $info['count'] * 100) : 0;
            $this->log(sprintf(
                '  avg=%3d%%  freq=%d  "%s" (%s)',
                $avg,
                $info['count'],
                $info['label'],
                $info['type']
            ));
        }

        // ── Step 4: Optional LLM dedup ────────────────────────────────────────
        $normalizeMap = []; // old_key => canonical_label

        if ($dedup && ! empty($freqMap)) {
            $this->log(self::HR);
            $this->log('Dedup (LLM): sending '.count($freqMap).' tag(s) for reclassification and merge...');
            try {
                [$freqMap, $normalizeMap, $allSuggestions] = $this->runDedup(
                    $freqMap, $tagResourceNames, $allSuggestions
                );
            } catch (\Throwable $e) {
                $this->log('  WARNING: dedup call failed ('.$e->getMessage().'). Continuing without normalisation.');
                Log::warning('AutoApprovalService: dedup LLM call failed: '.$e->getMessage());
            }
        } elseif (! $dedup) {
            $this->log(self::HR);
            $this->log('Dedup: disabled.');
        }

        // ── Step 5: Per-resource accept/skip decisions ─────────────────────────
        $this->log(self::HR);
        $this->log('Tag decisions:');

        $decisions = [];
        $resCounter = 0;
        $resTotal = count($allSuggestions);

        foreach ($allSuggestions as $resourceId => $sug) {
            $resCounter++;
            $display = "\"{$nameMap[$resourceId]}\"";
            $this->log("  [{$resCounter}/{$resTotal}] {$display}");

            [$accept, $skip] = $this->makeDecisions(
                $sug['tags'], $freqMap, $normalizeMap, $confidence, $midConf, $minFreq
            );

            foreach ($accept as $t) {
                $conf = (int) round((float) ($t['confidence'] ?? 0) * 100);
                $mergeNote = $t['_canonical'] !== ($t['label'] ?? $t['_canonical']) ? " ← \"{$t['label']}\"" : '';
                $this->log(sprintf(
                    '    [ACCEPT] %-36s (%s)  conf=%d%%  [%s]%s',
                    '"'.$t['_canonical'].'"',
                    $t['type'] ?? 'tag',
                    $conf,
                    $t['_reason'],
                    $mergeNote
                ));
            }
            foreach ($skip as $t) {
                $conf = (int) round((float) ($t['confidence'] ?? 0) * 100);
                $this->log(sprintf(
                    '    [SKIP ] %-36s (%s)  conf=%d%%  [%s]',
                    '"'.$t['_canonical'].'"',
                    $t['type'] ?? 'tag',
                    $conf,
                    $t['_reason']
                ));
            }

            if (empty($accept) && empty($skip)) {
                $this->log('    (no tag suggestions)');
            }

            $decisions[$resourceId] = compact('accept', 'skip');
        }

        // ── Step 6: Optional LLM max-tags pruning per resource ────────────────
        if ($maxTags > 0) {
            $needsPruning = array_filter($decisions, fn ($d) => count($d['accept']) > $maxTags);
            if (! empty($needsPruning)) {
                $this->log(self::HR);
                $this->log("Pruning to top {$maxTags} tag(s) per resource for ".count($needsPruning).' resource(s)...');
            }
            foreach ($decisions as $resourceId => &$decision) {
                if (count($decision['accept']) > $maxTags) {
                    $display = "\"{$nameMap[$resourceId]}\"";
                    try {
                        $before = count($decision['accept']);
                        $decision = $this->pruneWithLlm(
                            $decision,
                            $nameMap[$resourceId] ?? '',
                            $descMap[$resourceId] ?? '',
                            $maxTags
                        );
                        $after = count($decision['accept']);
                        $pruned = $before - $after;
                        $this->log("  {$display}: kept {$after}, pruned {$pruned}");
                    } catch (\Throwable $e) {
                        $this->log("  {$display}: WARNING — pruning call failed ({$e->getMessage()}). Keeping all.");
                        Log::warning("AutoApprovalService: LLM pruning failed for resource {$resourceId}: ".$e->getMessage());
                    }
                }
            }
            unset($decision);
        }

        // ── Summary counts ─────────────────────────────────────────────────────
        $tagsApplied = 0;
        $tagsSkipped = 0;
        foreach ($decisions as $d) {
            $tagsApplied += count($d['accept']);
            $tagsSkipped += count($d['skip']);
        }

        if ($dryRun) {
            $this->log(self::HR);
            $this->log('[DRY RUN] No changes written. Remove dry_run to apply.');
            $this->logSummary(count($allSuggestions), $namesApplied, $descriptionsApplied, $tagsApplied, $tagsSkipped, true);

            return $this->buildResult(count($allSuggestions), $namesApplied, $descriptionsApplied, $tagsApplied, $tagsSkipped, true);
        }

        // ── Step 7: Apply tags ─────────────────────────────────────────────────
        $this->log(self::HR);
        $this->log('Applying tags...');

        foreach ($decisions as $resourceId => $decision) {
            if (empty($decision['accept'])) {
                continue;
            }
            $resource = $resources->firstWhere('id', $resourceId);
            if (! $resource) {
                continue;
            }

            $display = "\"{$nameMap[$resourceId]}\"";
            $newCount = $this->applyTags($resource, $decision['accept'], $orgId);
            $totalCount = $resource->semanticTags()->count();
            $this->log("  {$display}: +{$newCount} new tag(s) → {$totalCount} total");

            $sug = $allSuggestions[$resourceId] ?? [];

            // Detect tag rewrites by the workspace-wide LLM dedup: any accepted tag whose
            // canonical label differs from the original AITY label is generated content.
            // Archive the final canonical set so the user can recover it if they later
            // pick a per-file tag suggestion or hand-edit the tag list.
            $dedupRewrote = false;
            foreach ($decision['accept'] as $t) {
                if (! empty($t['_canonical']) && ! empty($t['label']) && $t['_canonical'] !== $t['label']) {
                    $dedupRewrote = true;
                    break;
                }
            }

            $effectiveOrigin = $sug['tags_origin'] ?? Resource::FIELD_ORIGIN_AITY_SUGGESTION;
            $effectiveSourceFileId = $sug['tags_source_file_id'] ?? null;
            if ($dedupRewrote) {
                $effectiveOrigin = Resource::FIELD_ORIGIN_AITY_GENERATED;
                $effectiveSourceFileId = null;

                $finalTagSet = array_map(static function ($t) {
                    return [
                        'label' => (string) ($t['_canonical'] ?? $t['label'] ?? ''),
                        'type' => (string) ($t['type'] ?? 'tag'),
                        'confidence' => isset($t['confidence']) ? (float) $t['confidence'] : null,
                        'description' => is_string($t['description'] ?? null) ? $t['description'] : null,
                    ];
                }, $decision['accept']);

                $this->persistResourceGenerated($resource->id, SystemFilePurpose::AI_GENERATED_TAGS, $finalTagSet);
            }

            $resource->updateQuietly([
                'tags_origin' => $effectiveOrigin,
                'tags_source_file_id' => $effectiveSourceFileId,
                'tags_set_by' => Resource::FIELD_AGENT_AITY,
                'tags_set_at' => now(),
            ]);
        }

        // ── Step 8: Mark applied SystemFiles + update workspace + recompute ────
        // We keep the suggestion records active so the Files tab AITY card can
        // always show what the analysis produced. The `applied_at` timestamp is
        // what hides them from the "found suggestions" panel on basic info.
        $perFileApplied = [];
        $resourceLevelApplied = [];
        if ($applyName) {
            $perFileApplied[] = SystemFilePurpose::AI_SUGGESTED_NAME->value;
            $resourceLevelApplied[] = SystemFilePurpose::AI_GENERATED_NAME->value;
        }
        if ($applyDesc) {
            $perFileApplied[] = SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value;
            $resourceLevelApplied[] = SystemFilePurpose::AI_GENERATED_DESCRIPTION->value;
        }
        if ($applyTags) {
            $perFileApplied[] = SystemFilePurpose::AI_SUGGESTED_TAGS->value;
            $resourceLevelApplied[] = SystemFilePurpose::AI_GENERATED_TAGS->value;
        }
        if ($applyMetadata) {
            $perFileApplied[] = SystemFilePurpose::AI_SUGGESTED_METADATA->value;
        }

        foreach ($resources as $resource) {
            if (! isset($allSuggestions[$resource->id])) {
                continue;
            }

            if (! empty($perFileApplied)) {
                $fileIds = $resource->files()->pluck('files.id');
                $this->applySuggestionsAndLog(
                    resource: $resource,
                    query: SystemFile::whereIn('source_file_id', $fileIds)->whereIn('purpose', $perFileApplied),
                );
            }
            if (! empty($resourceLevelApplied)) {
                $this->applySuggestionsAndLog(
                    resource: $resource,
                    query: SystemFile::where('resource_id', $resource->id)
                        ->whereNull('source_file_id')
                        ->whereIn('purpose', $resourceLevelApplied),
                );
            }

            $resource->recomputeAndSaveAityStatus();
        }

        $this->logSummary(count($allSuggestions), $namesApplied, $descriptionsApplied, $tagsApplied, $tagsSkipped, false);

        return $this->buildResult(count($allSuggestions), $namesApplied, $descriptionsApplied, $tagsApplied, $tagsSkipped, false);
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Mark every pending row matched by the given SystemFile query as applied by
     * AITY, and emit one *_auto_approved event per affected row.
     *
     * The caller is responsible for scoping $query to a single resource (typically
     * by source_file_id or resource_id) and the relevant purposes. This helper
     * adds the is_active + applied_at NULL filters and persists the update.
     */
    private function applySuggestionsAndLog(Resource $resource, $query): void
    {
        $rows = (clone $query)
            ->where('is_active', true)
            ->whereNull('applied_at')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        SystemFile::whereIn('id', $rows->pluck('id'))
            ->update(['applied_at' => now(), 'applied_by_aity' => true]);

        foreach ($rows as $row) {
            $field = $this->purposeToField($row->purpose);
            if ($field === null) {
                continue;
            }
            $this->eventLogger->suggestionApplied(
                resourceId: $resource->id,
                field: $field,
                byAity: true,
                systemFileId: $row->id,
                payload: [
                    'value' => $this->payloadValueExcerpt($row->metadata['value'] ?? null),
                    'source_file_id' => $row->source_file_id,
                    'purpose' => $row->purpose,
                ],
            );
        }
    }

    /**
     * The subset of suggested scheme-field values that may actually be
     * written: only EMPTY metadata keys (AITY never clobbers curator input),
     * and only values the scheme's own validation accepts — invalid keys are
     * dropped, never blocking the valid ones.
     *
     * @return array<string, mixed>
     */
    private function validatedMetadataFill(Resource $resource, array $suggested): array
    {
        $resource->loadMissing('collection');
        if (! $resource->collection) {
            return [];
        }

        $existing = $resource->metadata ?? [];
        $fill = array_filter(
            $suggested,
            fn ($v, $k) => ! isset($existing[$k]) || $existing[$k] === '',
            ARRAY_FILTER_USE_BOTH
        );

        if ($fill === []) {
            return [];
        }

        $schema = app(CollectionSchemaService::class);

        try {
            $schema->validatePartialResourceData($resource->collection, $fill);

            return $fill;
        } catch (ValidationException $e) {
            $fill = array_diff_key($fill, $e->errors());

            if ($fill === []) {
                return [];
            }

            try {
                $schema->validatePartialResourceData($resource->collection, $fill);

                return $fill;
            } catch (ValidationException) {
                return [];
            }
        }
    }

    private function purposeToField(SystemFilePurpose|string $purpose): ?string
    {
        $value = $purpose instanceof SystemFilePurpose ? $purpose->value : $purpose;

        return match ($value) {
            SystemFilePurpose::AI_SUGGESTED_NAME->value,
            SystemFilePurpose::AI_GENERATED_NAME->value => 'name',
            SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value,
            SystemFilePurpose::AI_GENERATED_DESCRIPTION->value => 'description',
            SystemFilePurpose::AI_SUGGESTED_TAGS->value,
            SystemFilePurpose::AI_GENERATED_TAGS->value => 'tags',
            SystemFilePurpose::AI_SUGGESTED_METADATA->value => 'metadata',
            default => null,
        };
    }

    private function payloadValueExcerpt(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 200);
        }
        if (is_array($value)) {
            return array_slice($value, 0, 20);
        }

        return $value;
    }

    /**
     * Persist an AITY-generated value (no source file) as a resource-scoped SystemFile.
     * Supersedes any prior active row for the same purpose so the "latest" relationship
     * returns this one, and the JSON payload lands in the resource's archives folder.
     */
    private function persistResourceGenerated(string $resourceId, SystemFilePurpose $purpose, mixed $value): void
    {
        $suffix = match ($purpose) {
            SystemFilePurpose::AI_GENERATED_NAME => 'name',
            SystemFilePurpose::AI_GENERATED_DESCRIPTION => 'description',
            SystemFilePurpose::AI_GENERATED_TAGS => 'tags',
            default => 'value',
        };

        // Pre-generate the SystemFile id (UUID v7) and use it as the archive filename
        // signature. This guarantees the on-disk path is unique even if two AITY processes
        // write for the same resource+purpose in the same millisecond, and keeps the file
        // 1:1 traceable to its DB row.
        $systemFileId = (string) Str::orderedUuid();

        $stored = FileStorageService::storeResourceAiSuggestion($resourceId, $suffix, $value, $systemFileId);

        // Supersede prior active rows and create the new one atomically so a failure
        // between the two never leaves the resource with no active generated value.
        DB::transaction(function () use ($resourceId, $purpose, $stored, $value, $systemFileId): void {
            SystemFile::where('resource_id', $resourceId)
                ->whereNull('source_file_id')
                ->where('purpose', $purpose->value)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            SystemFile::create([
                'id' => $systemFileId,
                'resource_id' => $resourceId,
                'source_file_id' => null,
                'purpose' => $purpose->value,
                'filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'size' => $stored['size'],
                'path' => $stored['path'],
                'disk' => $stored['disk'],
                'metadata' => ['value' => $value],
                'is_active' => true,
            ]);
        });
    }

    private function log(string $line): void
    {
        $this->log[] = $line;
        if ($this->logCallback) {
            ($this->logCallback)($line);
        }
    }

    private function logSummary(int $processed, int $names, int $descs, int $tagsApplied, int $tagsSkipped, bool $dryRun): void
    {
        $this->log(self::HR);
        $this->log('Results'.($dryRun ? ' [DRY RUN — nothing written]' : '').':');
        $this->log("  Resources processed:  {$processed}");
        $this->log("  Names applied:        {$names}");
        $this->log("  Descriptions applied: {$descs}");
        $this->log("  Tags applied:         {$tagsApplied}");
        $this->log("  Tags skipped:         {$tagsSkipped}");
    }

    private function buildResult(int $processed, int $names, int $descs, int $tagsApplied, int $tagsSkipped, bool $dryRun): array
    {
        return [
            'resources_processed' => $processed,
            'names_applied' => $names,
            'descriptions_applied' => $descs,
            'tags_applied' => $tagsApplied,
            'tags_skipped' => $tagsSkipped,
            'dry_run' => $dryRun,
            'log' => $this->log,
        ];
    }

    private function collectSuggestions(Resource $resource): array
    {
        $allFileIds = $resource->files()->pluck('files.id');

        $canonicalFileId = $resource->files()
            ->where('role', FileRole::CANONICAL->value)
            ->where('is_active', true)
            ->value('id');

        $latestFrom = fn (array $ids, string $purpose) => SystemFile::whereIn('source_file_id', $ids)
            ->where('purpose', $purpose)
            ->where('is_active', true)
            ->latest()
            ->value('metadata');

        // Default provenance: no source assigned yet.
        $nameOrigin = $descOrigin = $tagsOrigin = null;
        $nameSourceFileId = $descSourceFileId = $tagsSourceFileId = null;

        // ── Scheme-field metadata (ai_fill contract) ─────────────────────────
        // Same contributor rules as everything else: canonical wins outright;
        // otherwise all files contribute, first-non-null per key (Decision A).
        $metadataSourceIds = $canonicalFileId ? [$canonicalFileId] : $allFileIds->all();
        $metadata = [];
        foreach ($metadataSourceIds as $metaFileId) {
            $mm = $latestFrom([$metaFileId], SystemFilePurpose::AI_SUGGESTED_METADATA->value);
            $values = is_array($mm['value'] ?? null) ? $mm['value'] : [];
            foreach ($values as $key => $value) {
                if ($value !== null && $value !== '' && ! array_key_exists($key, $metadata)) {
                    $metadata[$key] = $value;
                }
            }
        }

        // ── Name & description ────────────────────────────────────────────────
        if ($canonicalFileId) {
            // Canonical resource: authoritative file wins outright.
            $nm = $latestFrom([$canonicalFileId], SystemFilePurpose::AI_SUGGESTED_NAME->value);
            $dm = $latestFrom([$canonicalFileId], SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value);
            $name = is_string($nm['value'] ?? null) ? $nm['value'] : null;
            $description = is_string($dm['value'] ?? null) ? $dm['value'] : null;
            if ($name !== null) {
                $nameOrigin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $nameSourceFileId = $canonicalFileId;
            }
            if ($description !== null) {
                $descOrigin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $descSourceFileId = $canonicalFileId;
            }
        } elseif ($allFileIds->count() > 1) {
            // Multi-component: one LLM synthesis call encompasses name, description, and tags together.
            $candidates = [];
            foreach ($allFileIds as $fileId) {
                $nm = $latestFrom([$fileId], SystemFilePurpose::AI_SUGGESTED_NAME->value);
                $dm = $latestFrom([$fileId], SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value);
                $tm = $latestFrom([$fileId], SystemFilePurpose::AI_SUGGESTED_TAGS->value);
                $n = is_string($nm['value'] ?? null) ? $nm['value'] : null;
                $d = is_string($dm['value'] ?? null) ? $dm['value'] : null;
                $t = is_array($tm['value'] ?? null)
                    ? array_values(array_filter($tm['value'], fn ($tag) => is_array($tag) && ! empty($tag['label'])))
                    : [];
                if ($n !== null || $d !== null || ! empty($t)) {
                    $candidates[] = ['file_id' => $fileId, 'name' => $n, 'description' => $d, 'tags' => $t];
                }
            }
            $synth = $this->synthesizeMultiComponent($candidates);

            // Archive any LLM-invented fields so the user can recover them after editing or
            // accepting a per-file suggestion. Only the truly-generated cases get archived;
            // first-candidate-fallback values are already preserved via per-file SystemFiles.
            if ($synth['name'] !== null && $synth['name_origin'] === Resource::FIELD_ORIGIN_AITY_GENERATED) {
                $this->persistResourceGenerated($resource->id, SystemFilePurpose::AI_GENERATED_NAME, $synth['name']);
            }
            if ($synth['description'] !== null && $synth['description_origin'] === Resource::FIELD_ORIGIN_AITY_GENERATED) {
                $this->persistResourceGenerated($resource->id, SystemFilePurpose::AI_GENERATED_DESCRIPTION, $synth['description']);
            }
            if (! empty($synth['tags']) && $synth['tags_origin'] === Resource::FIELD_ORIGIN_AITY_GENERATED) {
                $this->persistResourceGenerated($resource->id, SystemFilePurpose::AI_GENERATED_TAGS, $synth['tags']);
            }

            return [
                'metadata' => $metadata,
                'name' => $synth['name'],
                'description' => $synth['description'],
                'tags' => $synth['tags'],
                'name_origin' => $synth['name_origin'],
                'name_source_file_id' => $synth['name_source_file_id'],
                'description_origin' => $synth['description_origin'],
                'description_source_file_id' => $synth['description_source_file_id'],
                'tags_origin' => $synth['tags_origin'],
                'tags_source_file_id' => $synth['tags_source_file_id'],
            ];
        } else {
            // Single file with no role marker — use it directly.
            $singleFileId = $allFileIds->first();
            $nm = $latestFrom($allFileIds->all(), SystemFilePurpose::AI_SUGGESTED_NAME->value);
            $dm = $latestFrom($allFileIds->all(), SystemFilePurpose::AI_SUGGESTED_DESCRIPTION->value);
            $name = is_string($nm['value'] ?? null) ? $nm['value'] : null;
            $description = is_string($dm['value'] ?? null) ? $dm['value'] : null;
            if ($name !== null) {
                $nameOrigin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $nameSourceFileId = $singleFileId;
            }
            if ($description !== null) {
                $descOrigin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
                $descSourceFileId = $singleFileId;
            }
        }

        // ── Tags ─────────────────────────────────────────────────────────────
        // Multi-component resources return early above with synthesized tags.
        // Canonical and single-file resources always source tags from the primary
        // file only — cross-file merging is only meaningful for multi-component.
        $tagSourceId = $canonicalFileId ?: $allFileIds->first();
        $tagsMeta = $latestFrom([$tagSourceId], SystemFilePurpose::AI_SUGGESTED_TAGS->value);
        $tags = $tagsMeta['value'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_filter($tags, fn ($t) => is_array($t) && ! empty($t['label'])));
        if (! empty($tags)) {
            $tagsOrigin = Resource::FIELD_ORIGIN_AITY_SUGGESTION;
            $tagsSourceFileId = $tagSourceId;
        }

        return [
            'metadata' => $metadata,
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
            'name_origin' => $nameOrigin,
            'name_source_file_id' => $nameSourceFileId,
            'description_origin' => $descOrigin,
            'description_source_file_id' => $descSourceFileId,
            'tags_origin' => $tagsOrigin,
            'tags_source_file_id' => $tagsSourceFileId,
        ];
    }

    /**
     * Ask the LLM to synthesize a unified name, description, and tag set that encompasses
     * the content of all component files. The LLM may generate new text — it is not restricted
     * to verbatim candidates. Falls back to the first non-null candidate per field on failure.
     *
     * Each candidate carries the originating file_id so the per-field provenance can record
     * whether the resulting value was generated by the LLM (aity_generated, no source file)
     * or copied verbatim from a specific candidate (aity_suggestion, source_file_id set).
     *
     * @param  array<array{file_id: string, name: string|null, description: string|null, tags: array}>  $candidates
     * @return array{
     *     name: string|null, description: string|null, tags: array,
     *     name_origin: string|null, name_source_file_id: string|null,
     *     description_origin: string|null, description_source_file_id: string|null,
     *     tags_origin: string|null, tags_source_file_id: string|null
     * }
     */
    private function synthesizeMultiComponent(array $candidates): array
    {
        $firstNonNullFor = function (array $candidates, string $field): array {
            foreach ($candidates as $c) {
                if (! empty($c[$field])) {
                    return ['value' => $c[$field], 'file_id' => $c['file_id'] ?? null];
                }
            }

            return ['value' => null, 'file_id' => null];
        };

        $mergeFallbackTags = function (array $candidates): array {
            $merged = [];
            foreach ($candidates as $c) {
                foreach ($c['tags'] ?? [] as $tag) {
                    $key = strtolower($tag['label'] ?? '').'|'.($tag['type'] ?? 'tag');
                    if (! isset($merged[$key])) {
                        $merged[$key] = $tag;
                    }
                }
            }

            return array_values($merged);
        };

        $empty = [
            'name' => null, 'description' => null, 'tags' => [],
            'name_origin' => null, 'name_source_file_id' => null,
            'description_origin' => null, 'description_source_file_id' => null,
            'tags_origin' => null, 'tags_source_file_id' => null,
        ];

        if (empty($candidates)) {
            return $empty;
        }

        if (count($candidates) === 1) {
            $c = $candidates[0];

            return [
                'name' => $c['name'] ?? null,
                'description' => $c['description'] ?? null,
                'tags' => $c['tags'] ?? [],
                'name_origin' => ! empty($c['name']) ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'name_source_file_id' => ! empty($c['name']) ? ($c['file_id'] ?? null) : null,
                'description_origin' => ! empty($c['description']) ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'description_source_file_id' => ! empty($c['description']) ? ($c['file_id'] ?? null) : null,
                'tags_origin' => ! empty($c['tags']) ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'tags_source_file_id' => ! empty($c['tags']) ? ($c['file_id'] ?? null) : null,
            ];
        }

        // Log per-file inputs so the user can see what fed into the synthesis.
        $this->log('  Multi-component: synthesizing from '.count($candidates).' file suggestion(s)...');
        foreach ($candidates as $i => $c) {
            $label = $c['name'] ? '"'.mb_substr($c['name'], 0, 50).'"' : '(no name)';
            $this->log(sprintf('    [%d] %-54s  %d tag(s)', $i + 1, $label, count($c['tags'] ?? [])));
        }

        $fallbackName = $firstNonNullFor($candidates, 'name');
        $fallbackDesc = $firstNonNullFor($candidates, 'description');
        $fallbackTags = $mergeFallbackTags($candidates);

        try {
            $raw = $this->llm->chat([
                ['role' => 'system', 'content' => self::SYNTHESIZE_MULTI_SYSTEM],
                ['role' => 'user',   'content' => json_encode($candidates, JSON_UNESCAPED_UNICODE)],
            ]);
            $result = $this->parseLlmJson($raw);

            if (! is_array($result)) {
                throw new \RuntimeException('non-array response');
            }

            $name = is_string($result['name'] ?? null) ? trim($result['name']) : null;
            $description = is_string($result['description'] ?? null) ? trim($result['description']) : null;
            $rawTags = is_array($result['tags'] ?? null) ? $result['tags'] : [];

            // Normalise and validate each synthesized tag.
            $tags = [];
            foreach ($rawTags as $t) {
                if (! is_array($t) || empty($t['label'])) {
                    continue;
                }
                $tags[] = [
                    'label' => (string) $t['label'],
                    'type' => in_array($t['type'] ?? '', self::VALID_ENTITY_TYPES) ? $t['type'] : 'tag',
                    'confidence' => min(1.0, max(0.0, (float) ($t['confidence'] ?? 0.8))),
                    'description' => is_string($t['description'] ?? null) ? $t['description'] : null,
                ];
            }

            $finalName = $name ?: $fallbackName['value'];
            $finalDesc = $description ?: $fallbackDesc['value'];
            $finalTags = $tags ?: $fallbackTags;

            return [
                'name' => $finalName,
                'description' => $finalDesc,
                'tags' => $finalTags,
                'name_origin' => $finalName === null ? null
                    : ($name ? Resource::FIELD_ORIGIN_AITY_GENERATED : Resource::FIELD_ORIGIN_AITY_SUGGESTION),
                'name_source_file_id' => $finalName !== null && ! $name ? $fallbackName['file_id'] : null,
                'description_origin' => $finalDesc === null ? null
                    : ($description ? Resource::FIELD_ORIGIN_AITY_GENERATED : Resource::FIELD_ORIGIN_AITY_SUGGESTION),
                'description_source_file_id' => $finalDesc !== null && ! $description ? $fallbackDesc['file_id'] : null,
                'tags_origin' => empty($finalTags) ? null
                    : ($tags ? Resource::FIELD_ORIGIN_AITY_GENERATED : Resource::FIELD_ORIGIN_AITY_SUGGESTION),
                'tags_source_file_id' => null,
            ];
        } catch (\Throwable) {
            $this->log('  (multi-component: synthesis failed — using first candidate with merged tags)');

            return [
                'name' => $fallbackName['value'],
                'description' => $fallbackDesc['value'],
                'tags' => $fallbackTags,
                'name_origin' => $fallbackName['value'] !== null ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'name_source_file_id' => $fallbackName['file_id'],
                'description_origin' => $fallbackDesc['value'] !== null ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'description_source_file_id' => $fallbackDesc['file_id'],
                'tags_origin' => ! empty($fallbackTags) ? Resource::FIELD_ORIGIN_AITY_SUGGESTION : null,
                'tags_source_file_id' => null, // merged across files — no single source
            ];
        }
    }

    /**
     * Collect and merge tags from every file in the resource.
     * Canonical file tags keep their original confidence; non-canonical file tags are discounted
     * so the downstream threshold and frequency logic naturally favours the primary file.
     * When the same tag (label + type) appears in multiple files the highest confidence wins.
     */
    private function mergeTagsFromAllFiles(array $allFileIds, mixed $canonicalFileId, float $discount = 0.8): array
    {
        $merged = []; // "label_lower|type" => tag array

        foreach ($allFileIds as $fileId) {
            $meta = SystemFile::where('source_file_id', $fileId)
                ->where('purpose', SystemFilePurpose::AI_SUGGESTED_TAGS->value)
                ->where('is_active', true)
                ->latest()
                ->value('metadata');

            $fileTags = $meta['value'] ?? [];
            if (! is_array($fileTags)) {
                continue;
            }

            // When there is no canonical file all components are equal — no discount applied.
            $isCanonical = $canonicalFileId === null || $fileId == $canonicalFileId;

            foreach ($fileTags as $tag) {
                if (! is_array($tag) || empty($tag['label'])) {
                    continue;
                }

                $conf = (float) ($tag['confidence'] ?? 1.0);
                if (! $isCanonical) {
                    $conf *= $discount;
                }

                $key = strtolower($tag['label']).'|'.($tag['type'] ?? 'tag');

                if (! isset($merged[$key]) || $conf > (float) ($merged[$key]['confidence'] ?? 0)) {
                    $merged[$key] = array_merge($tag, ['confidence' => round($conf, 4)]);
                }
            }
        }

        return array_values($merged);
    }

    /**
     * Run LLM-based deduplication: type reclassification + label merging.
     * Returns updated [$freqMap, $normalizeMap, $allSuggestions].
     */
    private function runDedup(array $freqMap, array $tagResourceNames, array $allSuggestions): array
    {
        $tagList = [];
        foreach ($freqMap as $key => $info) {
            $avg = $info['count'] > 0 ? round($info['total_confidence'] / $info['count'], 2) : 0.0;
            $tagList[] = [
                'label' => $info['label'],
                'type' => $info['type'],
                'freq' => $info['count'],
                'avg_conf' => $avg,
                'in' => array_slice($tagResourceNames[$key] ?? [], 0, 5),
            ];
        }
        usort($tagList, fn ($a, $b) => strcmp(strtolower($a['label']), strtolower($b['label'])));

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => self::DEDUP_SYSTEM],
            ['role' => 'user',   'content' => json_encode($tagList, JSON_UNESCAPED_UNICODE)],
        ]);
        $result = $this->parseLlmJson($raw);

        if (! is_array($result)) {
            $this->log('  Dedup: LLM returned unparseable response — skipping normalisation.');

            return [$freqMap, [], $allSuggestions];
        }

        $reclassifyList = $result['reclassify'] ?? [];
        $mergeGroups = $result['merges'] ?? [];

        // Reclassify entity types
        $reclassifyMap = [];
        foreach ($reclassifyList as $r) {
            if (! is_array($r)) {
                continue;
            }
            $lbl = is_string($r['label'] ?? null) ? trim($r['label']) : '';
            $newType = is_string($r['type'] ?? null) ? trim($r['type']) : '';
            if ($lbl && in_array($newType, self::VALID_ENTITY_TYPES)) {
                $reclassifyMap[strtolower($lbl)] = $newType;
            }
        }

        if (! empty($reclassifyMap)) {
            $this->log('  Reclassified '.count($reclassifyMap).' tag type(s):');
            foreach ($reclassifyMap as $lbl => $newType) {
                $this->log("    \"{$lbl}\"  tag → {$newType}");
            }

            foreach ($allSuggestions as &$sug) {
                foreach ($sug['tags'] as &$tag) {
                    $lk = strtolower(trim($tag['label'] ?? ''));
                    if (isset($reclassifyMap[$lk]) && ($tag['type'] ?? '') === 'tag') {
                        $tag['type'] = $reclassifyMap[$lk];
                    }
                }
                unset($tag);
            }
            unset($sug);

            // Rebuild freqMap with corrected types — count unique resources, not tag occurrences.
            $rebuilt = [];
            foreach ($allSuggestions as $sug) {
                $resKeys = [];
                foreach ($sug['tags'] as $tag) {
                    $lbl = trim($tag['label'] ?? '');
                    $et = $tag['type'] ?? 'tag';
                    $k = "{$lbl}|{$et}";
                    $conf = (float) ($tag['confidence'] ?? 0.0);
                    if (! isset($resKeys[$k]) || $conf > $resKeys[$k]['conf']) {
                        $resKeys[$k] = ['conf' => $conf, 'label' => $lbl, 'type' => $et];
                    }
                }
                foreach ($resKeys as $k => $info) {
                    if (! isset($rebuilt[$k])) {
                        $rebuilt[$k] = ['count' => 0, 'total_confidence' => 0.0, 'label' => $info['label'], 'type' => $info['type']];
                    }
                    $rebuilt[$k]['count']++;
                    $rebuilt[$k]['total_confidence'] += $info['conf'];
                }
            }
            $freqMap = $rebuilt;
        } else {
            $this->log('  No type reclassifications.');
        }

        // Merge duplicate labels
        $normalizeMap = [];
        foreach ($mergeGroups as $g) {
            if (! is_array($g)) {
                continue;
            }
            $canonical = is_string($g['canonical'] ?? null) ? trim($g['canonical']) : '';
            $entityType = is_string($g['type'] ?? null) ? trim($g['type']) : 'tag';
            $rawDups = is_array($g['duplicates'] ?? null) ? $g['duplicates'] : [];
            $dups = array_filter(array_map(fn ($d) => is_string($d) ? trim($d) : '', $rawDups), fn ($d) => $d !== '');
            if (! $canonical || empty($dups)) {
                continue;
            }
            foreach ($dups as $dup) {
                $k = "{$dup}|{$entityType}";
                if (isset($freqMap[$k])) {
                    $normalizeMap[$k] = $canonical;
                }
            }
        }

        if (! empty($normalizeMap)) {
            $this->log('  Proposed '.count($mergeGroups).' merge group(s):');
            foreach ($mergeGroups as $g) {
                if (! is_array($g)) {
                    continue;
                }
                $canonical = is_string($g['canonical'] ?? null) ? trim($g['canonical']) : '';
                $rawDups = is_array($g['duplicates'] ?? null) ? $g['duplicates'] : [];
                $dups = array_filter(array_map(fn ($d) => is_string($d) ? trim($d) : '', $rawDups), fn ($d) => $d !== '');
                if (! $canonical || empty($dups)) {
                    continue;
                }
                $dupList = implode('", "', $dups);
                $this->log("    KEEP \"{$canonical}\"  ←  [\"{$dupList}\"]");
                if (! empty($g['reason'])) {
                    $this->log("         ({$g['reason']})");
                }
            }

            // Merge duplicate labels — count unique resources, not tag occurrences.
            $merged = [];
            foreach ($allSuggestions as $sug) {
                $resKeys = [];
                foreach ($sug['tags'] as $tag) {
                    $label = trim($tag['label'] ?? '');
                    $entityType = $tag['type'] ?? 'tag';
                    $key = "{$label}|{$entityType}";
                    $canonical = $normalizeMap[$key] ?? $label;
                    $mkey = "{$canonical}|{$entityType}";
                    $conf = (float) ($tag['confidence'] ?? 0.0);
                    if (! isset($resKeys[$mkey]) || $conf > $resKeys[$mkey]['conf']) {
                        $resKeys[$mkey] = ['conf' => $conf, 'label' => $canonical, 'type' => $entityType];
                    }
                }
                foreach ($resKeys as $mkey => $info) {
                    if (! isset($merged[$mkey])) {
                        $merged[$mkey] = ['count' => 0, 'total_confidence' => 0.0, 'label' => $info['label'], 'type' => $info['type']];
                    }
                    $merged[$mkey]['count']++;
                    $merged[$mkey]['total_confidence'] += $info['conf'];
                }
            }
            $freqMap = $merged;
        } else {
            $this->log('  No duplicate groups found.');
        }

        return [$freqMap, $normalizeMap, $allSuggestions];
    }

    private function makeDecisions(
        array $tags,
        array $freqMap,
        array $normalizeMap,
        float $confidence,
        float $midConf,
        int $minFreq
    ): array {
        $accept = [];
        $skip = [];

        foreach ($tags as $tag) {
            $label = trim($tag['label'] ?? '');
            $entityType = $tag['type'] ?? 'tag';
            $conf = (float) ($tag['confidence'] ?? 0.0);
            $key = "{$label}|{$entityType}";
            $canonical = $normalizeMap[$key] ?? $label;
            $ckey = "{$canonical}|{$entityType}";
            $freq = $freqMap[$ckey]['count'] ?? ($freqMap[$key]['count'] ?? 1);

            if ($conf >= $confidence) {
                $accept[] = array_merge($tag, ['_canonical' => $canonical, '_reason' => 'high confidence']);
            } elseif ($conf >= $midConf && $freq >= $minFreq) {
                $accept[] = array_merge($tag, ['_canonical' => $canonical, '_reason' => "freq boost x{$freq}"]);
            } else {
                $skip[] = array_merge($tag, ['_canonical' => $canonical, '_reason' => 'conf='.(int) round($conf * 100)."% freq={$freq}"]);
            }
        }

        return [$accept, $skip];
    }

    private function pruneWithLlm(array $decision, string $name, string $description, int $maxTags): array
    {
        $payload = [
            'resource' => ['name' => $name, 'description' => $description],
            'max_tags' => $maxTags,
            'candidates' => array_map(fn ($t) => [
                'label' => $t['_canonical'],
                'type' => $t['type'] ?? 'tag',
                'confidence' => round((float) ($t['confidence'] ?? 0.0), 2),
                'reason' => $t['_reason'],
            ], $decision['accept']),
        ];

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => self::TOP_TAGS_SYSTEM],
            ['role' => 'user',   'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ]);
        $selected = $this->parseLlmJson($raw);

        if (! is_array($selected) || empty($selected)) {
            return $decision;
        }

        $selectedSet = array_flip(array_map('strtolower', array_filter($selected, 'is_string')));
        $kept = array_values(array_filter($decision['accept'], fn ($t) => isset($selectedSet[strtolower($t['_canonical'])])));
        $pruned = array_values(array_filter($decision['accept'], fn ($t) => ! isset($selectedSet[strtolower($t['_canonical'])])));

        if (empty($kept)) {
            return $decision;
        }

        $prunedWithReason = array_map(
            fn ($t) => array_merge($t, ['_reason' => "pruned by LLM (top-{$maxTags})"]),
            $pruned
        );

        return ['accept' => $kept, 'skip' => array_merge($decision['skip'], $prunedWithReason)];
    }

    /**
     * Sync accepted tags onto the resource and return how many new IDs were added.
     */
    private function applyTags(Resource $resource, array $tags, string $orgId): int
    {
        $newTagIds = [];
        foreach ($tags as $tag) {
            $canonical = $tag['_canonical'];
            $entityType = $tag['type'] ?? 'tag';
            if (! in_array($entityType, self::VALID_ENTITY_TYPES)) {
                $entityType = 'tag';
            }
            $semanticTag = $this->findOrCreateTag($canonical, $entityType, $tag['description'] ?? null, $orgId);
            $newTagIds[] = $semanticTag->id;
        }

        if (empty($newTagIds)) {
            return 0;
        }

        $existingIds = $resource->semanticTags()->pluck('semantic_tags.id')->all();
        $mergedIds = array_unique(array_merge($existingIds, $newTagIds));
        $resource->semanticTags()->sync($mergedIds);

        IndexResourceToElasticsearch::dispatchSync($resource->id);
        UpsertResourceMetadataChunk::dispatch($resource->id);

        return count($mergedIds) - count($existingIds);
    }

    private function findOrCreateTag(string $label, string $entityType, ?string $description, string $orgId): SemanticTag
    {
        $existing = SemanticTag::where('organization_id', $orgId)
            ->where('label', $label)
            ->where('entity_type', $entityType)
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing;
        }

        $slug = Str::slug($label);
        $base = $slug;
        $i = 1;
        while (SemanticTag::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return SemanticTag::create([
            'organization_id' => $orgId,
            'label' => $label,
            'slug' => $slug,
            'description' => $description,
            'entity_type' => $entityType,
            'vocabulary' => TagVocabulary::AI_GENERATED->value,
            'reviewer' => TagReviewer::AITY->value,
            'is_active' => true,
        ]);
    }

    private function parseLlmJson(string $text): mixed
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $lines = explode("\n", $text);
            array_shift($lines);
            if (! empty($lines) && trim(end($lines)) === '```') {
                array_pop($lines);
            }
            $text = trim(implode("\n", $lines));
        }

        return json_decode($text, true);
    }
}
