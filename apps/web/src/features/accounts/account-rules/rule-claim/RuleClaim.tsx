import type { AccountRuleClaim } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './RuleClaim.module.css';

interface RuleClaimProps {
  claim: AccountRuleClaim;
  onWithdraw: (claim: AccountRuleClaim) => void;
}

/**
 * Why an account diverges from what it inherits, and who said so.
 *
 * A local figure with no reason beside it is indistinguishable from a sourced
 * one at a glance, which is the drift an override exists to make visible. The
 * reason is therefore rendered next to the value and not hidden behind a
 * detail toggle.
 */
export function RuleClaim({ claim, onWithdraw }: RuleClaimProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.claim}>
      <p>{claim.reason}</p>
      <small>
        {t('accounts.rules.claimedOn', {
          date: formatCalendarDay(claim.recordedAt.slice(0, 10), i18n.language),
        })}
      </small>
      <button className="secondary-action" onClick={() => onWithdraw(claim)} type="button">
        {t('accounts.rules.withdraw')}
      </button>
    </div>
  );
}
