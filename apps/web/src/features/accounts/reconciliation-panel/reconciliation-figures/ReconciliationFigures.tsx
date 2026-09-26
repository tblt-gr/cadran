import type { AccountReconciliation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { compareDecimals, formatAmount } from '@/lib/decimal';
import styles from './ReconciliationFigures.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface ReconciliationFiguresProps {
  reconciliation: AccountReconciliation;
}

/**
 * The comparison of an observed closing balance with the movements of its
 * period. A figure the server could not compute is a stated reason, never a
 * zero; the discrepancy sign is written in words as well as in the figure.
 */
export function ReconciliationFigures({ reconciliation }: ReconciliationFiguresProps) {
  const { i18n, t } = useTranslation();
  const money = (amount: { value: string; assetCode: string } | null) =>
    amount === null ? (
      <EmptyValue label={t('accounts.reconciliation.missing')} />
    ) : (
      <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
    );
  const { discrepancy, nonCalculableReason } = reconciliation;
  const direction = discrepancy === null ? null : compareDecimals(discrepancy.value, '0');

  return (
    <div className={styles.figures}>
      {nonCalculableReason !== null ? (
        <p className={styles.reason} role="status">
          {t(`accounts.reconciliation.reasons.${nonCalculableReason}`)}
        </p>
      ) : null}
      <dl className={styles.list}>
        <div>
          <dt>{t('accounts.reconciliation.opening')}</dt>
          <dd>{money(reconciliation.openingBalance)}</dd>
        </div>
        <div>
          <dt>{t('accounts.reconciliation.movements')}</dt>
          <dd>{money(reconciliation.movementsTotal)}</dd>
        </div>
        <div>
          <dt>{t('accounts.reconciliation.closing')}</dt>
          <dd>{money(reconciliation.closingBalance)}</dd>
        </div>
        {discrepancy === null ? null : (
          <div className={styles.discrepancy}>
            <dt>{t('accounts.reconciliation.discrepancy')}</dt>
            <dd data-testid="discrepancy">
              {money(discrepancy)}
              {' · '}
              {t(
                direction === 0
                  ? 'accounts.reconciliation.direction.none'
                  : direction === 1
                    ? 'accounts.reconciliation.direction.surplus'
                    : 'accounts.reconciliation.direction.shortfall',
              )}
            </dd>
          </div>
        )}
      </dl>
    </div>
  );
}
