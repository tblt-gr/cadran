import { areAmountsHidden } from '@/lib/amountPrivacy';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { PortfolioOperationLeg } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount, formatFinancialDecimal, isZeroDecimal } from '@/lib/decimal';
import styles from './OperationDetailModal.module.css';

export function OperationLegsTable({
  legs,
  caption,
}: {
  legs: PortfolioOperationLeg[];
  caption?: string;
}) {
  const { i18n, t } = useTranslation();
  const title = caption ?? t('portfolio.account.legs.caption');
  return (
    <div className={styles.scroll} role="region" aria-label={title} tabIndex={0}>
      <table>
        <caption>{title}</caption>
        <thead>
          <tr>
            {(['direction', 'support', 'units', 'unitValue', 'amount', 'rounding'] as const).map(
              (column) => (
                <th scope="col" key={column}>
                  {t(`portfolio.account.legs.columns.${column}`)}
                </th>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {legs.map((leg) => (
            <tr key={leg.index}>
              <td>
                {leg.direction === 'IN'
                  ? t('portfolio.account.legs.in')
                  : t('portfolio.account.legs.out')}
              </td>
              <th scope="row">
                {leg.instrument ? (
                  <InstrumentLink instrument={leg.instrument} />
                ) : (
                  <EmptyValue label={t('portfolio.account.legs.unavailable')} />
                )}
              </th>
              <td>
                <MoneyValue value={formatFinancialDecimal(leg.quantity, i18n.language)} />
              </td>
              <td>
                <MoneyValue value={formatFinancialDecimal(leg.unitPrice, i18n.language)} />
              </td>
              <td>
                <MoneyValue
                  value={formatAmount(leg.amount.value, leg.amount.assetCode, i18n.language)}
                />
              </td>
              <td>
                {leg.roundingResidual &&
                (areAmountsHidden() || !isZeroDecimal(leg.roundingResidual.value)) ? (
                  <MoneyValue
                    wrapGroups={false}
                    value={formatAmount(
                      leg.roundingResidual.value,
                      leg.roundingResidual.assetCode,
                      i18n.language,
                    )}
                  />
                ) : (
                  <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {legs.length === 0 ? <p>{t('portfolio.account.legs.none')}</p> : null}
    </div>
  );
}
