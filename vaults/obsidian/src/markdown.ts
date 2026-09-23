/**
 * Minimal markdown → HTML for note bodies (headings, emphasis, code,
 * lists, links, paragraphs). Zero-dep by design — the vault apps ship no
 * runtime dependencies beyond React and the SDK. All text is escaped
 * BEFORE inline markup is applied, so bodies can never inject HTML.
 */
const escapeHtml = (s: string): string =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

const inline = (s: string): string =>
  s
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
    .replace(
      /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    )

export function renderMarkdown(md: string): string {
  const out: string[] = []
  let list: string[] | null = null
  let code: string[] | null = null

  const flushList = () => {
    if (list) {
      out.push(`<ul>${list.join('')}</ul>`)
      list = null
    }
  }

  for (const raw of md.split('\n')) {
    const line = raw.trimEnd()

    if (code !== null) {
      if (line.startsWith('```')) {
        out.push(`<pre><code>${code.join('\n')}</code></pre>`)
        code = null
      } else {
        code.push(escapeHtml(raw))
      }
      continue
    }

    if (line.startsWith('```')) {
      flushList()
      code = []
      continue
    }

    const heading = /^(#{1,4})\s+(.*)$/.exec(line)
    if (heading) {
      flushList()
      const level = heading[1].length
      out.push(`<h${level}>${inline(escapeHtml(heading[2]))}</h${level}>`)
      continue
    }

    const item = /^[-*]\s+(.*)$/.exec(line)
    if (item) {
      ;(list ??= []).push(`<li>${inline(escapeHtml(item[1]))}</li>`)
      continue
    }

    flushList()
    if (line !== '') out.push(`<p>${inline(escapeHtml(line))}</p>`)
  }

  if (code !== null) out.push(`<pre><code>${(code as string[]).join('\n')}</code></pre>`)
  flushList()
  return out.join('')
}
