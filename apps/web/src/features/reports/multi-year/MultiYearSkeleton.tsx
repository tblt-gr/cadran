import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import {
  SkeletonBlock,
  SkeletonChartArea,
  SkeletonStatus,
  SkeletonText,
} from '@/components/ui/skeleton/Skeleton';
import { MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS } from './multiYearDetailedAggregate';
import localStyles from './ContinuousDetailedReport.module.css';
import { ChartFrame } from '@/features/reports/annual/ChartFrame';
import chartStyles from './MultiYearCharts.module.css';
import pageStyles from './MultiYearReportPage.module.css';
import styles from './MultiYearSkeleton.module.css';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';

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
 * Mirrors the multi-year report while it loads: caption and year labels are real, columns and
 * figures are pending.
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
        <header className="sr-only">
          <h2 className={tableStyles.title}>
            {range ? (
              t('reports.multiYear.caption', { from: range.from, to: range.to })
            ) : (
              <SkeletonBlock className={styles.cell} />
            )}
          </h2>
        </header>
        <div className={`${tableStyles.scroll} ${localStyles.verticalScroll}`}>
          <table className={tableStyles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
                {PLACEHOLDER_COLUMNS.map((column) => (
                  <th key={column} scope="col">
                    <Cell />
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((year, index) => (
                <tr className={localStyles.yearRow} key={year ?? index}>
                  <th scope="row">
                    {year === null ? (
                      <Cell />
                    ) : (
                      <span className={localStyles.yearToggle}>
                        <span aria-hidden="true" className={localStyles.chevron}>
                          <Icon name="chevron-right" size={14} />
                        </span>
                        {year}
                      </span>
                    )}
                  </th>
                  {PLACEHOLDER_COLUMNS.map((column) => (
                    <td key={column}>
                      <Cell />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
            <tfoot>
              {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
                <tr key={rowId}>
                  <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
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
      </section>
      <ul aria-hidden="true" className={cardStyles.list}>
        {rows.map((year, index) => (
          <li className={`card ${cardStyles.card}`} key={year ?? index}>
            <h3>
              {year === null ? (
                <Cell />
              ) : (
                <span className={localStyles.yearToggle}>
                  <span aria-hidden="true" className={localStyles.chevron}>
                    <Icon name="chevron-right" size={14} />
                  </span>
                  {year}
                </span>
              )}
            </h3>
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
      <ChartFrame
        actions={
          <button
            aria-label={t('reports.multiYear.charts.flowsByYear')}
            className="icon-ghost"
            disabled
            type="button"
          >
            <Icon name="calendar" size={16} />
          </button>
        }
        data={<SkeletonText lines={2} />}
        title={t('reports.multiYear.charts.flows')}
      >
        <SkeletonChartArea />
      </ChartFrame>
      <ChartFrame data={<SkeletonText lines={2} />} title={t('reports.multiYear.charts.netWorth')}>
        <CurveChartSkeleton />
      </ChartFrame>
    </div>
  );
}
