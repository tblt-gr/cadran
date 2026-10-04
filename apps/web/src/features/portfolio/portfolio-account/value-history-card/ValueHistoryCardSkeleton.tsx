import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import {
  SkeletonChartArea,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import { PeriodControl } from './PeriodControl';
import { VALUE_HISTORY_COLUMNS } from './ValueHistoryTable';
import tableStyles from './ValueHistoryTable.module.css';
import styles from './ValueHistoryCard.module.css';

/** The card's real title, period control and table toggle; only the chart and the values are blocks. */
export function ValueHistoryCardSkeleton({ status }: { status?: string }) {
  return (
    <section
      aria-busy="true"
      aria-labelledby="portfolio-value-history-title"
      className={`card ${styles.card}`}
      data-testid="value-history-skeleton"
    >
      {status ? <SkeletonStatus>{status}</SkeletonStatus> : null}
      <div className={styles.header}>
        <h3 id="portfolio-value-history-title">Évolution de la valeur</h3>
        <PeriodControl disabled onChange={() => undefined} value="24" />
      </div>
      <SkeletonChartArea />
      <Disclosure onToggle={() => undefined} open={false} title="Voir le tableau">
        <div className={tableStyles.scroll}>
          <table>
            <thead>
              <tr>
                {VALUE_HISTORY_COLUMNS.map((text) => (
                  <th key={text} scope="col">
                    {text}
                  </th>
                ))}
              </tr>
            </thead>
            <SkeletonTableRows columns={VALUE_HISTORY_COLUMNS.length} rows={4} />
          </table>
        </div>
      </Disclosure>
    </section>
  );
}
