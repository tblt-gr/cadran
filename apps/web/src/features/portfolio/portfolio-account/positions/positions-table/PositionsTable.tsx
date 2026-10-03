import type { DecimalAmount, PortfolioPosition } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import styles from './PositionsTable.module.css';

interface Props {
  items: PortfolioPosition[];
  onLots: (item: PortfolioPosition) => void;
  onReorder: (item: PortfolioPosition) => void;
}

export function PositionsTable({ items, onLots, onReorder }: Props) {
  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll} data-testid="positions-table-scroll">
        <table aria-label="Positions du portefeuille" className={styles.table}>
          <thead>
            <tr>
              <th className={styles.instrument} scope="col">
                Instrument
              </th>
              <th scope="col">Quantité</th>
              <th scope="col">Prix de revient moyen</th>
              <th scope="col">Coût total (moyen)</th>
              <th scope="col">Lots</th>
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => {
              const unknownCost = item.costReason === 'UNKNOWN_LOT_COST';
              const orderDependent = item.flags.some((flag) => flag.code === 'ORDER_DEPENDENT_DAY');

              return (
                <tr key={item.instrument.id}>
                  <th className={styles.instrument} scope="row">
                    <span>{item.instrument.name}</span>
                    {item.instrument.isin ? <small>{item.instrument.isin}</small> : null}
                    {orderDependent ? (
                      <button
                        className={styles.warning}
                        onClick={() => onReorder(item)}
                        type="button"
                      >
                        <StatusBadge tone="warning">Ordre à vérifier</StatusBadge>
                      </button>
                    ) : null}
                  </th>
                  <td className={styles.number}>{formatDecimal(item.quantity, 'fr-FR')}</td>
                  <td className={styles.number}>
                    <CostValue amount={item.averageUnitCost} unknown={unknownCost} />
                  </td>
                  <td className={styles.number}>
                    <CostValue amount={item.averageCostBasis} badge unknown={unknownCost} />
                  </td>
                  <td className={styles.number}>{item.openLotCount}</td>
                  <td>
                    <button className="secondary-action" onClick={() => onLots(item)} type="button">
                      Voir les lots
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function CostValue({
  amount,
  badge = false,
  unknown,
}: {
  amount: DecimalAmount | null;
  badge?: boolean;
  unknown: boolean;
}) {
  if (!unknown && amount) return formatAmount(amount.value, amount.assetCode, 'fr-FR');
  if (!unknown) return <EmptyValue label="Non calculable" />;

  return (
    <span className={styles.unknown}>
      <EmptyValue label="Coût inconnu" />
      {badge ? <StatusBadge tone="warning">Coût inconnu</StatusBadge> : null}
    </span>
  );
}
