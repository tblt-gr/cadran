import type { CeilingCheck } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import styles from './RuleAlert.module.css';

type RuleAlertProps =
  | { check: CeilingCheck | null; comparable: boolean; language: string; shifted?: never }
  | { check?: never; comparable?: never; language?: never; shifted: true };

/**
 * The one short line a rule keeps beside its value when the figure alone would
 * mislead: a ceiling exceeded (allowed, never a refusal), a ceiling that cannot
 * be compared to the account, or a rate that changes above its first bracket.
 * The full explanation stays behind the rule's details button.
 */
export function RuleAlert(props: RuleAlertProps) {
  const { t } = useTranslation();

  let line: string | null = null;
  if (props.shifted) {
    line = t('accounts.rules.alerts.shifted');
  } else if (!props.comparable || props.check?.status === 'NOT_COMPARABLE') {
    line = t('accounts.rules.alerts.notComparable');
  } else if (props.check?.status === 'EXCEEDED') {
    const excess = props.check.excess;
    line =
      excess === null
        ? t('accounts.rules.alerts.exceeded')
        : t('accounts.rules.alerts.exceededBy', {
            excess: formatAmount(excess.value, excess.assetCode, props.language),
          });
  }

  return line === null ? null : (
    <p className={styles.alert} role="status">
      {line}
    </p>
  );
}
