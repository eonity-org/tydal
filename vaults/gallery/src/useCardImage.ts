/**
 * Resolve a card's display image, best source first:
 *
 *   1. `card.preview` — the resource's designated face (snapshot file or
 *      rendered preview, e.g. a PDF's first page), vault-scoped.
 *   2. `card.url` — the card's own address (streams the binary when the
 *      resource has one exposed file).
 *   3. The resource's first image file via `/files`.
 *
 * `src === null` means none of them worked — the caller renders a
 * typographic placeholder. Shared by the wall (ArtCard) and the close view
 * (Lightbox) so a work never shows its image on the wall and loses it up
 * close.
 */
import { useEffect, useRef, useState } from 'react'
import type { VaultConsumer, VaultResourceCard } from '@tydal/client'

export function useCardImage(card: VaultResourceCard, vault: VaultConsumer) {
  const candidates = [card.preview, card.url].filter((u): u is string => !!u)
  const [index, setIndex] = useState(0)
  const [manifestSrc, setManifestSrc] = useState<string | null>(null)
  const triedManifest = useRef(false)

  useEffect(() => {
    setIndex(0)
    setManifestSrc(null)
    triedManifest.current = false
  }, [card])

  const onError = async () => {
    if (index + 1 < candidates.length) {
      setIndex(index + 1)
      return
    }
    if (triedManifest.current || !card.slug) {
      setManifestSrc(null)
      setIndex(candidates.length)
      return
    }
    triedManifest.current = true
    try {
      const { files } = await vault.resource(card.slug).files()
      const image = files.find((f) => f.mime_type?.startsWith('image/') && f.url)
      setManifestSrc(image?.url ?? null)
    } catch {
      setManifestSrc(null)
    }
    setIndex(candidates.length)
  }

  const src = index < candidates.length ? candidates[index] : manifestSrc

  return { src, onError }
}
