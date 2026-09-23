/**
 * One note, fully resolved: title (node_label), frontmatter-style
 * properties, prose from body-slot fields, and — when the vault exposes
 * chunks — the extracted document text, section by section. Everything is
 * fetched through the vault grammar; nothing is scheme-specific.
 */
import { useEffect, useState } from 'react'
import type { VaultConsumer, VaultResourceCard } from '@tydal/client'
import type { SlotMap } from '../presentation'
import { renderMarkdown } from '../markdown'

interface Chunk {
  sequence?: number
  content?: string
}

export function NoteView({
  card,
  slots,
  vault,
  chunksAvailable,
}: {
  card: VaultResourceCard
  slots: SlotMap
  vault: VaultConsumer
  chunksAvailable: boolean
}) {
  const [meta, setMeta] = useState<VaultResourceCard | null>(null)
  const [chunks, setChunks] = useState<Chunk[]>([])

  useEffect(() => {
    setMeta(null)
    setChunks([])
    if (!card.slug) return

    vault.resource(card.slug).meta().then((m) => setMeta(m as VaultResourceCard)).catch(() => setMeta(null))

    if (chunksAvailable) {
      vault
        .resource(card.slug)
        .chunks()
        .then((res) => setChunks((res.items as Chunk[]) ?? []))
        .catch(() => setChunks([]))
    }
  }, [vault, card, chunksAvailable])

  // The card from a listing has no metadata; /meta fills it in.
  const resolved = meta ?? card
  const properties = slots.properties(resolved)
  const body = slots.body(resolved)

  return (
    <article className="note">
      <h1>{slots.label(resolved)}</h1>

      {properties.length > 0 && (
        <dl className="properties">
          {properties.map((p) => (
            <div key={p.label}>
              <dt>{p.label}</dt>
              <dd>{p.value}</dd>
            </div>
          ))}
        </dl>
      )}

      {resolved.description && <p className="summary">{resolved.description}</p>}

      {body && <section dangerouslySetInnerHTML={{ __html: renderMarkdown(body) }} />}

      {chunks.length > 0 && (
        <section className="document-text">
          {chunks.map((c, i) => (
            <div
              key={c.sequence ?? i}
              dangerouslySetInnerHTML={{ __html: renderMarkdown(c.content ?? '') }}
            />
          ))}
        </section>
      )}

      {(card.tags?.length ?? 0) > 0 && (
        <p className="tags">{card.tags.map((t) => <em key={t}>{t}</em>)}</p>
      )}
    </article>
  )
}
