/**
 * Citation weaving, pure and framework-free.
 *
 * AITY cites resources by NAME in prose (its contract: "cite resources by
 * their name … never internal identifiers"). This module finds those
 * mentions and splits the answer into text/citation segments so the UI can
 * render each mention as a link to the source's vault address. Longest
 * names match first so "Harbor at Dusk (study)" wins over "Harbor at Dusk".
 */
import type { VaultAskSource } from '@tydal/client'

export type AnswerSegment =
  | { kind: 'text'; text: string }
  | { kind: 'citation'; text: string; source: VaultAskSource }

export function annotateAnswer(answer: string, sources: VaultAskSource[]): AnswerSegment[] {
  const named = sources
    .filter((s) => s.resource_name.trim() !== '')
    .sort((a, b) => b.resource_name.length - a.resource_name.length)

  if (named.length === 0 || answer === '') {
    return answer === '' ? [] : [{ kind: 'text', text: answer }]
  }

  const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const pattern = new RegExp(`(${named.map((s) => escape(s.resource_name)).join('|')})`, 'gi')
  const byLowerName = new Map(named.map((s) => [s.resource_name.toLowerCase(), s]))

  const segments: AnswerSegment[] = []
  let last = 0

  for (const match of answer.matchAll(pattern)) {
    const at = match.index ?? 0
    if (at > last) segments.push({ kind: 'text', text: answer.slice(last, at) })
    const source = byLowerName.get(match[0].toLowerCase())!
    segments.push({ kind: 'citation', text: match[0], source })
    last = at + match[0].length
  }

  if (last < answer.length) segments.push({ kind: 'text', text: answer.slice(last) })
  return segments
}
