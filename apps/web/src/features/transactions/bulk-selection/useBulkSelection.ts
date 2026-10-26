import {
  countTransactions,
  type BulkTransactionFilters,
  type Transaction,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { transactionRequestError } from '@/features/transactions/transactionError';
import { toBulkFilters, type TransactionCountQuery } from './bulkFilters';

export type BulkScope =
  | { type: 'IDS'; ids: string[] }
  | { type: 'FILTER'; filters: BulkTransactionFilters; expectedCount: number };

interface UseBulkSelectionOptions {
  /** More rows than the loaded page match: the filter-wide scope is worth offering. */
  hasMore: boolean;
  /** The loaded page, the only rows a checkbox can select. */
  items: ReadonlyArray<Pick<Transaction, 'bookedOn' | 'displayLabel' | 'id'>>;
  /** The listing's filters, without paging: they define the filter-wide scope. */
  query: TransactionCountQuery;
  reloadAfterWrite: () => Promise<void>;
  /** Changes whenever the result set does (filters, search, account, period). */
  resetKey: string;
}

/** Checkbox wiring a list needs: which rows are selected and how to toggle one. */
export interface RowSelection {
  onToggle: (id: string) => void;
  selectedIds: ReadonlySet<string>;
}

export interface BulkSelection {
  allPageSelected: boolean;
  clear: () => void;
  /** Exact number of transactions matching the filters, once known. */
  matchingCount: number | null;
  /** Whether the banner proposing the filter-wide scope is relevant. */
  offerFilterScope: boolean;
  /** The loaded rows, used to name the transactions a result reports. */
  pageItems: ReadonlyArray<Pick<Transaction, 'bookedOn' | 'displayLabel' | 'id'>>;
  pageCount: number;
  reloadAfterWrite: () => Promise<void>;
  rowSelection: RowSelection;
  scope: BulkScope | null;
  selectedCount: number;
  selectFilterScope: () => void;
  somePageSelected: boolean;
  togglePage: () => void;
  filterScopeSelected: boolean;
}

/**
 * Selection of transactions for a bulk edit. It lives on the loaded page only and is dropped
 * as soon as the page or the result set changes, so a checkbox never refers to a row the
 * user can no longer see. The filter-wide scope selects by criteria, not by id, and is
 * resolved by the server at confirmation time.
 */
export function useBulkSelection({
  hasMore,
  items,
  query,
  reloadAfterWrite,
  resetKey,
}: UseBulkSelectionOptions): BulkSelection {
  const pageIds = items.map((item) => item.id);
  const signature = `${resetKey}|${pageIds.join(',')}`;
  const [state, setState] = useState<{
    filterScope: boolean;
    ids: ReadonlySet<string>;
    signature: string;
  }>({ filterScope: false, ids: new Set(), signature });
  // Dropped during render rather than in an effect: no frame shows a stale selection.
  if (state.signature !== signature) {
    setState({ filterScope: false, ids: new Set(), signature });
  }

  const allPageSelected = pageIds.length > 0 && pageIds.every((id) => state.ids.has(id));
  const somePageSelected = state.ids.size > 0;
  const countEnabled = allPageSelected && hasMore;
  const count = useQuery({
    queryKey: ['transactions', 'count', query],
    queryFn: async ({ signal }) => {
      const result = await countTransactions({ ...authApiOptions(), query, signal });
      if (!result.response?.ok || !result.data) {
        throw transactionRequestError(result);
      }
      return result.data.count;
    },
    enabled: countEnabled,
    retry: false,
  });
  const matchingCount = countEnabled ? (count.data ?? null) : null;

  function toggle(id: string) {
    const ids = new Set(state.filterScope ? pageIds : state.ids);
    if (ids.has(id)) {
      ids.delete(id);
    } else {
      ids.add(id);
    }
    setState({ filterScope: false, ids, signature });
  }

  function togglePage() {
    setState({
      filterScope: false,
      ids: allPageSelected ? new Set() : new Set(pageIds),
      signature,
    });
  }

  const filterScopeSelected = state.filterScope && matchingCount !== null;
  const selectedIds = state.filterScope ? new Set(pageIds) : state.ids;

  return {
    allPageSelected,
    clear: () => setState({ filterScope: false, ids: new Set(), signature }),
    filterScopeSelected,
    matchingCount,
    offerFilterScope: countEnabled && matchingCount !== null && matchingCount > pageIds.length,
    pageCount: pageIds.length,
    pageItems: items,
    reloadAfterWrite,
    rowSelection: { onToggle: toggle, selectedIds },
    scope: filterScopeSelected
      ? { type: 'FILTER', filters: toBulkFilters(query), expectedCount: matchingCount }
      : state.ids.size > 0
        ? { type: 'IDS', ids: pageIds.filter((id) => state.ids.has(id)) }
        : null,
    selectedCount: filterScopeSelected ? matchingCount : state.ids.size,
    selectFilterScope: () => setState({ filterScope: true, ids: new Set(pageIds), signature }),
    somePageSelected,
    togglePage,
  };
}
