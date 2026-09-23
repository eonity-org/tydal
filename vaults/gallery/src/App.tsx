/**
 * The exhibition shell. Everything rendered here comes from the vault
 * boundary alone: /meta (identity + presentation), /search (cards + facets),
 * /related. No TYDAL internals, no scheme knowledge — swap the vault, the
 * gallery re-themes itself from the slots.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createVaultConsumer, type VaultMeta, type VaultResourceCard } from '@tydal/client'
import { resolveConfig } from './config'
import { buildSlotMap } from './presentation'
import { ArtCard } from './components/ArtCard'
import { FacetBar } from './components/FacetBar'
import { Lightbox } from './components/Lightbox'

type Facets = Record<string, Record<string, number>>
type Selection = Record<string, string[]>

export default function App() {
  const config = useMemo(() => resolveConfig(), [])
  const vault = useMemo(() => (config ? createVaultConsumer(config) : null), [config])

  const [meta, setMeta] = useState<VaultMeta | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [cards, setCards] = useState<VaultResourceCard[]>([])
  const [facets, setFacets] = useState<Facets>({})
  const [selection, setSelection] = useState<Selection>({})
  const [input, setInput] = useState('')
  const [query, setQuery] = useState('')
  const [semantic, setSemantic] = useState(false)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [open, setOpen] = useState<VaultResourceCard | null>(null)
  const generation = useRef(0)

  const slots = useMemo(() => (meta ? buildSlotMap(meta) : null), [meta])

  useEffect(() => {
    if (!vault) return
    vault.meta().then(setMeta).catch((e) => setError(e?.message ?? 'Vault unreachable'))
  }, [vault])

  const load = useCallback(
    async (nextPage: number, append: boolean) => {
      if (!vault) return
      const gen = ++generation.current
      setLoading(true)
      try {
        const res = await vault.search({
          q: query,
          mode: semantic ? 'semantic' : 'keyword',
          page: nextPage,
          facets: selection,
        })
        if (gen !== generation.current) return
        setCards((prev) => (append ? [...prev, ...res.results] : res.results))
        setFacets(res.facets ?? {})
        setHasMore(res.pagination.has_more)
        setPage(nextPage)
        setError(null)
      } catch (e) {
        if (gen === generation.current) setError((e as Error)?.message ?? 'Search failed')
      } finally {
        if (gen === generation.current) setLoading(false)
      }
    },
    [vault, query, semantic, selection],
  )

  useEffect(() => {
    void load(1, false)
  }, [load])

  const toggleFacet = (field: string, value: string) => {
    setSelection((prev) => {
      const values = prev[field] ?? []
      const next = values.includes(value)
        ? values.filter((v) => v !== value)
        : [...values, value]
      const out = { ...prev, [field]: next }
      if (next.length === 0) delete out[field]
      return out
    })
  }

  if (!config) {
    return (
      <main className="empty-state">
        <h1>TYDAL Gallery</h1>
        <p>
          Point this app at a vault: <code>?vault=org/slug</code> or <code>?hash=…</code>
          {' '}(add <code>&key=tvk_…</code> for a private vault), or set{' '}
          <code>VITE_VAULT</code> at build time.
        </p>
      </main>
    )
  }

  return (
    <div className="gallery">
      <header className="masthead">
        <div>
          <h1>{meta?.name ?? '…'}</h1>
          {meta?.description && <p className="tagline">{meta.description}</p>}
        </div>
        <form
          className="search"
          onSubmit={(e) => {
            e.preventDefault()
            setQuery(input.trim())
          }}
        >
          <input
            type="search"
            value={input}
            placeholder="Search the exhibition…"
            onChange={(e) => setInput(e.target.value)}
            aria-label="Search"
          />
          {meta?.search_modes?.includes('semantic') && (
            <label className="mode-toggle">
              <input
                type="checkbox"
                checked={semantic}
                onChange={(e) => setSemantic(e.target.checked)}
              />
              by meaning
            </label>
          )}
        </form>
      </header>

      <FacetBar facets={facets} selection={selection} onToggle={toggleFacet} />

      {error && <p className="error" role="alert">{error}</p>}

      {slots && (
        <section className="wall" aria-busy={loading}>
          {cards.map((card) => (
            <ArtCard key={card.id} card={card} slots={slots} vault={vault!} onOpen={() => setOpen(card)} />
          ))}
        </section>
      )}

      {!loading && cards.length === 0 && !error && (
        <p className="empty-state">Nothing on this wall{query ? ` for “${query}”` : ''}.</p>
      )}

      {hasMore && (
        <footer className="more">
          <button onClick={() => void load(page + 1, true)} disabled={loading}>
            {loading ? 'Loading…' : 'More works'}
          </button>
        </footer>
      )}

      {open && slots && vault && (
        <Lightbox card={open} slots={slots} vault={vault} onClose={() => setOpen(null)} onOpen={setOpen} />
      )}

      <footer className="colophon">
        {meta && <span>{meta.resource_count} works · a TYDAL vault</span>}
      </footer>
    </div>
  )
}
