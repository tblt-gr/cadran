import type { AnnualColumn, AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { AnnualCellValue } from '@/features/reports/annual/AnnualCellValue';
import { annualCellHref } from '@/features/reports/annual/annualColumns';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import styles from '@/features/reports/annual/AggregateExplainModal.module.css';

interface Props {
  annualReports: AnnualReport[];
  close: () => void;
  column: AnnualColumn;
  label: string;
  quality: string;
}

/** Exposes the monthly sources of the entire selected period, rather than one annual total. */
export function DetailedAggregateModal({ annualReports, close, column, label, quality }: Props) {
  const { t, i18n } = useTranslation();
  return (
    <Modal close={close} intent="detail" title={label}>
      <div className={styles.body}>
        <p>
          {t('reports.multiYear.aggregatePeriod', {
            from: annualReports[0]?.year,
            to: annualReports.at(-1)?.year,
          })}
        </p>
        <dl className={styles.facts}>
          <dt>{t('reports.formula')}</dt>
          <dd>{t(`reports.annual.formulas.${column.kind}`)}</dd>
          <dt>{t('reports.quality')}</dt>
          <dd>{t(`reports.multiYear.aggregateQuality.${quality}`, { defaultValue: quality })}</dd>
          <dt>{t('reports.annual.policy')}</dt>
          <dd>
            {annualReports
              .map(
                (annual) =>
                  `${annual.year} : ${annual.metricPolicy.label ?? t('reports.multiYear.aggregateMixedPolicy')} (${annual.metricPolicy.versions.map((version) => `v${version}`).join(', ')})`,
              )
              .join(' ; ')}
          </dd>
        </dl>
        <p>{t('reports.multiYear.totalIncludesProvisional')}</p>
        <table className={styles.months}>
          <caption>{t('reports.annual.explainMonths')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('reports.annual.month')}</th>
              <th scope="col">{t('reports.value')}</th>
            </tr>
          </thead>
          <tbody>
            {annualReports.flatMap((annual) =>
              annual.rows.map((row) => {
                const cell = row.cells[column.id];
                return (
                  <tr key={row.month}>
                    <th scope="row">
                      {monthLabel(row.month, i18n.language)} {annual.year}
                    </th>
                    <td>
                      <AnnualCellValue
                        assetCode={column.assetCode}
                        href={annualCellHref(column.id, row.month)}
                        kind={column.kind}
                        reason={cell?.reason ?? null}
                        value={cell?.value ?? null}
                      />
                    </td>
                  </tr>
                );
              }),
            )}
          </tbody>
        </table>
      </div>
    </Modal>
  );
}
