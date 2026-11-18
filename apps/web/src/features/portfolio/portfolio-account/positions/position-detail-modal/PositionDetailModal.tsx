import type { DecimalAmount, PortfolioPosition } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import { valueReasonLabels } from '@/features/portfolio/portfolio-account/positions/positionLabels';
import styles from './PositionDetailModal.module.css';

interface Props {
  onClose: () => void;
  onLots: (position: PortfolioPosition) => void;
  position: PortfolioPosition;
}

/** The figures a position row leaves out: costs, result, lot count and the way to the lots. */
export function PositionDetailModal({ onClose, onLots, position }: Props) {
  const unknownCost = position.costReason === 'UNKNOWN_LOT_COST';

  return (
    <Modal intent="detail" close={onClose} title={position.instrument.name}>
      <div className={styles.content}>
        <p>
          <InstrumentLink instrument={position.instrument} />
        </p>
        <dl className={styles.figures}>
          <div>
            <dt>Quantité</dt>
            <dd>{formatDecimal(position.quantity, 'fr-FR')}</dd>
          </div>
          <div>
            <dt>Prix de revient moyen</dt>
            <dd>
              <CostValue amount={position.averageUnitCost} unknown={unknownCost} />
            </dd>
          </div>
          <div>
            <dt>Coût total (moyen)</dt>
            <dd>
              <CostValue amount={position.averageCostBasis} badge unknown={unknownCost} />
            </dd>
          </div>
          <div>
            <dt>Valeur</dt>
            <dd>
              {position.marketValue ? (
                formatAmount(position.marketValue.value, position.marketValue.assetCode, 'fr-FR')
              ) : (
                <EmptyValue label={valueReasonLabels[position.valueReason ?? 'NO_VALUATION']} />
              )}
            </dd>
          </div>
          <div>
            <dt>Résultat</dt>
            <dd>
              <UnrealizedGain
                assetCode={position.unrealizedGain?.assetCode ?? ''}
                value={position.unrealizedGain?.value ?? null}
              />
            </dd>
          </div>
          <div>
            <dt>Lots ouverts</dt>
            <dd>{position.openLotCount}</dd>
          </div>
        </dl>
        <div>
          <button className="secondary-action" onClick={() => onLots(position)} type="button">
            Voir les lots
          </button>
        </div>
      </div>
    </Modal>
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
