/**
 * Integration smoke test — calls real tool handlers against a live TYDAL backend.
 *
 * Requires real environment variables (not the vitest dummies):
 *   TYDAL_TOKEN      Sanctum personal access token
 *   TYDAL_ORG_ID     Organization UUID
 *   TYDAL_BASE_URL   API base URL (default: http://localhost:8000/api/v1)
 *
 * Run:
 *   TYDAL_TOKEN="1|..." TYDAL_ORG_ID="uuid" npm run test:integration
 */

// Guard: reject the vitest dummy values before any module reads them.
if (!process.env.TYDAL_TOKEN || process.env.TYDAL_TOKEN === 'test-token') {
  console.error('\n  ✗  TYDAL_TOKEN is not set (or is using the vitest dummy "test-token").');
  console.error('     Export a real Sanctum token first, e.g.:');
  console.error('     TYDAL_TOKEN="1|..." TYDAL_ORG_ID="..." npm run test:integration\n');
  process.exit(1);
}
if (!process.env.TYDAL_ORG_ID || process.env.TYDAL_ORG_ID === 'test-org-id') {
  console.error('\n  ✗  TYDAL_ORG_ID is not set (or is using the vitest dummy "test-org-id").');
  process.exit(1);
}

// Handlers — imported after env guard so config.ts does not throw.
import { handler as listWorkspaces }         from './tools/list_workspaces.js';
import { handler as browseWorkspace }         from './tools/browse_workspace.js';
import { handler as searchWorkspace }         from './tools/search_workspace.js';
import { handler as getResource }             from './tools/get_resource.js';
import { handler as getVaultLinks }             from './tools/get_vault_links.js';
import { handler as askWorkspace }            from './tools/ask_workspace.js';

// ── helpers ─────────────────────────────────────────────────────────────────

let passed = 0;
let failed = 0;

function ok(label: string, data: unknown) {
  passed++;
  console.log(`  ✓  ${label}`);
  console.log('     ' + JSON.stringify(data, null, 2).replace(/\n/g, '\n     '));
  console.log();
}

function fail(label: string, reason: unknown) {
  failed++;
  console.error(`  ✗  ${label}`);
  console.error('     ' + String(reason));
  console.log();
}

function isError(result: unknown): result is { error: string } {
  return typeof result === 'object' && result !== null && 'error' in result;
}

// ── run ──────────────────────────────────────────────────────────────────────

console.log('\n=== TYDAL MCP — Integration smoke test ===');
console.log(`    Base URL : ${process.env.TYDAL_BASE_URL ?? 'http://localhost:8000/api/v1'}`);
console.log(`    Org ID   : ${process.env.TYDAL_ORG_ID}`);
console.log(`    Token    : ${process.env.TYDAL_TOKEN!.slice(0, 12)}...`);
console.log();

// ── 1. list_workspaces ───────────────────────────────────────────────────────
console.log('1. list_workspaces');
const wsResult = await listWorkspaces({}) as any;
if (isError(wsResult)) {
  fail('list_workspaces', wsResult.error);
  process.exit(1); // nothing else can run without a workspace
}
ok('list_workspaces', { total: wsResult.total, names: wsResult.workspaces.map((w: any) => w.name) });

const firstWs = wsResult.workspaces[0];
if (!firstWs) {
  console.warn('  ⚠  No workspaces found — skipping workspace-dependent tests.\n');
  process.exit(0);
}
const wsId = String(firstWs.id);
console.log(`    Using workspace: "${firstWs.name}" (id=${wsId})\n`);

// ── 2. browse_workspace ──────────────────────────────────────────────────────
console.log('2. browse_workspace');
const browseResult = await browseWorkspace({ workspace_id: wsId, limit: 5 }) as any;
if (isError(browseResult)) {
  fail('browse_workspace', browseResult.error);
} else {
  ok('browse_workspace', {
    total: browseResult.total,
    first_items: (browseResult.data ?? []).slice(0, 3).map((r: any) => ({ id: r.id, name: r.name })),
  });
}

// ── 3. search_workspace ──────────────────────────────────────────────────────
console.log('3. search_workspace');
const searchResult = await searchWorkspace({ workspace_id: wsId, query: '' }) as any;
if (isError(searchResult)) {
  fail('search_workspace', searchResult.error);
} else {
  ok('search_workspace', { total: searchResult.total });
}

// ── 4. get_resource (using first result from browse) ─────────────────────────
const firstResource = browseResult?.data?.[0];
if (firstResource?.id) {
  console.log('4. get_resource');
  const resResult = await getResource({ resource_id: String(firstResource.id) }) as any;
  if (isError(resResult)) {
    fail('get_resource', resResult.error);
  } else {
    ok('get_resource', { id: resResult.id, name: resResult.name, type: resResult.type });
  }

  // ── 5. get_vault_links ──────────────────────────────────────────────────────
  console.log('5. get_vault_links');
  const vaultResult = await getVaultLinks({ resource_id: String(firstResource.id) }) as any;
  if (isError(vaultResult)) {
    fail('get_vault_links', vaultResult.error);
  } else {
    ok('get_vault_links', {
      resource_links: vaultResult.resource_links?.length ?? 0,
      file_links:     vaultResult.file_links?.length ?? 0,
    });
  }
} else {
  console.warn('  ⚠  browse returned no resources — skipping get_resource and get_vault_links.\n');
}

// ── 6. ask_workspace ─────────────────────────────────────────────────────────
console.log('6. ask_workspace');
const askResult = await askWorkspace({ workspace_id: wsId, question: 'What resources are in this workspace?' }) as any;
if (isError(askResult)) {
  fail('ask_workspace', askResult.error);
} else {
  ok('ask_workspace', {
    answer:  (askResult.answer ?? '').slice(0, 120) + (askResult.answer?.length > 120 ? '…' : ''),
    sources: askResult.sources?.length ?? 0,
  });
}

// ── summary ──────────────────────────────────────────────────────────────────
console.log('═'.repeat(44));
console.log(`    ${passed} passed   ${failed} failed`);
console.log('═'.repeat(44));
if (failed > 0) process.exit(1);
