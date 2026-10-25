import { useTranslation } from 'react-i18next';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import {
  SkeletonBlock,
  SkeletonChartArea,
  SkeletonStatus,
  SkeletonText,
} from '@/components/ui/skeleton/Skeleton';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import chartStyles from './MultiYearCharts.module.css';
import { MULTI_YEAR_SUMMARY_ROW_IDS } from './multiYearSummary';
import pageStyles from './MultiYearReportPage.module.css';
import styles from './MultiYearSkeleton.module.css';
import tableStyles from './MultiYearTable.module.css';
import cardStyles from './MultiYearCards.module.css';

/** The column set is the user's own choice: four placeholders stand for it until it is known. */
const PLACEHOLDER_COLUMNS = [0, 1, 2, 3];

/** The report shows at most the latest 15 years of a range. */
const MAX_ROWS = 15;

/** Years drawn when the range is not known yet (first visit, before the bounds answer). */
const UNKNOWN_RANGE_YEARS = 3;

interface MultiYearSkeletonProps {
  /** The requested or default range once known, so the year labels can be real. */
  range: { from: number; to: number } | null;
}

function Cell() {
  return <SkeletonBlock className={styles.cell} />;
}

/**
 * Mirrors the compact multi-year report element for element: selector, caption,
 * year and summary labels are real; columns and figures are pending.
 */
export function MultiYearSkeleton({ range }: MultiYearSkeletonProps) {
  const { t } = useTranslation();
  const count = range ? Math.min(range.to - range.from + 1, MAX_ROWS) : 0;
  const years = range
    ? Array.from({ length: count }, (_, index) => range.to - count + 1 + index)
    : null;
  const rows = years ?? Array.from({ length: UNKNOWN_RANGE_YEARS }, () => null);

  return (
    <div aria-busy="true" className={pageStyles.page} data-testid="multi-year-report-skeleton">
      <SkeletonStatus>{t('reports.loading')}</SkeletonStatus>
      <section aria-hidden="true" className={`card ${tableStyles.wrap}`}>
        <header className={tableStyles.header}>
          <h2 className={tableStyles.title}>
            {range ? (
              t('reports.multiYear.caption', { from: range.from, to: range.to })
            ) : (
              <SkeletonBlock className={styles.cell} />
            )}
          </h2>
        </header>
        <div className={tableStyles.scroll}>
          <table className={tableStyles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.multiYear.year')}</th>
                {PLACEHOLDER_COLUMNS.map((column) => (
                  <th key={column} scope="col">
                    <Cell />
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((year, index) => (
                <tr key={year ?? index}>
                  <th scope="row">{year ?? <Cell />}</th>
                  {PLACEHOLDER_COLUMNS.map((column) => (
                    <td key={column}>
                      <Cell />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
            <tfoot>
              {MULTI_YEAR_SUMMARY_ROW_IDS.map((rowId) => (
                <tr key={rowId}>
                  <th scope="row">{t(`reports.multiYear.summary.${rowId}`)}</th>
                  {PLACEHOLDER_COLUMNS.map((column) => (
                    <td key={column}>
                      <Cell />
                    </td>
                  ))}
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
        <footer className={tableStyles.notes}>
          <p>
            <Cell />
          </p>
        </footer>
      </section>
      <ul aria-hidden="true" className={cardStyles.list}>
        {rows.map((year, index) => (
          <li className={`card ${cardStyles.card}`} key={year ?? index}>
            <h3>{year ?? <Cell />}</h3>
            <dl>
              {PLACEHOLDER_COLUMNS.slice(0, 3).map((column) => (
                <div key={column}>
                  <dt>
                    <Cell />
                  </dt>
                  <dd>
                    <Cell />
                  </dd>
                </div>
              ))}
            </dl>
          </li>
        ))}
        <li className={`card ${cardStyles.card}`}>
          <h3>{t('reports.multiYear.summary.average')}</h3>
          <dl>
            {MULTI_YEAR_SUMMARY_ROW_IDS.map((rowId) => (
              <div key={rowId}>
                <dt>{t(`reports.multiYear.summary.${rowId}`)}</dt>
                <dd>
                  <Cell />
                </dd>
              </div>
            ))}
          </dl>
        </li>
      </ul>
      <MultiYearChartsSkeleton />
    </div>
  );
}

/** Mirrors MultiYearCharts element for element: frame titles and data toggles real, plots pending. */
export function MultiYearChartsSkeleton() {
  const { t } = useTranslation();
  return (
    <div className={chartStyles.grid}>
      <ChartFrame data={<SkeletonText lines={2} />} title={t('reports.multiYear.charts.flows')}>
        <SkeletonChartArea />
      </ChartFrame>
      <ChartFrame data={<SkeletonText lines={2} />} title={t('reports.multiYear.charts.netWorth')}>
        <CurveChartSkeleton />
      </ChartFrame>
    </div>
  );
}
