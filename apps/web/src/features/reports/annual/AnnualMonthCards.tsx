import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { AnnualCellValue } from './AnnualCellValue';
import { drillDownHref, SUMMARY_ROW_IDS, summaryFigure } from './annualColumns';
import { isEmptyState, monthLabel } from './annualRowLabels';
import styles from './AnnualMonthCards.module.css';

interface AnnualMonthCardsProps {
  onExplain: (columnId: string) => void;
  report: AnnualReport;
}

/** Below 640 px the table becomes one card per month, then one summary card, with the same values. */
export function AnnualMonthCards({ onExplain, report }: AnnualMonthCardsProps) {
  const { t, i18n } = useTranslation();

  return (
    <ul aria-label={t('reports.annual.cardsLabel', { year: report.year })} className={styles.list}>
      {report.rows.map((row) => (
        <li
          className={`card ${styles.card} ${isEmptyState(row.state) ? styles.dimmed : ''}`}
          key={row.month}
        >
          <h3>{monthLabel(row.month, i18n.language)}</h3>
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
              {report.columns.map((column) => (
                <div key={column.id}>
                  <dt>{column.label}</dt>
                  <dd>
                    <AnnualCellValue
                      assetCode={column.assetCode}
                      href={drillDownHref(column.id, row.month)}
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
      <li className={`card ${styles.card}`}>
        <h3>{t('reports.annual.summaryTitle')}</h3>
        {report.columns.map((column) => {
          const aggregate = report.aggregates[column.id];
          if (!aggregate) return null;
          return (
            <section key={column.id}>
              <h4>
                {column.label}
                <InfoButton
                  aria-haspopup="dialog"
                  label={t('reports.annual.explainColumn', { column: column.label })}
                  onClick={() => onExplain(column.id)}
                />
              </h4>
              <dl>
                {SUMMARY_ROW_IDS.map((rowId) => {
                  const figure = summaryFigure(aggregate, rowId);
                  return (
                    <div key={rowId}>
                      <dt>{t(`reports.annual.summary.${rowId}`)}</dt>
                      <dd>
                        <AnnualCellValue
                          assetCode={column.assetCode}
                          kind={column.kind}
                          reason={figure.reason}
                          value={figure.value}
                        />
                        {figure.month ? (
                          <small> ({monthLabel(figure.month, i18n.language)})</small>
                        ) : null}
                      </dd>
                    </div>
                  );
                })}
              </dl>
            </section>
          );
        })}
      </li>
    </ul>
  );
}
