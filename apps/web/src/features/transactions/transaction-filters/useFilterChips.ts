import type { Account } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import type { Chip } from './active-filter-chips/ActiveFilterChips';
import { hasAppliedAmountBounds, type TransactionFilterState } from './filterState';

interface UseFilterChipsOptions {
  accounts: Account[];
  categoryLabels: Record<string, string>;
  filters: TransactionFilterState;
  onChange: (next: TransactionFilterState) => void;
  onClearQuery: () => void;
  queue: boolean;
  t: TFunction;
}

/**
 * One chip per applied filter, each carrying the callback that clears just that filter. The
 * period, account, state, nature, category, axis, source, amount, categorisation-queue and
 * free-text filters each contribute independently, so a chip's own clear never disturbs the
 * others.
 */
export function useFilterChips({
  accounts,
  categoryLabels,
  filters,
  onChange,
  onClearQuery,
  queue,
  t,
}: UseFilterChipsOptions): Chip[] {
  function set<K extends keyof TransactionFilterState>(key: K, value: TransactionFilterState[K]) {
    onChange({ ...filters, [key]: value });
  }

  const chips: Chip[] = [];

  if (filters.period !== 'all') {
    chips.push({
      key: 'period',
      label: t(`transactions.filters.period.${filters.period}`),
      onClear: () => set('period', 'all'),
    });
  }
  for (const id of filters.accountId) {
    const account = accounts.find((candidate) => candidate.id === id);
    chips.push({
      key: `account-${id}`,
      label: account?.label ?? id,
      onClear: () =>
        set(
          'accountId',
          filters.accountId.filter((candidate) => candidate !== id),
        ),
    });
  }
  for (const value of filters.state) {
    chips.push({
      key: `state-${value}`,
      label: t(`transactions.states.${value}`),
      onClear: () =>
        set(
          'state',
          filters.state.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const value of filters.nature) {
    chips.push({
      key: `nature-${value}`,
      label: t(`transactions.natures.${value}`),
      onClear: () =>
        set(
          'nature',
          filters.nature.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const id of filters.categoryId) {
    chips.push({
      key: `category-${id}`,
      label: categoryLabels[id] ?? id,
      onClear: () =>
        set(
          'categoryId',
          filters.categoryId.filter((candidate) => candidate !== id),
        ),
    });
  }
  for (const value of filters.axis) {
    chips.push({
      key: `axis-${value}`,
      label: t(`categories.axes.${value}`),
      onClear: () =>
        set(
          'axis',
          filters.axis.filter((candidate) => candidate !== value),
        ),
    });
  }
  for (const value of filters.source) {
    chips.push({
      key: `source-${value}`,
      label: t(`transactions.sources.${value}`),
      onClear: () =>
        set(
          'source',
          filters.source.filter((candidate) => candidate !== value),
        ),
    });
  }
  if (hasAppliedAmountBounds(filters)) {
    chips.push({
      key: 'amount',
      label: t('transactions.filters.amountChip', {
        min: filters.minAmount || '…',
        max: filters.maxAmount || '…',
      }),
      onClear: () => onChange({ ...filters, minAmount: '', maxAmount: '', assetCode: '' }),
    });
  }
  if (queue) {
    chips.push({
      key: 'categorization',
      label: t('transactions.filters.categorizationQueue'),
      onClear: () => set('categorization', 'ANY'),
    });
  }
  if (filters.q) {
    chips.push({
      key: 'q',
      label: t('transactions.filters.searchChip', { query: filters.q }),
      onClear: onClearQuery,
    });
  }
  if (filters.includeVoided && !queue) {
    chips.push({
      key: 'includeVoided',
      label: t('transactions.includeVoided'),
      onClear: () => set('includeVoided', false),
    });
  }

  return chips;
}
