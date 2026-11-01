import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import styles from './UnrealizedGain.module.css';

export type GainReason =
  PortfolioOverviewAccountCard['unrealizedGain']['reason'] | 'INCOMPLETE_GAIN' | 'MIXED_ASSETS';

const REASON_LABEL: Record<NonNullable<GainReason>, string> = {
  NO_VALUATION: 'Aucune valorisation',
  PARTIAL_VALUATION: 'Valorisation partielle',
  UNKNOWN_LOT_COST: 'Coût d’acquisition inconnu',
  INCOMPLETE_GAIN: 'Plus-value incomplète',
  MIXED_ASSETS: 'Actifs différents',
};

/** The sign is always written, so a gain and a loss differ without colour. */
function signed(text: string, raw: string): string {
  if (!/[1-9]/.test(text)) return text;
  return raw.startsWith('-') ? text : `+${text}`;
}

/** Unrealized gain amount and its performance since the start; a missing figure is a dash, never zero. */
export function UnrealizedGain({
  assetCode,
  performance,
  reason = null,
  value,
}: {
  assetCode: string;
  performance: string | null;
  reason?: GainReason;
  value: string | null;
}) {
  if (value === null) {
    return (
      <EmptyValue label={reason === null ? 'Plus-value non calculable' : REASON_LABEL[reason]} />
    );
  }
  const tone = value.startsWith('-') ? styles.loss : styles.gain;

  return (
    <span className={`${styles.figures} ${tone}`}>
      <span>{signed(formatAmount(value, assetCode, 'fr'), value)}</span>
      {performance === null ? (
        <EmptyValue label="Performance non calculable" />
      ) : (
        <span>({signed(formatRatioPercentage(performance, 'fr'), performance)})</span>
      )}
    </span>
  );
}
