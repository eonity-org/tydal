/**
 * Facet chips — the per-vault index speaking (fields whose slot aggregates).
 * Selected values stay visible even when the filtered aggregation no longer
 * returns them, so a filter can always be lifted.
 */
export function FacetBar({
  facets,
  selection,
  onToggle,
}: {
  facets: Record<string, Record<string, number>>
  selection: Record<string, string[]>
  onToggle: (field: string, value: string) => void
}) {
  const fields = new Set([...Object.keys(facets), ...Object.keys(selection)])
  if (fields.size === 0) return null

  return (
    <nav className="facet-bar" aria-label="Filters">
      {[...fields].map((field) => {
        const counts = facets[field] ?? {}
        const values = new Set([...Object.keys(counts), ...(selection[field] ?? [])])
        if (values.size === 0) return null

        return (
          <div className="facet-group" key={field}>
            <span className="facet-name">{field}</span>
            {[...values].map((value) => {
              const active = selection[field]?.includes(value) ?? false
              return (
                <button
                  key={value}
                  className={active ? 'chip active' : 'chip'}
                  onClick={() => onToggle(field, value)}
                  aria-pressed={active}
                >
                  {value}
                  {counts[value] !== undefined && <small> {counts[value]}</small>}
                </button>
              )
            })}
          </div>
        )
      })}
    </nav>
  )
}
