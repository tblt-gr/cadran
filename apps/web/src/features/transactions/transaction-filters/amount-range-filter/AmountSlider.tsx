import { useTranslation } from 'react-i18next';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import {
  LAST_POSITION,
  maxBoundAt,
  minBoundAt,
  positionForMax,
  positionForMin,
} from './amountSteps';
import styles from './AmountSlider.module.css';

interface AmountSliderProps {
  /** Unit used to voice the thresholds; `null` reads them as bare numbers. */
  assetCode: string | null;
  maxAmount: string;
  minAmount: string;
  onChange: (bounds: { maxAmount: string; minAmount: string }) => void;
}

/**
 * Two native range inputs sharing one track, so each thumb keeps the platform keyboard and
 * assistive behaviour. The thumbs never cross: moving one past the other stops it there.
 */
export function AmountSlider({ assetCode, maxAmount, minAmount, onChange }: AmountSliderProps) {
  const { i18n, t } = useTranslation();
  const minPosition = positionForMin(minAmount);
  const maxPosition = positionForMax(maxAmount);

  function voice(bound: string, empty: string): string {
    if (bound === '') return empty;
    return assetCode === null
      ? formatDecimal(bound, i18n.language)
      : formatAmount(bound, assetCode, i18n.language);
  }

  // Geometry only: the share of the track between the thumbs, never a money value.
  const fill = {
    insetInlineStart: `${(minPosition / LAST_POSITION) * 100}%`,
    insetInlineEnd: `${100 - (maxPosition / LAST_POSITION) * 100}%`,
  };

  return (
    <div className={styles.slider}>
      <span aria-hidden="true" className={styles.track}>
        <span className={styles.fill} style={fill} />
      </span>
      <input
        aria-label={t('transactions.filters.minSlider')}
        aria-valuetext={voice(minBoundAt(minPosition), t('transactions.filters.noMinimum'))}
        className={styles.thumb}
        max={LAST_POSITION}
        min={0}
        onChange={(event) => {
          const position = Math.min(Number(event.target.value), maxPosition, LAST_POSITION - 1);
          onChange({ maxAmount, minAmount: minBoundAt(position) });
        }}
        step={1}
        type="range"
        value={minPosition}
      />
      <input
        aria-label={t('transactions.filters.maxSlider')}
        aria-valuetext={voice(maxBoundAt(maxPosition), t('transactions.filters.noMaximum'))}
        className={styles.thumb}
        max={LAST_POSITION}
        min={0}
        onChange={(event) => {
          const position = Math.max(Number(event.target.value), minPosition, 1);
          onChange({ maxAmount: maxBoundAt(position), minAmount });
        }}
        step={1}
        type="range"
        value={maxPosition}
      />
    </div>
  );
}
