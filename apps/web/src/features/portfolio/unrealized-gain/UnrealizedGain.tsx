import { areAmountsHidden } from '@/lib/amountPrivacy';
import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { signedPresentation, formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import styles from './UnrealizedGain.module.css';

export type GainReason =
  PortfolioOverviewAccountCard['unrealizedGain']['reason'] | 'INCOMPLETE_GAIN' | 'MIXED_ASSETS';

/** The sign is always written, so a gain and a loss differ without colour. */
export function signed(text: string, raw: string): string {
  return signedPresentation(text, raw).text;
}

/** Unrealized gain amount and its performance since the start; a missing figure is a dash, never zero. */
export function UnrealizedGain({
  assetCode,
  performance,
  reason = null,
  value,
}: {
  assetCode: string;
  /** Omitted where no ratio is offered; `null` still says it cannot be calculated. */
  performance?: string | null;
  reason?: GainReason;
  value: string | null;
}) {
  const { t, i18n } = useTranslation();
  if (value === null) {
    return (
      <EmptyValue
        label={
          reason === null
            ? t('investments.table.gainNotCalculable')
            : t(`portfolio.unrealizedGain.reasons.${reason}`)
        }
      />
    );
  }
  const sign = signedPresentation('', value).sign;
  const tone = sign < 0 ? styles.loss : sign > 0 ? styles.gain : '';

  return (
    <span className={`${styles.figures} ${tone}`}>
      <MoneyValue
        wrapGroups={false}
        value={signed(formatAmount(value, assetCode, i18n.language), value)}
      />
      {performance === undefined ? null : performance === null ? (
        <EmptyValue label={t('portfolio.unrealizedGain.performanceNotCalculable')} />
      ) : (
        <MoneyValue
          wrapGroups={false}
          value={
            areAmountsHidden()
              ? signedPresentation('', performance).text
              : `(${signed(formatRatioPercentage(performance, i18n.language), performance)})`
          }
        />
      )}
    </span>
  );
}
