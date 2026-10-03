import type { PortfolioCash } from '@cadran/api-client';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { useTranslation } from 'react-i18next';
import styles from './CashMovementsTable.module.css';

/** Running cash movements in `(tradeDate, sequence)` order; `null` renders the loading layout. */
export function CashMovementsTable({ cash }: { cash: PortfolioCash | null }) {
  const { i18n } = useTranslation();
  if (cash && cash.movements.length === 0) {
    return <OperationsState title="Aucun mouvement d’espèces sur la période" />;
  }

  return (
    <section className={`card ${styles.panel}`}>
      {cash ? null : <SkeletonStatus>Chargement des espèces…</SkeletonStatus>}
      <div className={styles.scroll} data-testid="cash-movements-scroll">
        <table aria-label="Mouvements d’espèces" className={styles.table}>
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Type</th>
              <th className={styles.number} scope="col">
                Effet espèces
              </th>
              <th className={styles.number} scope="col">
                Solde après
              </th>
            </tr>
          </thead>
          {cash ? (
            <tbody>
              {cash.movements.map((movement) => (
                <tr key={movement.operationId}>
                  <th scope="row">{formatCalendarNumericDay(movement.tradeDate)}</th>
                  <td>{operationTypeLabel(movement.type)}</td>
                  <td className={styles.number}>
                    <CashAmount amount={movement.cashAmount} />
                  </td>
                  <td className={styles.number}>
                    <MoneyValue
                      value={formatAmount(
                        movement.runningBalance.value,
                        movement.runningBalance.assetCode,
                        i18n.language,
                      )}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          ) : (
            <SkeletonTableRows columns={4} rows={6} />
          )}
        </table>
      </div>
    </section>
  );
}
