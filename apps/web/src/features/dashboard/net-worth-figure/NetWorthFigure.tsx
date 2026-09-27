import type { NetWorthAmount, NetWorthReason } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import styles from './NetWorthFigure.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface NetWorthFigureProps {
  amount: NetWorthAmount | null;
  className?: string;
  reason: NetWorthReason | null;
}

/**
 * A backend-owned figure, or the reason there is none.
 *
 * The interface never adds, divides or rounds an amount: it prints the display
 * string the API produced. An absent figure states why it is absent and is
 * never shown as `0`.
 */
export function NetWorthFigure({ amount, className, reason }: NetWorthFigureProps) {
  const { i18n, t } = useTranslation();

  if (amount === null) {
    return (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={reason ? t(`dashboard.netWorth.reasons.${reason}`) : null}
      />
    );
  }

  if (amount.belowDisplayStep) {
    return (
      <span className={`${styles.unavailable} ${className ?? ''}`}>
        {t('dashboard.netWorth.belowStep')}
      </span>
    );
  }

  // An asset the reference does not know carries no display precision. The
  // exact figure is still a figure, so it is shown as recorded rather than
  // mistaken for one too small to display.
  const shown = amount.display ?? { value: amount.value, assetCode: amount.assetCode };

  return (
    <MoneyValue
      className={className}
      value={formatAmount(shown.value, shown.assetCode, i18n.language)}
    />
  );
}
