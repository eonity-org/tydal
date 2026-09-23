#!/usr/bin/env php
<?php

/*
 * TYDAL MCP Server
 *
 * Exposes TYDAL workspace intelligence to external AI agents via the
 * Model Context Protocol (MCP) over stdio.
 *
 * Protocol: JSON-RPC 2.0 with Content-Length framing (same as LSP).
 *
 * Usage:
 *   TYDAL_MCP_TOKEN=<sanctum-api-token> php mcp/tydal-mcp.php
 *
 * Tools:
 *   search_workspace  — keyword search over resources in a workspace
 *   ask_workspace     — RAG question-answering over indexed workspace documents
 *
 * Authentication:
 *   Set TYDAL_MCP_TOKEN to a Sanctum personal access token.
 *   The token owner's permissions govern which workspaces are accessible.
 *   Without a token the server runs unauthenticated (all DB queries proceed
 *   but policy guards may block access to protected workspaces).
 */

declare(strict_types=1);

// ── Bootstrap Laravel ─────────────────────────────────────────────────────────

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\McpToolService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

// ── Authenticate ──────────────────────────────────────────────────────────────

$mcpToken = getenv('TYDAL_MCP_TOKEN') ?: null;

if ($mcpToken !== null) {
    $pat = PersonalAccessToken::findToken($mcpToken);
    if ($pat && $pat->tokenable) {
        Auth::login($pat->tokenable);
    }
}

// ── Tool catalogue ────────────────────────────────────────────────────────────

$MCP_TOOLS = [
    [
        'name' => 'search_workspace',
        'description' => 'Search for resources (digital assets) inside a TYDAL workspace. '
            .'Returns resource metadata: id, name, description, type, and last-updated timestamp. '
            .'Use this to discover what assets exist before asking detailed questions.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Numeric TYDAL workspace ID (e.g. "3").',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional keyword filter applied to resource name and description. Omit to list all resources.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (1–50, default 10).',
                ],
            ],
            'required' => ['workspace_id'],
        ],
    ],
    [
        'name' => 'ask_workspace',
        'description' => 'Answer a natural-language question using retrieval-augmented generation (RAG) '
            .'over the vector-indexed documents in a TYDAL workspace. '
            .'Returns a synthesised answer plus the source passages used. '
            .'Requires that the workspace resources have been vector-indexed.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Numeric TYDAL workspace ID (e.g. "3").',
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'The question to answer using the workspace knowledge base.',
                ],
                'k' => [
                    'type' => 'integer',
                    'description' => 'Number of document chunks to retrieve for context (1–20, default 5). Higher values give richer context at the cost of slower responses.',
                ],
                'strict' => [
                    'type' => 'boolean',
                    'description' => 'When true, AI-generated metadata (name suggestions, descriptions, tags) is excluded from context — only raw extracted document text is used. Default false.',
                ],
            ],
            'required' => ['workspace_id', 'question'],
        ],
    ],
];

// ── Tool dispatch (delegates to McpToolService) ───────────────────────────────

function tydal_call_tool(string $name, array $args): array
{
    /** @var McpToolService $svc */
    $svc = app(McpToolService::class);

    return match ($name) {
        'search_workspace' => $svc->searchWorkspace(
            workspaceId: (int) ($args['workspace_id'] ?? 0),
            query: trim($args['query'] ?? ''),
            limit: (int) ($args['limit'] ?? 10),
        ),
        'ask_workspace' => $svc->askWorkspace(
            workspaceId: (int) ($args['workspace_id'] ?? 0),
            question: trim($args['question'] ?? ''),
            k: (int) ($args['k'] ?? 5),
            strict: (bool) ($args['strict'] ?? false),
        ),
        default => ['error' => "Unknown tool: {$name}"],
    };
}

// ── MCP protocol helpers ──────────────────────────────────────────────────────

function mcp_write(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $len = strlen($json);
    fwrite(STDOUT, "Content-Length: {$len}\r\n\r\n{$json}");
    fflush(STDOUT);
}

function mcp_error_response(mixed $id, int $code, string $message): void
{
    mcp_write([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

function mcp_read_message(): ?array
{
    $headers = [];

    // Read HTTP-style headers until blank line
    while (($line = fgets(STDIN)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        if ($trimmed === '') {
            break;
        }
        if (preg_match('/^([^:]+):\s*(.+)$/', $trimmed, $m)) {
            $headers[strtolower(trim($m[1]))] = trim($m[2]);
        }
    }

    if (feof(STDIN) || ! isset($headers['content-length'])) {
        return null;
    }

    $length = (int) $headers['content-length'];
    $body = '';

    while (strlen($body) < $length) {
        $chunk = fread(STDIN, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    if (strlen($body) < $length) {
        return null;
    }

    return json_decode($body, true);
}

// ── Main server loop ──────────────────────────────────────────────────────────

while (true) {
    $msg = mcp_read_message();

    if ($msg === null) {
        break; // stdin closed — client disconnected
    }

    $id = $msg['id'] ?? null;
    $method = $msg['method'] ?? '';
    $params = $msg['params'] ?? [];

    // Notifications have no id and require no response
    if ($id === null && str_starts_with($method, 'notifications/')) {
        continue;
    }

    if ($method === 'initialize') {
        mcp_write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => [
                    'name' => 'tydal-mcp',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        continue;
    }

    if ($method === 'tools/list') {
        mcp_write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $MCP_TOOLS],
        ]);

        continue;
    }

    if ($method === 'tools/call') {
        $toolName = $params['name'] ?? '';
        $toolArgs = $params['arguments'] ?? [];

        $result = tydal_call_tool($toolName, $toolArgs);

        if (isset($result['error']) && $result['error'] === "Unknown tool: {$toolName}") {
            mcp_error_response($id, -32602, $result['error']);

            continue;
        }

        $isError = isset($result['error']);

        mcp_write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
                'isError' => $isError,
            ],
        ]);

        continue;
    }

    // Method not found — only respond if this is a request (has id)
    if ($id !== null) {
        mcp_error_response($id, -32601, "Method not found: {$method}");
    }
}
