/**
 * A short, visible handle for a resource id.
 *
 * Resource ids are UUID v7, whose leading characters encode the creation time,
 * so resources created in the same upload share them. The tail of the last
 * segment is random, which keeps siblings apart on every screen that shows it.
 */
export function shortId(id: string | number): string {
  const value = String(id)
  const last = value.includes('-') ? (value.split('-').pop() ?? value) : value
  return last.slice(-8)
}
