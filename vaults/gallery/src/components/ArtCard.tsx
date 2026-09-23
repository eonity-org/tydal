/**
 * One work on the wall. Image resolution (card address → `/files` fallback →
 * typographic placeholder) lives in useCardImage — an exhibition never 404s.
 */
import type { VaultConsumer, VaultResourceCard } from '@tydal/client'
import type { SlotMap } from '../presentation'
import { useCardImage } from '../useCardImage'

export function ArtCard({
  card,
  slots,
  vault,
  onOpen,
}: {
  card: VaultResourceCard
  slots: SlotMap
  vault: VaultConsumer
  onOpen: () => void
}) {
  const { src, onError } = useCardImage(card, vault)

  const caption = slots.caption(card)
  const badges = slots.badges(card)

  return (
    <figure className="art-card" onClick={onOpen} tabIndex={0} role="button"
      onKeyDown={(e) => e.key === 'Enter' && onOpen()}>
      {src ? (
        <img src={src} alt={caption} loading="lazy" onError={() => void onError()} />
      ) : (
        <div className="placeholder" aria-hidden>
          <span>{card.resource_type ?? 'work'}</span>
        </div>
      )}
      <figcaption>
        <strong>{caption}</strong>
        {badges.length > 0 && (
          <span className="badges">
            {badges.slice(0, 3).map((b) => (
              <em key={b}>{b}</em>
            ))}
          </span>
        )}
      </figcaption>
    </figure>
  )
}
