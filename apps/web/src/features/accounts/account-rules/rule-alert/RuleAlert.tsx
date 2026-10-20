import type { CeilingCheck } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { formatAmount } from '@/lib/decimal';
import styles from './RuleAlert.module.css';

/** One short sentence with its icon: the warning never rests on colour alone. */
export function AlertLine({ children, status = false }: { children: ReactNode; status?: boolean }) {
  return (
    <span className={styles.alert} role={status ? 'status' : undefined}>
      <Icon name="alert" size={14} />
      <span>{children}</span>
    </span>
  );
}

type RuleAlertProps =
  | { check: CeilingCheck | null; comparable: boolean; language: string; shifted?: never }
  | { check?: never; comparable?: never; language?: never; shifted: true };

/**
 * The one short line a rule keeps under its value when the figure alone would
 * mislead: a ceiling exceeded (allowed, never a refusal), a ceiling that cannot
 * be compared to the account, or a rate that changes above its first bracket.
 * The full explanation stays behind the line's details button.
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

  return line === null ? null : <AlertLine status>{line}</AlertLine>;
}
