import type { MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { MultiYearCellValue } from './MultiYearCellValue';
import { MULTI_YEAR_SUMMARY_ROW_IDS, multiYearSummaryFigure } from './multiYearSummary';
import { multiYearYearNotes } from './multiYearYearNotes';
import styles from './MultiYearCards.module.css';

interface MultiYearCardsProps {
  report: MultiYearReport;
}

/**
 * Below 640 px the table becomes one card per year, with the same values, the
 * same partial-year and mixed-snapshot cues, and a trailing summary card.
 */
export function MultiYearCards({ report }: MultiYearCardsProps) {
  const { t, i18n } = useTranslation();

  return (
    <ul aria-label={t('reports.multiYear.cardsLabel')} className={styles.list}>
      {report.years.map((year) => {
        const href = `/budget/${year.year}`;
        const notes = multiYearYearNotes(year);

        return (
          <li
            className={`card ${styles.card} ${year.state === 'NO_DATA' ? styles.dimmed : ''}`}
            key={year.year}
          >
            <h3>
              <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
                {year.year}
              </a>
            </h3>
            <p>
              {year.metricPolicy.state === 'MIXED'
                ? t('reports.multiYear.mixedPolicy')
                : `v${year.metricPolicy.version}`}
              {notes.map((note) => (
                <small className={styles.meta} key={note.key}>
                  {' '}
                  {t(note.key, note.params)}
                </small>
              ))}
            </p>
            <dl>
              {report.columns.map((column) => (
                <div key={column.id}>
                  <dt>{column.label}</dt>
                  <dd>
                    <MultiYearCellValue
                      assetCode={column.assetCode}
                      cell={year.cells[column.id]!}
                      kind={column.kind}
                    />
                  </dd>
                </div>
              ))}
            </dl>
          </li>
        );
      })}
      <li className={`card ${styles.card}`}>
        <h3>{t('reports.multiYear.summary.average')}</h3>
        {report.columns.map((column) => {
          const summary = report.summary[column.id];
          if (!summary) return null;

          return (
            <section key={column.id}>
              <h4>{column.label}</h4>
              <dl>
                {MULTI_YEAR_SUMMARY_ROW_IDS.map((rowId) => {
                  const figure = multiYearSummaryFigure(summary, rowId);
                  return (
                    <div key={rowId}>
                      <dt>{t(`reports.multiYear.summary.${rowId}`)}</dt>
                      <dd>
                        {figure.value === null ? (
                          <EmptyValue
                            label={t('reports.annual.notCalculable')}
                            reason={
                              figure.reason
                                ? t(`reports.multiYear.reasons.${figure.reason}`, {
                                    defaultValue: figure.reason,
                                  })
                                : null
                            }
                          />
                        ) : (
                          <>
                            {formatAnnualValue(
                              figure.value,
                              column.kind,
                              column.assetCode,
                              i18n.language,
                            )}
                            {figure.year !== null ? <small> ({figure.year})</small> : null}
                          </>
                        )}
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
