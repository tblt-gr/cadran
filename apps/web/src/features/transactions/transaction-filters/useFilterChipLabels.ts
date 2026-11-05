import type { Account } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useCategoryIdentities } from '@/features/categories/useCategoryIdentities';
import { listAllAccounts } from '@/features/transactions/transactionAccounts';
import type { TransactionFilterState } from './filterState';
import { LABEL_UNAVAILABLE, type LabelLookup } from './useFilterChips';

interface UseFilterChipLabelsOptions {
  /** Accounts already on screen: they answer without a request. */
  accounts: readonly Account[];
  filters: Pick<TransactionFilterState, 'accountId' | 'categoryId'>;
}

/**
 * Resolves the label of every account and category a filter holds by identifier only (a
 * restored URL), through workspace-scoped lookups that include archived records. The lookups
 * only run once a filter needs them, so an unfiltered page makes no extra request.
 */
export function useFilterChipLabels({ accounts, filters }: UseFilterChipLabelsOptions) {
  const accountsOnScreen = new Map(accounts.map((account) => [account.id, account.label]));
  const needsAccountLookup = filters.accountId.some((id) => !accountsOnScreen.has(id));
  const accountLookup = useQuery({
    queryKey: ['accounts', { identities: true }],
    enabled: needsAccountLookup,
    queryFn: ({ signal }) => listAllAccounts(signal),
    retry: false,
    staleTime: 60_000,
  });
  const categoryLookup = useCategoryIdentities(filters.categoryId.length > 0);

  const accountLabel: LabelLookup = (id) => {
    const known = accountsOnScreen.get(id);
    if (known !== undefined) return known;
    if (accountLookup.data) return accountLookup.data.find((a) => a.id === id)?.label ?? null;

    return accountLookup.isError ? LABEL_UNAVAILABLE : undefined;
  };
  const categoryLabel: LabelLookup = (id) => {
    if (categoryLookup.data) return categoryLookup.data.get(id)?.label ?? null;

    return categoryLookup.isError ? LABEL_UNAVAILABLE : undefined;
  };

  return { accountLabel, categoryLabel };
}
