import type { PortfolioPosition } from '@cadran/api-client';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { signed } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import {
  isClosedPosition,
  valueReasonLabels,
} from '@/features/portfolio/portfolio-account/positions/positionLabels';
import styles from './PositionsList.module.css';

interface Props {
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  position: PortfolioPosition | null;
  onOpen: (position: PortfolioPosition) => void;
  onReorder: (position: PortfolioPosition) => void;
}

/** Gain or loss with its sign and an icon: colour never carries it alone. */
function ResultBadge({ gain }: { gain: NonNullable<PortfolioPosition['unrealizedGain']> }) {
  const text = signed(formatAmount(gain.value, gain.assetCode, 'fr-FR'), gain.value);
  if (!/[1-9]/.test(gain.value)) return <StatusBadge tone="neutral">{text}</StatusBadge>;
  const loss = gain.value.startsWith('-');

  return (
    <StatusBadge icon={loss ? 'arrow-down' : 'arrow-up'} tone={loss ? 'negative' : 'positive'}>
      {text}
    </StatusBadge>
  );
}

/** One position: instrument, quantity and result on the left, the position value on the right. */
export function PositionRow({ onOpen, onReorder, position }: Props) {
  const closed = position ? isClosedPosition(position) : false;
  const orderDependent = position?.flags.some((flag) => flag.code === 'ORDER_DEPENDENT_DAY');

  return (
    <DatedRow
      dataAttributes={{ 'data-closed': closed || undefined }}
      meta={
        <small className={styles.meta}>
          {position ? (
            <>
              <span>{formatDecimal(position.quantity, 'fr-FR')}</span>
              {position.unrealizedGain ? <ResultBadge gain={position.unrealizedGain} /> : null}
              {closed ? <StatusBadge tone="neutral">Clôturée</StatusBadge> : null}
              {orderDependent ? (
                <button
                  className={styles.warning}
                  onClick={() => onReorder(position)}
                  type="button"
                >
                  <StatusBadge tone="warning">Ordre à vérifier</StatusBadge>
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
          formatAmount(position.marketValue.value, position.marketValue.assetCode, 'fr-FR')
        ) : (
          <EmptyValue label={valueReasonLabels[position.valueReason ?? 'NO_VALUATION']} />
        )
      }
    />
  );
}
