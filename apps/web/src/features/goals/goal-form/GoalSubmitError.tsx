import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import type { GoalErrorKind, GoalScheduleMismatch } from '@/features/goals/goalError';
import styles from './GoalForm.module.css';

interface Props {
  assetCode: string;
  kind: GoalErrorKind;
  mismatch: GoalScheduleMismatch | null;
}

/** The server's refusal of the goal, with the exact schedule difference when it reports one. */
export function GoalSubmitError({ assetCode, kind, mismatch }: Props) {
  const { t, i18n } = useTranslation();

  return (
    <p className={styles.alert} role="alert">
      {t(`goals.errors.${kind}`)}
      {kind === 'schedule_sum_mismatch' && mismatch ? (
        <>
          {' '}
          {t('goals.schedule.serverMismatch', {
            difference: formatAmount(mismatch.difference, assetCode, i18n.language),
          })}
        </>
      ) : null}
    </p>
  );
}
