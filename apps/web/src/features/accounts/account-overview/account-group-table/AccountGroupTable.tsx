import type { Account, AccountOverviewGroup, AccountOverviewRow } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountList } from '@/features/accounts/account-list/AccountList';
import { AccountRequestError } from '@/features/accounts/accountError';
import { useGroupRows } from '@/features/accounts/account-overview/useGroupRows';
import {
  useMoveAccount,
  type AccountMove,
} from '@/features/accounts/account-overview/useMoveAccount';
import type { GroupingMode } from '@/features/accounts/grouping-mode/groupingMode';
import { AccountGroupHeading } from './AccountGroupHeading';
import { accountGroupName } from './accountGroupName';
import styles from './AccountGroupTable.module.css';

interface AccountGroupTableProps {
  asOf: string;
  group: AccountOverviewGroup;
  grouping: GroupingMode;
  includeArchived: boolean;
  includeClosed: boolean;
  onArchive: (account: Account) => void;
  onEdit: (account: Account) => void;
  onReconcile: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onRules: (account: Account) => void;
  reordering: boolean;
  sole: boolean;
}

/** One primary group: its heading, its ordered members and the recovery of a refused move. */
export function AccountGroupTable({
  asOf,
  group,
  grouping,
  includeArchived,
  includeClosed,
  reordering,
  sole,
  ...rowActions
}: AccountGroupTableProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const headingId = useId();
  const [lastMove, setLastMove] = useState<AccountMove | null>(null);
  const hasRows = group.memberCount > 0;
  const rows = useGroupRows({
    asOf,
    enabled: hasRows,
    groupKey: group.groupId ?? 'ungrouped',
    grouping,
    includeArchived,
    includeClosed,
  });
  const [movedId, setMovedId] = useState<string | null>(null);
  const move = useMoveAccount((account) => setMovedId(account.id));
  const accounts = rows.data?.pages.flatMap((page) => page.items) ?? null;
  // The position is read from the refetched visible list: the API rank also counts hidden members.
  const movedIndex = accounts?.findIndex((account) => account.id === movedId) ?? -1;
  const name = accountGroupName(group, sole, t);
  const stale = move.error instanceof AccountRequestError && move.error.kind === 'stale';

  function reload() {
    move.reset();
    void queryClient.invalidateQueries({ queryKey: ['accounts'] });
  }

  // A row is ordered inside its own primary group, which in parent mode is not the section's.
  function requestMove(account: AccountOverviewRow, beforeAccountId: string | null) {
    const next = {
      account,
      beforeAccountId,
      groupId: account.primaryGroupId,
      orderVersion: account.groupOrderVersion,
    };
    setLastMove(next);
    setMovedId(null);
    move.mutate(next);
  }

  return (
    <section aria-labelledby={headingId} className={styles.group}>
      <AccountGroupHeading
        asOf={asOf}
        group={group}
        grouping={grouping}
        headingId={headingId}
        sole={sole}
      />
      <p aria-live="polite" className="sr-only" role="status">
        {movedIndex >= 0 && accounts !== null
          ? t('accounts.overview.moved', {
              count: group.memberCount,
              label: accounts[movedIndex]!.label,
              rank: movedIndex + 1,
            })
          : null}
      </p>
      {move.isError ? (
        <p className={styles.alert} role="alert">
          {t(stale ? 'accounts.overview.stale' : 'accounts.overview.moveFailed')}{' '}
          {stale || lastMove === null ? (
            <button className="secondary-action" onClick={reload} type="button">
              {t('accounts.overview.reloadOrder')}
            </button>
          ) : (
            <>
              <button
                className="secondary-action"
                onClick={() => move.mutate(lastMove)}
                type="button"
              >
                {t('accounts.overview.retryMove')}
              </button>{' '}
              <button className="secondary-action" onClick={reload} type="button">
                {t('accounts.overview.reloadOrder')}
              </button>
            </>
          )}
        </p>
      ) : null}
      {rows.isError ? (
        <p className={styles.alert} role="alert">
          {t('accounts.overview.rowsUnavailable')}{' '}
          <button className="secondary-action" onClick={reload} type="button">
            {t('accounts.overview.rowsReload')}
          </button>
        </p>
      ) : null}
      {hasRows && !(rows.isError && accounts === null) ? (
        <AccountList
          accounts={accounts}
          groupAssetCode={group.total?.assetCode ?? null}
          hasMore={rows.hasNextPage}
          label={t('accounts.overview.caption', { label: name })}
          moveDisabled={move.isPending}
          onMove={requestMove}
          reordering={reordering}
          {...rowActions}
        />
      ) : null}
      {rows.hasNextPage ? (
        <button
          className="secondary-action"
          disabled={rows.isFetchingNextPage}
          onClick={() => void rows.fetchNextPage()}
          type="button"
        >
          {t(
            rows.isFetchingNextPage
              ? 'accounts.overview.loadingMore'
              : 'accounts.overview.loadMore',
          )}
        </button>
      ) : null}
    </section>
  );
}
