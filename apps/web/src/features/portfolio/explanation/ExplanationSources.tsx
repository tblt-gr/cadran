import type { PortfolioOverviewSource } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './Explanation.module.css';

function sourceLabel(source: PortfolioOverviewSource, t: TFunction): string {
  return t(`portfolio.explanation.source.${source.kind}`, {
    date: formatCalendarNumericDay(source.date),
  });
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
  const { t } = useTranslation();
  const total = sources.length + more;
  if (total === 0) return <p className={styles.footnote}>{t('portfolio.explanation.noSource')}</p>;
  return (
    <details className={styles.sources}>
      <summary>{t('portfolio.explanation.sources', { count: total })}</summary>
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
                {sourceLabel(source, t)}
              </a>
            ) : (
              <button className="text-action" onClick={() => onSource(source)} type="button">
                {sourceLabel(source, t)}
              </button>
            )}
          </li>
        ))}
        {more > 0 ? <li>{t('portfolio.explanation.more', { count: more })}</li> : null}
      </ul>
    </details>
  );
}
