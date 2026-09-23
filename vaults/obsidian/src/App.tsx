/**
 * The knowledge-graph shell. Everything rendered here comes from the vault
 * boundary alone: /meta (identity + presentation), /graph (nodes + edges),
 * /{slug}/meta and /chunks for the open note. No TYDAL internals, no scheme
 * knowledge — swap the vault, the notebook re-themes itself from the slots.
 */
import { useEffect, useMemo, useState } from 'react'
import { createVaultConsumer, type VaultGraph, type VaultMeta, type VaultResourceCard } from '@tydal/client'
import { resolveConfig } from './config'
import { buildSlotMap } from './presentation'
import { backlinksIndex, outlinksIndex } from './graph'
import { GraphView } from './components/GraphView'
import { NoteView } from './components/NoteView'
import { Backlinks } from './components/Backlinks'

export default function App() {
  const config = useMemo(() => resolveConfig(), [])
  const vault = useMemo(() => (config ? createVaultConsumer(config) : null), [config])

  const [meta, setMeta] = useState<VaultMeta | null>(null)
  const [graph, setGraph] = useState<VaultGraph | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [filter, setFilter] = useState('')
  const [open, setOpen] = useState<VaultResourceCard | null>(null)

  const slots = useMemo(() => (meta ? buildSlotMap(meta) : null), [meta])
  const byId = useMemo(() => new Map((graph?.nodes ?? []).map((n) => [n.id, n])), [graph])
  const incoming = useMemo(() => backlinksIndex(graph?.edges ?? []), [graph])
  const outgoing = useMemo(() => outlinksIndex(graph?.edges ?? []), [graph])

  useEffect(() => {
    if (!vault) return
    vault.meta().then(setMeta).catch((e) => setError(e?.message ?? 'Vault unreachable'))
    vault.graph().then(setGraph).catch((e) => setError(e?.message ?? 'Graph unavailable'))
  }, [vault])

  const notes = useMemo(() => {
    if (!graph || !slots) return []
    const q = filter.trim().toLowerCase()
    const all = [...graph.nodes].sort((a, b) => slots.label(a).localeCompare(slots.label(b)))
    return q === '' ? all : all.filter((n) => slots.label(n).toLowerCase().includes(q))
  }, [graph, slots, filter])

  if (!config) {
    return (
      <main className="empty-state">
        <h1>TYDAL Notes</h1>
        <p>
          Point this app at a vault: <code>?vault=org/slug</code> or <code>?hash=…</code>
          {' '}(add <code>&key=tvk_…</code> for a private vault), or set{' '}
          <code>VITE_VAULT</code> at build time.
        </p>
      </main>
    )
  }

  return (
    <div className="notebook">
      <nav className="sidebar">
        <header>
          <h1>{meta?.name ?? '…'}</h1>
          <button className="to-graph" onClick={() => setOpen(null)} disabled={!open}>
            ◉ Graph
          </button>
        </header>
        <input
          type="search"
          value={filter}
          placeholder="Filter notes…"
          onChange={(e) => setFilter(e.target.value)}
          aria-label="Filter notes"
        />
        <ul className="note-list">
          {slots &&
            notes.map((n) => (
              <li key={n.id}>
                <button className={n.id === open?.id ? 'active' : ''} onClick={() => setOpen(n)}>
                  {slots.label(n)}
                  <small>
                    {(incoming.get(n.id)?.length ?? 0) + (outgoing.get(n.id)?.length ?? 0)}
                  </small>
                </button>
              </li>
            ))}
        </ul>
        <footer>
          {graph && (
            <span>
              {graph.nodes.length} notes · {graph.edges.length} links
              {graph.truncated ? ' (truncated)' : ''}
            </span>
          )}
        </footer>
      </nav>

      <main className="canvas">
        {error && <p className="error" role="alert">{error}</p>}

        {!open && graph && slots && (
          <GraphView graph={graph} slots={slots} activeId={null} onOpen={setOpen} />
        )}

        {open && slots && vault && (
          <div className="reading">
            <NoteView
              card={open}
              slots={slots}
              vault={vault}
              chunksAvailable={meta?.tiers?.chunks ?? false}
            />
            <div className="context">
              {graph && (
                <GraphView graph={graph} slots={slots} activeId={open.id} onOpen={setOpen} />
              )}
              <Backlinks
                incoming={incoming.get(open.id) ?? []}
                outgoing={outgoing.get(open.id) ?? []}
                byId={byId}
                slots={slots}
                onOpen={setOpen}
              />
            </div>
          </div>
        )}
      </main>
    </div>
  )
}
