import type { DecimalAmount, PortfolioValuationHistoryPoint } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { detailLabel, summaryQualityLabel } from '@/features/portfolio/valuations/valuationLabels';
import { exactAmount, missingReason, pointWarnings } from './valueHistoryLabels';
import styles from './ValueHistoryTable.module.css';

export const VALUE_HISTORY_COLUMNS = [
  'Date',
  'Valeur totale',
  'Titres',
  'Espèces',
  'Saisie',
  'Qualité',
];

/** The chart's figures as a table, newest first; a missing figure is a greyed dash with its reason. */
export function ValueHistoryTable({
  locale,
  points,
}: {
  locale: string;
  points: PortfolioValuationHistoryPoint[];
}) {
  const money = (amount: DecimalAmount | null, point: PortfolioValuationHistoryPoint) =>
    amount === null ? (
      <EmptyValue label={missingReason(point)} />
    ) : (
      <MoneyValue value={exactAmount(amount, locale)} />
    );

  return (
    <div
      className={styles.scroll}
      role="region"
      aria-label="Valorisations du portefeuille par date"
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">Valorisations du portefeuille par date</caption>
        <thead>
          <tr>
            {VALUE_HISTORY_COLUMNS.map((text) => (
              <th key={text} scope="col">
                {text}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {[...points].reverse().map((point) => {
            const quality = summaryQualityLabel[point.priceQuality];
            return (
              <tr
                className={point.totalValue === null ? styles.gap : undefined}
                key={point.valuationId}
              >
                <th scope="row">{formatCalendarNumericDay(point.valuedOn)}</th>
                <td>{money(point.totalValue, point)}</td>
                <td>{money(point.securitiesValue, point)}</td>
                <td>{money(point.cash, point)}</td>
                <td>{detailLabel[point.detail]}</td>
                <td>
                  <span className={styles.quality}>
                    {quality ?? <EmptyValue label="Non détaillé" />}
                    {pointWarnings(point).map((warning) => (
                      <StatusBadge key={warning} tone="warning">
                        {warning}
                      </StatusBadge>
                    ))}
                  </span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
