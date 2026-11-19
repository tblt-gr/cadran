import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import type { DecimalAmount, PortfolioValuationHistoryPoint } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  detailLabel,
  exactAmount,
  missingReason,
  pointWarnings,
  priceQualityLabel,
} from './valueHistoryLabels';
import styles from './ValueHistoryTable.module.css';

export const VALUE_HISTORY_COLUMNS = [
  'date',
  'total',
  'securities',
  'cash',
  'entry',
  'quality',
] as const;

export function valueHistoryColumnLabel(
  t: TFunction,
  column: (typeof VALUE_HISTORY_COLUMNS)[number],
): string {
  return t(`portfolio.account.history.columns.${column}`);
}

/** The chart's figures as a table, newest first; a missing figure is a greyed dash with its reason. */
export function ValueHistoryTable({
  locale,
  points,
}: {
  locale: string;
  points: PortfolioValuationHistoryPoint[];
}) {
  const { t } = useTranslation();
  const money = (amount: DecimalAmount | null, point: PortfolioValuationHistoryPoint) =>
    amount === null ? (
      <EmptyValue label={missingReason(t, point)} />
    ) : (
      <MoneyValue value={exactAmount(amount, locale)} />
    );

  return (
    <div
      className={styles.scroll}
      role="region"
      aria-label={t('portfolio.account.history.tableLabel')}
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">{t('portfolio.account.history.tableLabel')}</caption>
        <thead>
          <tr>
            {VALUE_HISTORY_COLUMNS.map((column) => (
              <th key={column} scope="col">
                {valueHistoryColumnLabel(t, column)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {[...points].reverse().map((point) => {
            const quality = priceQualityLabel(t, point.priceQuality);
            return (
              <tr
                className={point.totalValue === null ? styles.gap : undefined}
                key={point.valuationId}
              >
                <th scope="row">{formatCalendarNumericDay(point.valuedOn)}</th>
                <td>{money(point.totalValue, point)}</td>
                <td>{money(point.securitiesValue, point)}</td>
                <td>{money(point.cash, point)}</td>
                <td>{detailLabel(t, point.detail)}</td>
                <td>
                  <span className={styles.quality}>
                    {quality ?? <EmptyValue label={t('portfolio.account.history.notDetailed')} />}
                    {pointWarnings(t, point).map((warning) => (
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
