import type { PortfolioOverviewSource } from '@cadran/api-client';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './Explanation.module.css';

function sourceLabel(source: PortfolioOverviewSource): string {
  return `${source.kind === 'OPERATION' ? 'Opération' : 'Valorisation'} du ${formatCalendarNumericDay(source.date)}`;
}

/**
 * The operations and valuations a figure was computed from, folded by default: they are for
 * checking, not reading. Each opens its detail in the page.
 */
export function ExplanationSources({
  sources,
  more,
  onSource,
  hrefOf,
}: {
  sources: readonly PortfolioOverviewSource[];
  /** Sources the response left out beyond the listed ones. */
  more: number;
  onSource: (source: PortfolioOverviewSource) => void;
  /** The page of a source: given, each source is a real link that can open elsewhere. */
  hrefOf?: (source: PortfolioOverviewSource) => string;
}) {
  const total = sources.length + more;
  if (total === 0) return <p className={styles.footnote}>Aucune source à cette date.</p>;
  return (
    <details className={styles.sources}>
      <summary>{`Sources du calcul (${total})`}</summary>
      <ul>
        {sources.map((source) => (
          <li key={`${source.kind}-${source.id}`}>
            {hrefOf ? (
              <a
                className="text-action"
                href={hrefOf(source)}
                onClick={(event) =>
                  handleClientNavigation(event, hrefOf(source), () => onSource(source))
                }
              >
                {sourceLabel(source)}
              </a>
            ) : (
              <button className="text-action" onClick={() => onSource(source)} type="button">
                {sourceLabel(source)}
              </button>
            )}
          </li>
        ))}
        {more > 0 ? <li>{`et ${more} autres`}</li> : null}
      </ul>
    </details>
  );
}
