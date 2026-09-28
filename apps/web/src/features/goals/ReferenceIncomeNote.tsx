import type { GoalDetail } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import styles from './GoalsPage.module.css';

export function ReferenceIncomeNote({ goal }: { goal: GoalDetail }) {
  const { t, i18n } = useTranslation();
  if (!goal.referenceIncome) return null;
  const income = goal.referenceIncome;
  return (
    <p className={styles.reference}>
      {income.value === null
        ? t('goals.noReferenceIncome')
        : t('goals.referenceIncome', {
            value: formatAmount(income.value, goal.assetCode, i18n.language),
            usable: income.usableMonths,
            start: income.windowStart,
            end: income.windowEnd,
          })}
    </p>
  );
}
