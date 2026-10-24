import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import { useTranslation } from 'react-i18next';
import type { ChartPeriod } from '@/lib/chartPeriodRange';
import { DEFAULT_HISTORY_PERIOD, HISTORY_PERIODS } from './valueHistoryRange';
import { VALUE_HISTORY_COLUMNS } from './ValueHistoryTable';
import tableStyles from './ValueHistoryTable.module.css';
import styles from './ValueHistoryCard.module.css';

/** Mirrors ValueHistoryCard element for element: title, period control and table toggle are real; the curve and values pending. */
export function ValueHistoryCardSkeleton({
  period = DEFAULT_HISTORY_PERIOD,
  status,
}: {
  period?: ChartPeriod;
  status?: string;
}) {
  const { t } = useTranslation();
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
        <ChartPeriodControl
          disabled
          label={t('chartPeriod.groupLabel')}
          onChange={() => undefined}
          periods={HISTORY_PERIODS}
          value={period}
        />
      </div>
      <CurveChartSkeleton />
      <Disclosure onToggle={() => undefined} open={false} title="Voir le tableau">
        <div
          className={tableStyles.scroll}
          role="region"
          aria-label="Valorisations du portefeuille par date"
          tabIndex={0}
        >
          <table>
            <caption className="sr-only">Valorisations du portefeuille par date</caption>
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
