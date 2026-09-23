/**
 * One question → answer exchange: the grounded answer with each cited
 * resource name woven into a link, a sources footer (name + pages → the
 * resource's vault address), and related follow-up suggestions.
 */
import type { Turn } from '../App'
import { annotateAnswer } from '../citations'

export function Exchange({ turn, onFollowUp }: { turn: Turn; onFollowUp: (q: string) => void }) {
  return (
    <section className="exchange">
      <p className="question">{turn.question}</p>

      {turn.state === 'thinking' &&
        (turn.partial ? (
          <div className="answer streaming">
            <p>{turn.partial}<span className="cursor" aria-hidden>▍</span></p>
          </div>
        ) : (
          <p className="thinking">Consulting the vault…</p>
        ))}

      {turn.state === 'failed' && <p className="failed" role="alert">{turn.error}</p>}

      {turn.state === 'answered' && turn.result && (
        <div className="answer">
          <p>
            {annotateAnswer(turn.result.answer, turn.result.sources).map((seg, i) =>
              seg.kind === 'citation' && seg.source.url ? (
                <a key={i} href={seg.source.url} target="_blank" rel="noopener noreferrer">
                  {seg.text}
                </a>
              ) : (
                <span key={i}>{seg.text}</span>
              ),
            )}
          </p>

          {turn.result.sources.length > 0 && (
            <footer className="sources">
              <h3>Sources</h3>
              <ul>
                {turn.result.sources.map((s) => (
                  <li key={s.resource_id}>
                    {s.url ? (
                      <a href={s.url} target="_blank" rel="noopener noreferrer">{s.resource_name}</a>
                    ) : (
                      <span>{s.resource_name}</span>
                    )}
                    {s.pages.length > 0 && <small> p. {s.pages.join(', ')}</small>}
                  </li>
                ))}
              </ul>
            </footer>
          )}

          {(turn.related?.length ?? 0) > 0 && (
            <aside className="related">
              <h3>Explore related</h3>
              {turn.related!.map((r) => (
                <button key={r.id} onClick={() => onFollowUp(`Tell me about “${r.name}”.`)}>
                  {r.name}
                </button>
              ))}
            </aside>
          )}
        </div>
      )}
    </section>
  )
}
