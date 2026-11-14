import { usePersistedChoice } from '@/hooks/use-persisted-choice';

/** `all`: every account in one table. `grouped`: one table per group, with its total and share. */
export type AccountsView = 'all' | 'grouped';

const ACCOUNTS_VIEWS: readonly AccountsView[] = ['grouped', 'all'];

/** The view kept per browser. */
export function useAccountsView(): [AccountsView, (view: AccountsView) => void] {
  return usePersistedChoice('cadran.accounts.view', ACCOUNTS_VIEWS, 'grouped');
}
