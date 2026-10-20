import type { Account } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountFactRows } from './account-sheet/AccountFactRows';
import { Sheet, SheetRow } from './sheet-row/SheetRow';
import styles from './AccountRulesPanel.module.css';
import skeletonStyles from './AccountRulesPanelSkeleton.module.css';

interface AccountRulesPanelSkeletonProps {
  account: Account;
  /** The panel's own date field, live while the rules resolve. */
  dateField: ReactNode;
  status: string;
}

/**
 * Mirrors AccountRulesPanel element for element: the date field, the account's
 * own opening and closing lines and the yield label are real; only the rule
 * lines, whose names and values come from the request, are pending.
 */
export function AccountRulesPanelSkeleton({
  account,
  dateField,
  status,
}: AccountRulesPanelSkeletonProps) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.panel} data-testid="account-rules-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      {dateField}
      <Sheet>
        <AccountFactRows account={account} />
        <SheetRow
          label={t('accounts.rules.yield.title')}
          pending
          since={<SkeletonBlock className={skeletonStyles.field} />}
          value={<SkeletonBlock className={skeletonStyles.field} />}
        />
        {[0, 1].map((index) => (
          <SheetRow
            key={index}
            label={<SkeletonBlock className={skeletonStyles.field} />}
            pending
            since={<SkeletonBlock className={skeletonStyles.field} />}
            value={<SkeletonBlock className={skeletonStyles.field} />}
          />
        ))}
      </Sheet>
    </div>
  );
}
