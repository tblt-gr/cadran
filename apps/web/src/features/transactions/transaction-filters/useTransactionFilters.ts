import { useCallback, useEffect, useState } from 'react';
import {
  DEFAULT_FILTERS,
  decodeFilters,
  encodeFilters,
  type TransactionFilterState,
} from './filterState';

function readFromLocation(): TransactionFilterState {
  return decodeFilters(new URLSearchParams(window.location.search));
}

function writeToLocation(filters: TransactionFilterState) {
  const params = encodeFilters(filters);
  const query = params.toString();
  const url = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`;
  window.history.replaceState(window.history.state, '', url);
}

/**
 * Keeps the transaction filter set in sync with the URL query string: it is the single
 * source of truth, so a reload or a back navigation restores exactly what was applied.
 * Changes replace the current history entry rather than pushing a new one — a filter bar
 * is not a sequence of pages to step back through — while a `popstate` coming from
 * elsewhere (browser back/forward) still re-reads the URL and updates the state.
 *
 * `revision` changes whenever the set is replaced from outside the filter bar (reset, history
 * navigation), so fields holding a not-yet-committed draft can discard it even when the
 * committed value they mirror stays the same.
 */
export function useTransactionFilters() {
  const [filters, setFilters] = useState<TransactionFilterState>(() =>
    typeof window === 'undefined' ? DEFAULT_FILTERS : readFromLocation(),
  );
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    function onPopState() {
      setFilters(readFromLocation());
      setRevision((current) => current + 1);
    }
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  const update = useCallback((next: TransactionFilterState) => {
    writeToLocation(next);
    setFilters(next);
  }, []);

  const reset = useCallback(() => {
    update(DEFAULT_FILTERS);
    setRevision((current) => current + 1);
  }, [update]);

  return { filters, reset, revision, setFilters: update } as const;
}
