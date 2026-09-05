import type { NetWorthDelta as Delta } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { deltaDirection } from './deltaDirection';
import styles from './NetWorthDelta.module.css';

const ACCESSIBLE_LABEL = {
  down: 'dashboard.netWorth.decreaseAccessible',
  flat: 'dashboard.netWorth.stableAccessible',
  up: 'dashboard.netWorth.increaseAccessible',
} as const;

interface NetWorthDeltaProps {
  delta: Delta;
}

/**
 * The movement since the compared day, and its rate when one exists.
 *
 * The two fail apart: a real movement measured against a zero or negative
 * base keeps its amount and states why no percentage follows. The direction
 * is read from the sign of the canonical string, never from a `Number`, and
 * is carried by an accessible label as well as by the arrow and the colour.
 */
export function NetWorthDelta({ delta }: NetWorthDeltaProps) {
  const { i18n, t } = useTranslation();
  const since = t('dashboard.netWorth.deltaPeriod', {
    period: formatCalendarDay(delta.comparedOn, i18n.language),
  });

  if (delta.amount === null) {
    return (
      <p className={styles.delta}>
        <span className={styles.unavailable}>
          {t('states.notCalculable.label')}
          {delta.amountReason
            ? ` · ${t(`dashboard.netWorth.reasons.${delta.amountReason}`)}`
            : null}
        </span>
        <span>{since}</span>
      </p>
    );
  }

  const direction = deltaDirection(delta.amount.value);
  const shown = delta.amount.display ?? {
    value: delta.amount.value,
    assetCode: delta.amount.assetCode,
  };
  const figure = formatAmount(shown.value, shown.assetCode, i18n.language);
  // The label names the direction in words, so the amount inside it stays
  // unsigned: "decrease of minus 1 740 euros" reads as a double negative.
  const spoken = formatAmount(shown.value.replace(/^-/, ''), shown.assetCode, i18n.language);

  return (
    <p className={styles.delta}>
      <span
        aria-label={t(ACCESSIBLE_LABEL[direction], { amount: spoken })}
        className={styles[direction]}
      >
        {direction === 'flat' ? null : (
          <Icon name={direction === 'down' ? 'arrow-down' : 'arrow-up'} size={16} />
        )}
        <span aria-hidden="true">{figure}</span>
      </span>
      {delta.ratePercentDisplay === null ? (
        <span className={styles.unavailable}>
          {delta.rateReason ? t(`dashboard.netWorth.rateReasons.${delta.rateReason}`) : null}
        </span>
      ) : (
        <span className={styles.rate}>{formatSharePercent(delta.ratePercentDisplay)}</span>
      )}
      <span>{since}</span>
    </p>
  );
}
