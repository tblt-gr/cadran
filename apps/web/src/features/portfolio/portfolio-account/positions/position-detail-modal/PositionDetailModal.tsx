import type { DecimalAmount, PortfolioPosition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import { valueReasonLabel } from '@/features/portfolio/portfolio-account/positions/positionLabels';
import styles from './PositionDetailModal.module.css';

interface Props {
  onClose: () => void;
  onLots: (position: PortfolioPosition) => void;
  position: PortfolioPosition;
}

/** The figures a position row leaves out: costs, result, lot count and the way to the lots. */
export function PositionDetailModal({ onClose, onLots, position }: Props) {
  const { t, i18n } = useTranslation();
  const unknownCost = position.costReason === 'UNKNOWN_LOT_COST';

  return (
    <Modal intent="detail" close={onClose} title={position.instrument.name}>
      <div className={styles.content}>
        <p>
          <InstrumentLink instrument={position.instrument} />
        </p>
        <dl className={styles.figures}>
          <div>
            <dt>{t('portfolio.positions.quantity')}</dt>
            <dd>{formatDecimal(position.quantity, i18n.language)}</dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.detail.avgCost')}</dt>
            <dd>
              <CostValue amount={position.averageUnitCost} unknown={unknownCost} />
            </dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.detail.totalCost')}</dt>
            <dd>
              <CostValue amount={position.averageCostBasis} badge unknown={unknownCost} />
            </dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.detail.value')}</dt>
            <dd>
              {position.marketValue ? (
                formatAmount(
                  position.marketValue.value,
                  position.marketValue.assetCode,
                  i18n.language,
                )
              ) : (
                <EmptyValue label={valueReasonLabel(t, position.valueReason ?? 'NO_VALUATION')} />
              )}
            </dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.detail.result')}</dt>
            <dd>
              <UnrealizedGain
                assetCode={position.unrealizedGain?.assetCode ?? ''}
                value={position.unrealizedGain?.value ?? null}
              />
            </dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.detail.openLots')}</dt>
            <dd>{position.openLotCount}</dd>
          </div>
        </dl>
        <div>
          <button className="secondary-action" onClick={() => onLots(position)} type="button">
            {t('portfolio.positions.detail.viewLots')}
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
  const { t, i18n } = useTranslation();
  if (!unknown && amount) return formatAmount(amount.value, amount.assetCode, i18n.language);
  if (!unknown) return <EmptyValue label={t('states.notCalculable.label')} />;

  return (
    <span className={styles.unknown}>
      <EmptyValue label={t('portfolio.positions.unknownCost')} />
      {badge ? (
        <StatusBadge tone="warning">{t('portfolio.positions.unknownCost')}</StatusBadge>
      ) : null}
    </span>
  );
}
