/**
 * The close view of one work: full slots (caption / subcaption / details /
 * credit / badges) plus its graph neighborhood via the vault's `/related` —
 * clicking a related work walks the wall without leaving the lightbox.
 */
import { useEffect, useState } from 'react'
import type { VaultConsumer, VaultRelated, VaultResourceCard } from '@tydal/client'
import type { SlotMap } from '../presentation'
import { useCardImage } from '../useCardImage'

export function Lightbox({
  card,
  slots,
  vault,
  onClose,
  onOpen,
}: {
  card: VaultResourceCard
  slots: SlotMap
  vault: VaultConsumer
  onClose: () => void
  onOpen: (card: VaultResourceCard) => void
}) {
  const [related, setRelated] = useState<VaultRelated | null>(null)
  const { src, onError } = useCardImage(card, vault)

  useEffect(() => {
    setRelated(null)
    if (!card.slug) return
    vault.resource(card.slug).related().then(setRelated).catch(() => setRelated(null))
  }, [vault, card])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const details = slots.details(card)
  const credit = slots.credit(card)
  const badges = slots.badges(card)

  return (
    <div className="lightbox" role="dialog" aria-modal="true" aria-label={slots.caption(card)} onClick={onClose}>
      <article onClick={(e) => e.stopPropagation()}>
        <button className="close" onClick={onClose} aria-label="Close">×</button>

        {src ? (
          <img src={src} alt={slots.caption(card)} onError={() => void onError()} />
        ) : (
          <div className="placeholder large"><span>{card.resource_type ?? 'work'}</span></div>
        )}

        <div className="plate">
          <h2>{slots.caption(card)}</h2>
          {slots.subcaption(card) && <p className="subcaption">{slots.subcaption(card)}</p>}

          {details.length > 0 && (
            <dl className="details">
              {details.map((d) => (
                <div key={d.label}>
                  <dt>{d.label}</dt>
                  <dd>{d.value}</dd>
                </div>
              ))}
            </dl>
          )}

          {credit && <p className="credit">{credit}</p>}

          {badges.length > 0 && (
            <p className="badges">{badges.map((b) => <em key={b}>{b}</em>)}</p>
          )}

          {related && related.resources.length > 0 && (
            <aside className="related">
              <h3>Related works</h3>
              <ul>
                {related.resources.slice(0, 6).map((r) => (
                  <li key={r.id}>
                    <button onClick={() => onOpen(r)}>{r.name}</button>
                    {r.relation && <small> {r.relation.origin}</small>}
                  </li>
                ))}
              </ul>
            </aside>
          )}
        </div>
      </article>
    </div>
  )
}
