import { useTranslation } from 'react-i18next';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import {
  SkeletonBlock,
  SkeletonChartArea,
  SkeletonStatus,
  SkeletonText,
} from '@/components/ui/skeleton/Skeleton';
import { ChartFrame } from './ChartFrame';
import { SUMMARY_ROW_IDS } from './annualColumns';
import { monthLabel } from './annualRowLabels';
import styles from './AnnualReportPage.module.css';
import cardStyles from './AnnualMonthCards.module.css';
import chartStyles from './AnnualCharts.module.css';
import tableStyles from './AnnualReportTable.module.css';

/** The column set is the user's own choice: four placeholders stand for it until it is known. */
const PLACEHOLDER_COLUMNS = [0, 1, 2, 3];

const MONTHS = Array.from({ length: 12 }, (_, index) => String(index + 1).padStart(2, '0'));

/**
 * Mirrors the loaded annual report element for element: title, month names,
 * summary labels and chart frames are real; the columns and every figure are pending.
 */
export function AnnualReportPageSkeleton({ status, year }: { status: string; year: number }) {
  const { i18n, t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.page} data-testid="annual-report-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section aria-hidden="true" className={`card ${tableStyles.wrap}`}>
        <header className={tableStyles.header}>
          <h2 className={tableStyles.title}>{t('reports.annual.caption', { year })}</h2>
        </header>
        <div className={tableStyles.scroll}>
          <table className={tableStyles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
                {PLACEHOLDER_COLUMNS.map((column) => (
                  <th key={column} scope="col">
                    <SkeletonBlock className={styles.skeletonCell} />
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {MONTHS.map((month) => (
                <tr key={month}>
                  <th scope="row">{monthLabel(`${year}-${month}`, i18n.language)}</th>
                  {PLACEHOLDER_COLUMNS.map((column) => (
                    <td key={column}>
                      <SkeletonBlock className={styles.skeletonCell} />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
            <tfoot>
              {SUMMARY_ROW_IDS.map((rowId) => (
                <tr key={rowId}>
                  <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                  {PLACEHOLDER_COLUMNS.map((column) => (
                    <td key={column}>
                      <SkeletonBlock className={styles.skeletonCell} />
                    </td>
                  ))}
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
      </section>
      <ul aria-hidden="true" className={cardStyles.list}>
        {MONTHS.map((month) => (
          <li className={`card ${cardStyles.card}`} key={month}>
            <h3>{monthLabel(`${year}-${month}`, i18n.language)}</h3>
            <dl>
              {PLACEHOLDER_COLUMNS.slice(0, 3).map((column) => (
                <div key={column}>
                  <dt>
                    <SkeletonBlock className={styles.skeletonCell} />
                  </dt>
                  <dd>
                    <SkeletonBlock className={styles.skeletonCell} />
                  </dd>
                </div>
              ))}
            </dl>
          </li>
        ))}
        <li className={`card ${cardStyles.card}`}>
          <h3>{t('reports.annual.summaryTitle')}</h3>
          {PLACEHOLDER_COLUMNS.slice(0, 2).map((column) => (
            <section key={column}>
              <h4>
                <SkeletonBlock className={styles.skeletonCell} />
              </h4>
              <dl>
                {SUMMARY_ROW_IDS.map((rowId) => (
                  <div key={rowId}>
                    <dt>{t(`reports.annual.summary.${rowId}`)}</dt>
                    <dd>
                      <SkeletonBlock className={styles.skeletonCell} />
                    </dd>
                  </div>
                ))}
              </dl>
            </section>
          ))}
        </li>
      </ul>
      <div className={chartStyles.grid}>
        <ChartFrame data={<SkeletonText lines={2} />} title={t('reports.annual.charts.flows')}>
          <SkeletonChartArea />
        </ChartFrame>
        <ChartFrame data={<SkeletonText lines={2} />} title={t('reports.annual.charts.netWorth')}>
          <CurveChartSkeleton />
        </ChartFrame>
        <ChartFrame title={t('reports.annual.charts.topCategories')}>
          <SkeletonChartArea />
        </ChartFrame>
        <ChartFrame title={t('reports.annual.charts.allocation')}>
          <SkeletonChartArea />
        </ChartFrame>
      </div>
    </div>
  );
}
