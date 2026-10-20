import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountFactRows } from './account-sheet/AccountFactRows';
import { RuleActions } from './account-sheet/RuleActions';
import { Sheet, SheetRow } from './sheet-row/SheetRow';
import styles from './AccountRulesPanel.module.css';
import skeletonStyles from './AccountRulesPanelSkeleton.module.css';

interface AccountRulesPanelSkeletonProps {
  account: Account;
  status: string;
}

/**
 * Mirrors AccountRulesPanel element for element: the account's
 * own opening and closing lines and the yield label are real; only the rule
 * lines, whose names and values come from the request, are pending.
 */
export function AccountRulesPanelSkeleton({ account, status }: AccountRulesPanelSkeletonProps) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.panel} data-testid="account-rules-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <Sheet>
        <AccountFactRows account={account} />
        <SheetRow
          label={t('accounts.rules.yield.title')}
          actions={<RuleActions disabled />}
          since={<SkeletonBlock className={skeletonStyles.field} />}
          value={<SkeletonBlock className={skeletonStyles.field} />}
        />
        {[0, 1].map((index) => (
          <SheetRow
            key={index}
            label={<SkeletonBlock className={skeletonStyles.field} />}
            actions={<RuleActions disabled />}
            since={<SkeletonBlock className={skeletonStyles.field} />}
            value={<SkeletonBlock className={skeletonStyles.field} />}
          />
        ))}
      </Sheet>
    </div>
  );
}
