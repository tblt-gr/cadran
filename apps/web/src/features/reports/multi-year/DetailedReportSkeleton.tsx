import type { MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import localStyles from './ContinuousDetailedReport.module.css';
import styles from './DetailedAnnualReports.module.css';
import skeletonStyles from './MultiYearSkeleton.module.css';

const ROWS = Array.from({ length: 12 }, (_, index) => index);

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
          <header className={tableStyles.header}>
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
                {ROWS.map((row) => (
                  <tr key={row}>
                    <th scope="row">
                      <SkeletonBlock className={skeletonStyles.cell} />
                    </th>
                    {report.columns.map((column) => (
                      <td key={column.id}>
                        <SkeletonBlock className={skeletonStyles.cell} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
        <ul aria-hidden="true" className={cardStyles.list}>
          {ROWS.map((row) => (
            <li className={`card ${cardStyles.card}`} key={row}>
              <h3>
                <SkeletonBlock className={skeletonStyles.cell} />
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
