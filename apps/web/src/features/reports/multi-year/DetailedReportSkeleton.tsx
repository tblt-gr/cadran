import type { MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { Icon } from '@/components/ui/icon/Icon';
import { MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS } from './multiYearDetailedAggregate';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import localStyles from './ContinuousDetailedReport.module.css';
import styles from './DetailedAnnualReports.module.css';
import skeletonStyles from './MultiYearSkeleton.module.css';

/**
 * Mirrors ContinuousDetailedReport element for element while the annual
 * reports load: caption and the selected column labels are real, every month
 * and figure pending.
 */
export function DetailedReportSkeleton({ report }: { report: MultiYearReport }) {
  const { t } = useTranslation();
  const caption = t('reports.multiYear.detailedCaption', { from: report.from, to: report.to });

  return (
    <div aria-busy="true" className={styles.reports} data-testid="detailed-reports-skeleton">
      <SkeletonStatus>{t('reports.loading')}</SkeletonStatus>
      <div>
        <section
          aria-labelledby="detailed-multi-year-skeleton-title"
          className={`card ${tableStyles.wrap}`}
        >
          <header className="sr-only">
            <h2 className={tableStyles.title} id="detailed-multi-year-skeleton-title">
              {caption}
            </h2>
          </header>
          <div className={`${tableStyles.scroll} ${localStyles.verticalScroll}`}>
            <table aria-hidden="true" className={tableStyles.table}>
              <thead>
                <tr>
                  <th scope="col">{t('reports.annual.month')}</th>
                  {report.columns.map((column) => (
                    <th key={column.id} scope="col">
                      <ColumnHeader column={column} />
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.years.map((year) => (
                  <tr className={localStyles.yearRow} key={year.year}>
                    <th scope="row">
                      <span className={localStyles.yearToggle}>
                        <span aria-hidden="true" className={localStyles.chevron}>
                          <Icon name="chevron-right" size={14} />
                        </span>
                        {year.year}
                      </span>
                    </th>
                    {report.columns.map((column) => (
                      <td key={column.id}>
                        <SkeletonBlock className={skeletonStyles.cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
              <tfoot>
                {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
                  <tr key={rowId}>
                    <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                    {report.columns.map((column) => (
                      <td key={column.id}>
                        <SkeletonBlock className={skeletonStyles.cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tfoot>
            </table>
          </div>
        </section>
        <ul aria-hidden="true" className={cardStyles.list}>
          {report.years.map((year) => (
            <li className={`card ${cardStyles.card}`} key={year.year}>
              <h3>
                <span className={localStyles.yearToggle}>
                  <span aria-hidden="true" className={localStyles.chevron}>
                    <Icon name="chevron-right" size={14} />
                  </span>
                  {year.year}
                </span>
              </h3>
              <dl>
                {report.columns.map((column) => (
                  <div key={column.id}>
                    <dt>
                      <ColumnHeader column={column} />
                    </dt>
                    <dd>
                      <SkeletonBlock className={skeletonStyles.cell} />
                    </dd>
                  </div>
                ))}
              </dl>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
