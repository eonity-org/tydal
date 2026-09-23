import { describe, it, expect } from 'vitest'
import { renderMarkdown } from './markdown'

describe('renderMarkdown', () => {
  it('renders headings, emphasis, lists and paragraphs', () => {
    const html = renderMarkdown('# Title\n\nSome **bold** and *italic* and `code`.\n\n- one\n- two')
    expect(html).toContain('<h1>Title</h1>')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<em>italic</em>')
    expect(html).toContain('<code>code</code>')
    expect(html).toContain('<ul><li>one</li><li>two</li></ul>')
  })

  it('renders fenced code blocks verbatim', () => {
    const html = renderMarkdown('```\nconst x = 1\n```')
    expect(html).toBe('<pre><code>const x = 1</code></pre>')
  })

  it('links only http(s) URLs and opens them safely', () => {
    const html = renderMarkdown('[docs](https://example.com) [evil](javascript:alert(1))')
    expect(html).toContain('<a href="https://example.com" target="_blank" rel="noopener noreferrer">docs</a>')
    expect(html).not.toContain('href="javascript:')
  })

  it('escapes raw HTML so bodies cannot inject markup', () => {
    const html = renderMarkdown('<script>alert(1)</script>')
    expect(html).not.toContain('<script>')
    expect(html).toContain('&lt;script&gt;')
  })
})
