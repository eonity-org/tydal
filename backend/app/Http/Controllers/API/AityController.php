<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\AutoApproveWorkspaceJob;
use App\Models\Workspace;
use App\Services\AutoApprovalService;
use App\Services\LLM\LlmDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AityController extends Controller
{
    /**
     * Proxy a free-form chat request through the configured LLM driver.
     *
     * Intended for trusted tools (e.g. the bulk uploader's --phase tags --dedup)
     * that need LLM access without holding API keys themselves. The backend's
     * configured driver (Claude, Gemini, Zai, Ollama) is used transparently.
     *
     * POST /api/v1/aity/chat
     * Body: { "messages": [{ "role": "system|user|assistant", "content": "..." }] }
     * Response: { "success": true, "data": { "response": "..." } }
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', Rule::in(['system', 'user', 'assistant'])],
            'messages.*.content' => ['required', 'string'],
        ]);

        try {
            $response = LlmDriverFactory::chatDriver()->chat($validated['messages']);

            return response()->json(['success' => true, 'data' => ['response' => $response]]);
        } catch (\Throwable $e) {
            Log::error('AityController::chat failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'LLM service unavailable.'], 503);
        }
    }

    /**
     * Automatically approve AI suggestions for all resources in a workspace.
     *
     * Applies suggested names and descriptions immediately, then runs cross-resource
     * tag analysis (dedup + confidence/frequency thresholds) and applies accepted tags.
     * Suggestion SystemFiles are deactivated and workspace membership is updated.
     *
     * POST /api/v1/aity/auto-approve
     * Body: {
     *   workspace_id:    int   (required)
     *   confidence:      float (default 0.80) — individual tag confidence threshold
     *   mid_confidence:  float (default 0.65) — lower bound for frequency-boosted tags
     *   min_frequency:   int   (default 3)    — min resources a tag must appear in for freq boost
     *   dedup:           bool  (default true)  — run LLM type reclassification + label merging
     *   max_tags:        int   (default 4)     — max tags per resource; 0 = unlimited
     *   dry_run:         bool  (default false) — preview counts without writing
     *   apply_name:         bool (default true)
     *   apply_description:  bool (default true)
     * }
     */
    public function autoApprove(Request $request): JsonResponse
    {
        $orgId = currentOrganizationId();

        if (! $orgId) {
            return response()->json(['success' => false, 'message' => 'No organization context.'], 400);
        }

        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'mid_confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_frequency' => ['sometimes', 'integer', 'min:1'],
            'dedup' => ['sometimes', 'boolean'],
            'max_tags' => ['sometimes', 'integer', 'min:0'],
            'dry_run' => ['sometimes', 'boolean'],
            'apply_name' => ['sometimes', 'boolean'],
            'apply_description' => ['sometimes', 'boolean'],
            'apply_tags' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = app(AutoApprovalService::class)->approveWorkspace(
                (int) $validated['workspace_id'],
                $orgId,
                $validated,
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            Log::error('AityController::autoApprove failed: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Auto-approval failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Streaming version of autoApprove — emits SSE events as each log line is produced
     * so the frontend can display progress in real time.
     *
     * POST /api/v1/aity/auto-approve/stream
     * Same body as /auto-approve.
     *
     * SSE event format:
     *   data: {"type":"log","line":"..."}
     *   data: {"type":"complete","result":{...}}
     *   data: {"type":"error","message":"..."}
     */
    public function autoApproveStream(Request $request): StreamedResponse
    {
        $orgId = currentOrganizationId();

        if (! $orgId) {
            return new StreamedResponse(function () {
                $this->sseEmit(['type' => 'error', 'message' => 'No organization context.']);
            }, 400, $this->sseHeaders());
        }

        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'mid_confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_frequency' => ['sometimes', 'integer', 'min:1'],
            'dedup' => ['sometimes', 'boolean'],
            'max_tags' => ['sometimes', 'integer', 'min:0'],
            'dry_run' => ['sometimes', 'boolean'],
            'apply_name' => ['sometimes', 'boolean'],
            'apply_description' => ['sometimes', 'boolean'],
            'apply_tags' => ['sometimes', 'boolean'],
        ]);

        return new StreamedResponse(function () use ($validated, $orgId) {
            // Flush any existing output buffers so lines reach the browser immediately.
            while (ob_get_level()) {
                ob_end_flush();
            }

            /** @var AutoApprovalService $service */
            $service = app(AutoApprovalService::class);
            $service->setLogCallback(function (string $line) {
                $this->sseEmit(['type' => 'log', 'line' => $line]);
            });

            try {
                $result = $service->approveWorkspace(
                    (int) $validated['workspace_id'],
                    $orgId,
                    $validated,
                );
                $this->sseEmit(['type' => 'complete', 'result' => $result]);
            } catch (\InvalidArgumentException $e) {
                $this->sseEmit(['type' => 'error', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::error('AityController::autoApproveStream failed: '.$e->getMessage());
                $this->sseEmit(['type' => 'error', 'message' => 'Auto-approval failed: '.$e->getMessage()]);
            }
        }, 200, $this->sseHeaders());
    }

    /**
     * Dispatch a background job to wait for AiTy analysis then auto-approve a workspace.
     *
     * The job polls workspace resource aity_status values until all are terminal,
     * then runs the full cross-resource auto-approval pipeline (dedup included).
     * Returns immediately — the frontend can close without waiting.
     *
     * POST /api/v1/aity/auto-approve/dispatch
     * Body: same as /auto-approve (workspace_id required, all other options optional).
     * Response: { "success": true, "data": { "status": "pending" } }
     */
    public function dispatchAutoApprove(Request $request): JsonResponse
    {
        $orgId = currentOrganizationId();

        if (! $orgId) {
            return response()->json(['success' => false, 'message' => 'No organization context.'], 400);
        }

        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'mid_confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'min_frequency' => ['sometimes', 'integer', 'min:1'],
            'dedup' => ['sometimes', 'boolean'],
            'max_tags' => ['sometimes', 'integer', 'min:0'],
            'dry_run' => ['sometimes', 'boolean'],
            'apply_name' => ['sometimes', 'boolean'],
            'apply_description' => ['sometimes', 'boolean'],
            'apply_tags' => ['sometimes', 'boolean'],
            'poll_timeout' => ['sometimes', 'integer', 'min:30', 'max:3600'],
        ]);

        $workspace = Workspace::where('id', $validated['workspace_id'])
            ->where('organization_id', $orgId)
            ->first();

        if (! $workspace) {
            return response()->json(['success' => false, 'message' => 'Workspace not found.'], 404);
        }

        if ($workspace->auto_approve_status === 'running') {
            return response()->json(['success' => false, 'message' => 'Auto-approval is already running for this workspace.'], 409);
        }

        $workspace->update([
            'auto_approve_status' => 'pending',
            'auto_approve_log' => [
                'log' => ['Job queued — waiting for AiTy analysis to complete...'],
                'partial' => true,
                'resources_processed' => 0,
                'names_applied' => 0,
                'descriptions_applied' => 0,
                'tags_applied' => 0,
                'tags_skipped' => 0,
                'dry_run' => (bool) ($validated['dry_run'] ?? false),
            ],
        ]);

        $options = array_diff_key($validated, ['workspace_id' => null]);
        AutoApproveWorkspaceJob::dispatch((int) $validated['workspace_id'], $orgId, $options);

        return response()->json(['success' => true, 'data' => ['status' => 'pending']]);
    }

    private function sseEmit(array $data): void
    {
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
        flush();
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // disable nginx proxy buffering
            'Connection' => 'keep-alive',
        ];
    }
}
