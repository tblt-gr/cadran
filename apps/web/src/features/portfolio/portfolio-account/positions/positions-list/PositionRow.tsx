import type { PortfolioPosition } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { DatedCell } from '@/components/ui/dated-list/DatedCell';
import { Icon } from '@/components/ui/icon/Icon';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { signed } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import {
  isClosedPosition,
  valueReasonLabel,
} from '@/features/portfolio/portfolio-account/positions/positionLabels';
import styles from './PositionsList.module.css';

interface Props {
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  position: PortfolioPosition | null;
  onOpen: (position: PortfolioPosition) => void;
  onReorder: (position: PortfolioPosition) => void;
}

/** Gain or loss with its sign and an icon: colour never carries it alone. Plain text keeps rows one line high. */
function ResultText({
  className,
  gain,
}: {
  className: string;
  gain: NonNullable<PortfolioPosition['unrealizedGain']>;
}) {
  const { i18n } = useTranslation();
  const text = signed(formatAmount(gain.value, gain.assetCode, i18n.language), gain.value);
  if (!/[1-9]/.test(gain.value)) return <span className={className}>{text}</span>;
  const loss = gain.value.startsWith('-');

  return (
    <span className={`${className} ${loss ? styles.loss : styles.gain}`}>
      <Icon name={loss ? 'arrow-down' : 'arrow-up'} size={12} />
      {text}
    </span>
  );
}

function Figure({ amount, label }: { amount: ReactNode; label: string }) {
  return (
    <span className={styles.figure}>
      <span>{label}</span> {amount}
    </span>
  );
}

function costOf(
  position: PortfolioPosition,
  amount: PortfolioPosition['averageCostBasis'],
  t: TFunction,
  locale: string,
) {
  if (position.costReason === 'UNKNOWN_LOT_COST')
    return <EmptyValue label={t('portfolio.positions.unknownCost')} />;

  return amount ? (
    formatAmount(amount.value, amount.assetCode, locale)
  ) : (
    <EmptyValue label={t('states.notCalculable.label')} />
  );
}

/**
 * One position: instrument on the left, then quantity, average cost, total cost, result and
 * value. A phone keeps quantity and average cost under the name and the value, or the total cost
 * when no value can be computed, on the right.
 */
export function PositionRow({ onOpen, onReorder, position }: Props) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const closed = position ? isClosedPosition(position) : false;
  const orderDependent = position?.flags.some((flag) => flag.code === 'ORDER_DEPENDENT_DAY');
  const unitCost = position ? costOf(position, position.averageUnitCost, t, locale) : null;
  const totalCost = position ? costOf(position, position.averageCostBasis, t, locale) : null;
  const fallbackCost =
    position && position.costReason !== 'UNKNOWN_LOT_COST' ? position.averageCostBasis : null;
  const reason = position ? valueReasonLabel(t, position.valueReason ?? 'NO_VALUATION') : '';

  return (
    <DatedRow
      aside={
        position ? (
          position.unrealizedGain ? (
            <ResultText className={styles.result} gain={position.unrealizedGain} />
          ) : (
            <EmptyValue label={t('investments.table.gainNotCalculable')} />
          )
        ) : (
          <SkeletonBlock />
        )
      }
      cells={
        <>
          <DatedCell column={1}>
            {position ? (
              <Figure
                amount={formatDecimal(position.quantity, locale)}
                label={t('portfolio.positions.quantity')}
              />
            ) : (
              <SkeletonBlock />
            )}
          </DatedCell>
          <DatedCell column={2}>
            {position ? (
              <Figure amount={unitCost} label={t('portfolio.positions.avgUnitCostShort')} />
            ) : (
              <SkeletonBlock />
            )}
          </DatedCell>
          <DatedCell column={3}>
            {position ? (
              <Figure amount={totalCost} label={t('portfolio.positions.cost')} />
            ) : (
              <SkeletonBlock />
            )}
          </DatedCell>
        </>
      }
      dataAttributes={{ 'data-closed': closed || undefined }}
      meta={
        <small className={styles.meta}>
          {position ? (
            <>
              <span className={styles.phoneFigures}>
                {formatDecimal(position.quantity, locale)}
                {position.averageUnitCost
                  ? t('portfolio.positions.phoneAvgCost', {
                      amount: formatAmount(
                        position.averageUnitCost.value,
                        position.averageUnitCost.assetCode,
                        locale,
                      ),
                    })
                  : null}
              </span>
              {closed ? (
                <StatusBadge tone="neutral">{t('portfolio.positions.closed')}</StatusBadge>
              ) : null}
              {orderDependent ? (
                <button
                  className={styles.warning}
                  onClick={() => onReorder(position)}
                  type="button"
                >
                  <StatusBadge tone="warning">{t('portfolio.positions.checkOrder')}</StatusBadge>
                </button>
              ) : null}
            </>
          ) : (
            <SkeletonBlock />
          )}
        </small>
      }
      onOpen={position ? () => onOpen(position) : undefined}
      state={closed ? { tone: 'muted', cue: null } : undefined}
      title={
        <button
          className={styles.name}
          disabled={!position}
          onClick={() => position && onOpen(position)}
          title={position?.instrument.name}
          type="button"
        >
          {position?.instrument.name ?? <SkeletonBlock />}
        </button>
      }
      value={
        !position ? (
          <SkeletonBlock />
        ) : position.marketValue ? (
          <span className={styles.valueStack}>
            <span>
              {formatAmount(position.marketValue.value, position.marketValue.assetCode, locale)}
            </span>
            {position.unrealizedGain ? (
              <ResultText className={styles.phoneResult} gain={position.unrealizedGain} />
            ) : null}
          </span>
        ) : (
          <>
            <span className={fallbackCost ? styles.emptyValue : undefined}>
              <EmptyValue label={reason} />
            </span>
            {fallbackCost ? (
              <span className={styles.costFallback} title={reason}>
                <span>{t('portfolio.positions.cost')}</span>{' '}
                {formatAmount(fallbackCost.value, fallbackCost.assetCode, locale)}
                <span className="sr-only">{reason}</span>
              </span>
            ) : null}
          </>
        )
      }
    />
  );
}
