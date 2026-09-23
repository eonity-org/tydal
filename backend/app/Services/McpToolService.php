<?php

namespace App\Services;

use App\Enums\ResourceState;
use App\Models\Resource;
use App\Models\Workspace;

/**
 * McpToolService — implements the two MCP tool handlers as a proper Laravel
 * service so they are testable, container-resolvable, and decoupled from the
 * stdio protocol layer in mcp/tydal-mcp.php.
 */
class McpToolService
{
    public function __construct(
        private readonly RagService $ragService,
    ) {}

    /**
     * Search for resources inside a workspace.
     *
     * @return array{workspace: string, query: string|null, total: int, resources: array}
     *                                                                                    | array{error: string}
     */
    public function searchWorkspace(int $workspaceId, string $query = '', int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return ['error' => "Workspace {$workspaceId} not found."];
        }

        // Default workspaces span all active org resources; named workspaces use the pivot.
        if ($workspace->is_default) {
            $q = Resource::where('organization_id', $workspace->organization_id)
                ->where('state', ResourceState::LIVE->value);
        } else {
            $q = $workspace->resources()->where('resources.state', ResourceState::LIVE->value);
        }

        if ($query !== '') {
            $q->where(function ($sub) use ($query) {
                $sub->where('resources.name', 'like', "%{$query}%")
                    ->orWhere('resources.description', 'like', "%{$query}%");
            });
        }

        $resources = $q
            ->select(['resources.id', 'resources.name', 'resources.description', 'resources.type', 'resources.updated_at'])
            ->orderByDesc('resources.updated_at')
            ->limit($limit)
            ->get();

        return [
            'workspace' => $workspace->name ?? "Workspace {$workspaceId}",
            'query' => $query !== '' ? $query : null,
            'total' => $resources->count(),
            'resources' => $resources->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'type' => $r->type,
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Answer a question using workspace-scoped RAG.
     *
     * @return array{answer: string, sources: array}
     *                                               | array{error: string}
     */
    public function askWorkspace(int $workspaceId, string $question, int $k = 5, bool $strict = false): array
    {
        $k = max(1, min(20, $k));

        if ($question === '') {
            return ['error' => '`question` is required and must not be empty.'];
        }

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            return ['error' => "Workspace {$workspaceId} not found."];
        }

        try {
            return $this->ragService->askForWorkspace($workspace, $question, $k, $strict);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => 'RAG service unavailable: '.$e->getMessage()];
        }
    }
}
