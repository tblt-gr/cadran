import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountRequestError } from '@/features/accounts/accountError';
import { AccountsPageSkeleton } from '@/features/accounts/AccountsPageSkeleton';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { AccountsState } from '@/features/accounts/accounts-state/AccountsState';
import { NetWorthCompositionCard } from '@/features/accounts/net-worth-composition/NetWorthCompositionCard';
import { AccountGroupTable } from './account-group-table/AccountGroupTable';
import { useAccountOverview } from './useAccountOverview';
import styles from './AccountOverview.module.css';

interface AccountOverviewProps {
  grouping: GroupingMode;
  includeArchived: boolean;
  includeClosed: boolean;
  onArchive: (account: Account) => void;
  onCreate: () => void;
  onEdit: (account: Account) => void;
  onReconcile: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onResetFilters: () => void;
  onRules: (account: Account) => void;
  reordering: boolean;
}

/**
 * The single financial overview: one ordered table per primary group, then the
 * ungrouped section. Totals and shares come from the server at one valuation
 * date; nothing here sums an amount.
 */
export function AccountOverview({
  grouping,
  includeArchived,
  includeClosed,
  onCreate,
  onResetFilters,
  reordering,
  ...rowActions
}: AccountOverviewProps) {
  const { t } = useTranslation();
  const overview = useAccountOverview(grouping, includeClosed, includeArchived);

  if (overview.isPending) {
    return <AccountsPageSkeleton status={t('accounts.loading')} />;
  }

  if (overview.isError) {
    const unauthorized =
      overview.error instanceof AccountRequestError && overview.error.status === 401;

    return (
      <AccountsState
        kind={unauthorized ? 'unauthorized' : 'error'}
        onCreate={onCreate}
        onRetry={() => void overview.refetch()}
      />
    );
  }

  const { accountCount, asOf, groups } = overview.data;
  const shown = groups.filter((group) => group.memberCount > 0);

  if (accountCount === 0) {
    return <AccountsState kind="empty" onCreate={onCreate} onRetry={() => undefined} />;
  }

  if (groups.every((group) => group.memberCount === 0)) {
    return (
      <section className={`card ${styles.empty}`}>
        <h2>{t('accounts.overview.emptyFilter.title')}</h2>
        <p>{t('accounts.overview.emptyFilter.description')}</p>
        <button className="secondary-action" onClick={onResetFilters} type="button">
          {t('accounts.overview.emptyFilter.reset')}
        </button>
      </section>
    );
  }

  return (
    <div className={styles.overview}>
      <NetWorthCompositionCard asOf={asOf} />
      {shown.map((group) => (
        <AccountGroupTable
          asOf={asOf}
          group={group}
          grouping={grouping}
          includeArchived={includeArchived}
          includeClosed={includeClosed}
          key={group.groupId ?? 'ungrouped'}
          reordering={reordering}
          sole={shown.length === 1}
          {...rowActions}
        />
      ))}
    </div>
  );
}
