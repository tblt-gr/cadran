import type { DecimalAmount, MonthlyPortfolioCosts } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, isZeroDecimal } from '@/lib/decimal';
import styles from './PortfolioCostsCard.module.css';

/**
 * Informative fees and taxes of the portfolio accounts for the month. Never part of a budget
 * total: the portfolio value already contains them.
 */
export function PortfolioCostsCard({ costs }: { costs: MonthlyPortfolioCosts }) {
  const { i18n, t } = useTranslation();
  const money = (amount: DecimalAmount) => (
    <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
  );
  const rowMoney = (value: string, assetCode: string) => money({ value, assetCode });
  const total = (amount: DecimalAmount | null) =>
    amount ? (
      money(amount)
    ) : (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={t('budget.monthly.recap.portfolioCosts.mixedAssets')}
      />
    );
  const nothing = costs.items.every(
    (item) => isZeroDecimal(item.fees.value) && isZeroDecimal(item.taxes.value),
  );

  return (
    <section className={`card ${styles.panel}`}>
      <header>
        <h3>{t('budget.monthly.recap.portfolioCosts.title')}</h3>
        <p>{t('budget.monthly.recap.portfolioCosts.subtitle')}</p>
      </header>
      {nothing ? (
        <p className={styles.empty}>{t('budget.monthly.recap.portfolioCosts.empty')}</p>
      ) : (
        <div className={styles.tableScroll}>
          <table>
            <thead>
              <tr>
                <th scope="col">{t('budget.monthly.recap.portfolioCosts.account')}</th>
                <th className={styles.number} scope="col">
                  {t('budget.monthly.recap.portfolioCosts.fees')}
                </th>
                <th className={styles.number} scope="col">
                  {t('budget.monthly.recap.portfolioCosts.taxes')}
                </th>
              </tr>
            </thead>
            <tbody>
              {costs.items.map((item) => {
                const href = `/portfolios/${item.accountId}?tab=cash`;
                return (
                  <tr key={item.accountId}>
                    <th scope="row">
                      <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
                        {item.label}
                      </a>
                    </th>
                    <td className={styles.number}>{rowMoney(item.fees.value, item.assetCode)}</td>
                    <td className={styles.number}>{rowMoney(item.taxes.value, item.assetCode)}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <th scope="row">{t('budget.monthly.recap.portfolioCosts.total')}</th>
                <td className={styles.number}>{total(costs.totalFees)}</td>
                <td className={styles.number}>{total(costs.totalTaxes)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}
    </section>
  );
}
