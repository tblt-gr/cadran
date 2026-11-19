import { useTranslation } from 'react-i18next';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import type { Summary } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { SupportCards } from './support-cards/SupportCards';
import { supportCells, supportResultTone, useSupportHeaders } from './supportPresentation';
import styles from './SupportsTable.module.css';
export function SupportsTable({ summary }: { summary: Summary | null }) {
  const { t, i18n } = useTranslation();
  const supportHeaders = useSupportHeaders();
  const empty = summary && summary.supports.length === 0;
  return (
    <section className={`card ${styles.card}`} aria-busy={!summary}>
      <h3 className={styles.title}>{t('portfolio.lifeInsurance.supports.title')}</h3>
      {empty ? (
        <p className={styles.empty}>
          {summary.allocation.status === 'AVAILABLE'
            ? t('portfolio.lifeInsurance.supports.noneHeld')
            : t(`portfolio.lifeInsurance.allocation.empty.${summary.allocation.status}`)}
        </p>
      ) : (
        <>
          <div className={styles.desktop}>
            <div
              className={styles.scroll}
              role="region"
              aria-label={t('portfolio.lifeInsurance.supports.detailed')}
              tabIndex={0}
            >
              <table>
                <caption className="sr-only">{t('portfolio.lifeInsurance.supports.title')}</caption>
                <thead>
                  <tr>
                    {supportHeaders.map((label) => (
                      <th key={label} scope="col">
                        {label}
                      </th>
                    ))}
                  </tr>
                </thead>
                {!summary ? (
                  <SkeletonTableRows columns={supportHeaders.length} />
                ) : (
                  <tbody>
                    {summary.supports.map((row) => (
                      <tr key={row.instrumentId}>
                        {supportCells(row, summary.assetCode, t, i18n.language).map(
                          (cell, index) =>
                            index === 0 ? (
                              <th scope="row" key={index}>
                                <InstrumentLink
                                  instrument={{ id: row.instrumentId, name: row.name }}
                                />
                              </th>
                            ) : (
                              <td
                                className={[
                                  index >= 2 ? 'money' : '',
                                  index === 8 ? (styles[supportResultTone(row) ?? ''] ?? '') : '',
                                ]
                                  .filter(Boolean)
                                  .join(' ')}
                                key={index}
                              >
                                {cell}
                              </td>
                            ),
                        )}
                      </tr>
                    ))}
                  </tbody>
                )}
              </table>
            </div>
          </div>
          <SupportCards summary={summary} />
        </>
      )}
    </section>
  );
}
