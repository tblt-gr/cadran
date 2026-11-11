import type { AnnualColumn, AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { useState } from 'react';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { DetailedAggregateModal } from './DetailedAggregateModal';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { GoalMark, GoalMarkedValue } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalEvaluations } from '@/features/goals/goal-panel/useGoalPanelData';
import { useAnnualReportPreferences } from '@/features/reports/annual/useAnnualReport';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { isEmptyState, monthLabel } from '@/features/reports/annual/annualRowLabels';
import {
  annualCellHref,
  groupStartIds,
  withoutLeadingEmptyMonths,
} from '@/features/reports/annual/annualColumns';
import { ColumnHeader } from '@/features/reports/annual/ColumnHeader';
import { useAnnualColumnLabel } from '@/features/reports/annual/columnLabel';
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
  const labelOf = useAnnualColumnLabel();
  const [explained, setExplained] = useState<string | null>(null);
  const preferences = useAnnualReportPreferences();
  const goalsEnabled = !preferences.isPending && preferences.data?.goalMarks !== false;
  const goals = useGoalEvaluations(
    { kind: 'years', years: report.years.map((year) => year.year) },
    goalsEnabled,
  );
  const columns = selectedColumns(annualReports);
  // The first year may start mid-year: its empty leading months are not rows of the chronology.
  const rows = annualReports.flatMap((annualReport, index) =>
    (index === 0 ? withoutLeadingEmptyMonths(annualReport.rows) : annualReport.rows).map((row) => ({
      annualReport,
      row,
    })),
  );
  const starts = groupStartIds(columns);
  const edge = (id: string) => (starts.has(id) ? tableStyles.groupStart : undefined);
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
      <section aria-labelledby={titleId} className={`card ${tableStyles.wrap}`}>
        <header className="sr-only">
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
                  <th className={edge(column.id)} key={column.id} scope="col">
                    <ColumnHeader column={column} />
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
                      const marks = marksFor(
                        goalsEnabled ? goals.evaluations?.months : undefined,
                        row.month,
                        column.id,
                      );
                      return (
                        <td className={edge(column.id)} key={column.id}>
                          {row.state === 'FUTURE' ? (
                            <EmptyValue quiet label={t('reports.annual.future')} />
                          ) : row.state === 'NO_DATA' ? (
                            <EmptyValue quiet label={t('reports.annual.noData')} />
                          ) : (
                            <>
                              <GoalMarkedValue items={marks}>
                                <AnnualCellValue
                                  assetCode={column.assetCode}
                                  href={annualCellHref(column.id, row.month)}
                                  kind={column.kind}
                                  reason={cell?.reason ?? null}
                                  value={cell?.value ?? null}
                                />
                              </GoalMarkedValue>
                              <GoalMark items={marks} period={row.month} />
                            </>
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
                    if (!aggregate) return <td className={edge(column.id)} key={column.id} />;
                    const figure = multiYearDetailedAggregateFigure(aggregate, rowId);
                    return (
                      <td className={edge(column.id)} key={column.id}>
                        <AnnualCellValue
                          assetCode={column.assetCode}
                          kind={column.kind}
                          reason={figure.reason}
                          value={figure.value}
                        />
                        {rowId === 'total' && figure.value !== null ? (
                          <InfoButton
                            aria-haspopup="dialog"
                            className={tableStyles.explain}
                            label={t('reports.annual.explainColumn', { column: labelOf(column) })}
                            onClick={() => setExplained(column.id)}
                          />
                        ) : null}
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
        {goalsEnabled && goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
      </section>
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
                <EmptyValue quiet label={t('reports.annual.future')} />
              </p>
            ) : row.state === 'NO_DATA' ? (
              <p>
                <EmptyValue quiet label={t('reports.annual.noData')} />
              </p>
            ) : (
              <dl>
                {columns.map((column) => {
                  const marks = marksFor(
                    goalsEnabled ? goals.evaluations?.months : undefined,
                    row.month,
                    column.id,
                  );
                  return (
                    <div key={column.id}>
                      <dt>
                        <ColumnHeader column={column} />
                      </dt>
                      <dd>
                        <GoalMarkedValue items={marks}>
                          <AnnualCellValue
                            assetCode={column.assetCode}
                            href={annualCellHref(column.id, row.month)}
                            kind={column.kind}
                            reason={row.cells[column.id]?.reason ?? null}
                            value={row.cells[column.id]?.value ?? null}
                          />
                        </GoalMarkedValue>
                        <GoalMark items={marks} period={row.month} />
                      </dd>
                    </div>
                  );
                })}
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
                    {labelOf(column)}
                    {': '}
                    <AnnualCellValue
                      assetCode={column.assetCode}
                      kind={column.kind}
                      reason={figure.reason}
                      value={figure.value}
                    />
                    {rowId === 'total' && figure.value !== null ? (
                      <InfoButton
                        aria-haspopup="dialog"
                        className={tableStyles.explain}
                        label={t('reports.annual.explainColumn', { column: labelOf(column) })}
                        onClick={() => setExplained(column.id)}
                      />
                    ) : null}
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
      {columns.find((column) => column.id === explained) ? (
        <DetailedAggregateModal
          annualReports={annualReports}
          close={() => setExplained(null)}
          column={columns.find((column) => column.id === explained)!}
          label={labelOf(columns.find((column) => column.id === explained)!)}
          quality={report.detailedPeriod[explained!]?.quality ?? report.quality}
        />
      ) : null}
    </div>
  );
}
