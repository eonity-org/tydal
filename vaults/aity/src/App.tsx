/**
 * The ask shell. Everything rendered here comes from the vault boundary
 * alone: /meta (identity + whether this vault answers at all), POST /ask
 * (grounded answer + resource-level sources), /related (follow-up
 * suggestions from the top source). No TYDAL internals, no scheme knowledge.
 */
import { useEffect, useMemo, useRef, useState } from 'react'
import {
  createVaultConsumer,
  type VaultAskResult,
  type VaultMeta,
  type VaultResourceCard,
} from '@tydal/client'
import { resolveConfig } from './config'
import { Exchange } from './components/Exchange'

export interface Turn {
  id: number
  question: string
  state: 'thinking' | 'answered' | 'failed'
  /** Answer text accumulated live from the stream while `state === 'thinking'`. */
  partial: string
  result?: VaultAskResult
  error?: string
  related?: VaultResourceCard[]
}

export default function App() {
  const config = useMemo(() => resolveConfig(), [])
  const vault = useMemo(() => (config ? createVaultConsumer(config) : null), [config])

  const [meta, setMeta] = useState<VaultMeta | null>(null)
  const [metaError, setMetaError] = useState<string | null>(null)
  const [turns, setTurns] = useState<Turn[]>([])
  const [input, setInput] = useState('')
  const nextId = useRef(1)
  const endRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!vault) return
    vault.meta().then(setMeta).catch((e) => setMetaError(e?.message ?? 'Vault unreachable'))
  }, [vault])

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [turns])

  const busy = turns.some((t) => t.state === 'thinking')

  const ask = async (question: string) => {
    if (!vault || question.trim().length < 3 || busy) return
    const id = nextId.current++
    setInput('')
    setTurns((prev) => [...prev, { id, question, state: 'thinking', partial: '' }])

    try {
      const result = await vault.askStream({
        question,
        onToken: (text) =>
          setTurns((prev) => prev.map((t) => (t.id === id ? { ...t, partial: t.partial + text } : t))),
      })
      setTurns((prev) => prev.map((t) => (t.id === id ? { ...t, state: 'answered', result } : t)))

      // Follow-up suggestions: the graph neighborhood of the top source.
      const topSlug = result.sources[0]?.slug
      if (topSlug) {
        vault
          .resource(topSlug)
          .related()
          .then((rel) =>
            setTurns((prev) =>
              prev.map((t) => (t.id === id ? { ...t, related: rel.resources.slice(0, 4) } : t)),
            ),
          )
          .catch(() => undefined)
      }
    } catch (e) {
      const status = (e as { status?: number })?.status
      const error =
        status === 403
          ? 'This vault does not answer questions.'
          : status === 429
            ? 'Too many questions — give it a minute.'
            : ((e as Error)?.message ?? 'The vault could not answer.')
      setTurns((prev) => prev.map((t) => (t.id === id ? { ...t, state: 'failed', error } : t)))
    }
  }

  if (!config) {
    return (
      <main className="empty-state">
        <h1>TYDAL Ask</h1>
        <p>
          Point this app at a vault: <code>?vault=org/slug</code> or <code>?hash=…</code>
          {' '}(add <code>&key=tvk_…</code> for a private vault), or set{' '}
          <code>VITE_VAULT</code> at build time.
        </p>
      </main>
    )
  }

  const askable = meta?.tiers?.ask ?? true // optimistic until meta lands

  return (
    <div className="ask-app">
      <header>
        <h1>{meta?.name ?? '…'}</h1>
        {meta?.description && <p className="tagline">{meta.description}</p>}
        {meta && (
          <p className="scope">
            Answers come only from this vault — {meta.resource_count} resources.
          </p>
        )}
      </header>

      {metaError && <p className="error" role="alert">{metaError}</p>}
      {meta && !askable && (
        <p className="error" role="alert">
          This vault does not expose reasoning — browse it with the gallery or notes app instead.
        </p>
      )}

      <main className="thread">
        {turns.length === 0 && askable && !metaError && (
          <p className="hint">Ask anything about what this vault holds.</p>
        )}
        {vault &&
          turns.map((turn) => <Exchange key={turn.id} turn={turn} onFollowUp={ask} />)}
        <div ref={endRef} />
      </main>

      <form
        className="composer"
        onSubmit={(e) => {
          e.preventDefault()
          void ask(input)
        }}
      >
        <input
          type="text"
          value={input}
          placeholder={askable ? 'Ask this vault…' : 'This vault does not answer questions'}
          disabled={!askable || busy}
          onChange={(e) => setInput(e.target.value)}
          aria-label="Question"
        />
        <button type="submit" disabled={!askable || busy || input.trim().length < 3}>
          {busy ? 'Thinking…' : 'Ask'}
        </button>
      </form>
    </div>
  )
}
