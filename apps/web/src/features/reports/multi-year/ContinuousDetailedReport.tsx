import type { AnnualColumn, AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { isEmptyState, monthLabel } from '@/features/reports/annual/annualRowLabels';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import mixedPolicyStyles from '@/features/reports/annual/MixedPolicyBanner.module.css';
import localStyles from './ContinuousDetailedReport.module.css';
import {
  MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS,
  multiYearDetailedAggregateFigure,
} from './multiYearDetailedAggregate';

interface ContinuousDetailedReportProps {
  annualReports: AnnualReport[];
  report: MultiYearReport;
}

function selectedColumns(reports: AnnualReport[]): AnnualColumn[] {
  const columns = new Map<string, AnnualColumn>();
  for (const report of reports) {
    for (const column of report.columns) {
      columns.set(column.id, column);
    }
  }
  return [...columns.values()];
}

/**
 * One continuous presentation of monthly, server-supplied annual report rows. It deliberately
 * flattens display lists only: exact financial values remain owned by the annual responses.
 */
export function ContinuousDetailedReport({ annualReports, report }: ContinuousDetailedReportProps) {
  const { t, i18n } = useTranslation();
  const columns = selectedColumns(annualReports);
  const rows = annualReports.flatMap((annualReport) =>
    annualReport.rows.map((row) => ({ annualReport, row })),
  );
  const versions = new Set(
    report.years.flatMap((year) =>
      year.metricPolicy.state === 'SINGLE' && year.metricPolicy.version !== null
        ? [year.metricPolicy.version]
        : [],
    ),
  );
  const hasMixedPolicy =
    report.years.some((year) => year.metricPolicy.state === 'MIXED') || versions.size > 1;
  const titleId = 'detailed-multi-year-table';

  return (
    <div>
      {hasMixedPolicy ? (
        <aside className={mixedPolicyStyles.banner} role="status">
          <p>{t('reports.multiYear.detailedMixedPolicy')}</p>
          <a
            href="/parameters/metric-policy"
            onClick={(event) => handleClientNavigation(event, '/parameters/metric-policy')}
          >
            {t('reports.annual.mixedPolicyLink')}
          </a>
        </aside>
      ) : null}
      <section aria-labelledby={titleId} className={`card ${tableStyles.wrap}`}>
        <header className={tableStyles.header}>
          <h2 className={tableStyles.title} id={titleId}>
            {t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}
          </h2>
        </header>
        <div
          aria-label={t('reports.multiYear.detailedCaption', { from: report.from, to: report.to })}
          className={`${tableStyles.scroll} ${localStyles.verticalScroll}`}
          role="region"
          tabIndex={0}
        >
          <table aria-labelledby={titleId} className={tableStyles.table}>
            <thead>
              <tr>
                <th scope="col">{t('reports.annual.month')}</th>
                {columns.map((column) => (
                  <th key={column.id} scope="col">
                    {column.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(({ annualReport, row }) => {
                const isYearBoundary = row.month.endsWith('-12');
                return (
                  <tr
                    className={[
                      isEmptyState(row.state) ? tableStyles.dimmed : '',
                      isYearBoundary ? localStyles.yearBoundary : '',
                    ]
                      .filter(Boolean)
                      .join(' ')}
                    key={`${annualReport.year}-${row.month}`}
                  >
                    <th scope="row">
                      {monthLabel(row.month, i18n.language)} {annualReport.year}
                    </th>
                    {columns.map((column) => {
                      const cell = row.cells[column.id];
                      return (
                        <td key={column.id}>
                          {row.state === 'FUTURE' ? (
                            <EmptyValue label={t('reports.annual.future')} />
                          ) : row.state === 'NO_DATA' ? (
                            <EmptyValue label={t('reports.annual.noData')} />
                          ) : (
                            <AnnualCellValue
                              assetCode={column.assetCode}
                              kind={column.kind}
                              reason={cell?.reason ?? null}
                              value={cell?.value ?? null}
                            />
                          )}
                        </td>
                      );
                    })}
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
                <tr key={rowId}>
                  <th scope="row">{t(`reports.annual.summary.${rowId}`)}</th>
                  {columns.map((column) => {
                    const aggregate = report.detailedPeriod[column.id];
                    if (!aggregate) return <td key={column.id} />;
                    const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
                    return (
                      <td key={column.id}>
                        <AnnualCellValue
                          assetCode={column.assetCode}
                          kind={column.kind}
                          reason={figure.reason}
                          value={figure.value}
                        />
                        {figure.month ? (
                          <small>
                            {' '}
                            ({monthLabel(figure.month, i18n.language)} {figure.month.slice(0, 4)})
                          </small>
                        ) : null}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tfoot>
          </table>
        </div>
      </section>

      <ul
        aria-label={t('reports.multiYear.detailedCardsLabel', { from: report.from, to: report.to })}
        className={cardStyles.list}
      >
        {rows.map(({ annualReport, row }) => (
          <li
            className={`card ${cardStyles.card} ${isEmptyState(row.state) ? cardStyles.dimmed : ''}`}
            key={`${annualReport.year}-${row.month}`}
          >
            <h3>
              {monthLabel(row.month, i18n.language)} {annualReport.year}
            </h3>
            {row.state === 'FUTURE' ? (
              <p>
                <EmptyValue label={t('reports.annual.future')} />
              </p>
            ) : row.state === 'NO_DATA' ? (
              <p>
                <EmptyValue label={t('reports.annual.noData')} />
              </p>
            ) : (
              <dl>
                {columns.map((column) => (
                  <div key={column.id}>
                    <dt>{column.label}</dt>
                    <dd>
                      <AnnualCellValue
                        assetCode={column.assetCode}
                        kind={column.kind}
                        reason={row.cells[column.id]?.reason ?? null}
                        value={row.cells[column.id]?.value ?? null}
                      />
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </li>
        ))}
        <li className={`card ${cardStyles.card}`}>
          <h3>{t('reports.multiYear.detailedSummaryCard')}</h3>
          {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
            <dl key={rowId}>
              <dt>{t(`reports.annual.summary.${rowId}`)}</dt>
              {columns.map((column) => {
                const aggregate = report.detailedPeriod[column.id];
                if (!aggregate) return null;
                const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
                return (
                  <dd key={column.id}>
                    {column.label}
                    {': '}
                    <AnnualCellValue
                      assetCode={column.assetCode}
                      kind={column.kind}
                      reason={figure.reason}
                      value={figure.value}
                    />
                    {figure.month ? (
                      <small>
                        {' '}
                        ({monthLabel(figure.month, i18n.language)} {figure.month.slice(0, 4)})
                      </small>
                    ) : null}
                  </dd>
                );
              })}
            </dl>
          ))}
        </li>
      </ul>
    </div>
  );
}
