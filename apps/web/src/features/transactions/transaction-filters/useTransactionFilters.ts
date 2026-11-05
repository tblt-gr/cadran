import { useCallback, useEffect, useState } from 'react';
import {
  DEFAULT_FILTERS,
  decodeFilters,
  encodeFilters,
  hasLegacyPeriod,
  type TransactionFilterState,
} from './filterState';

const NO_PRESERVED_KEYS: readonly string[] = [];

function readFromLocation(): TransactionFilterState {
  return decodeFilters(new URLSearchParams(window.location.search));
}

function writeToLocation(
  filters: TransactionFilterState,
  mode: 'replace' | 'push',
  preserve: readonly string[],
) {
  const params = encodeFilters(filters);
  // Parameters owned by the host page (such as its active tab) survive every filter change.
  const current = new URLSearchParams(window.location.search);
  for (const key of preserve) {
    for (const value of current.getAll(key)) params.append(key, value);
  }
  const query = params.toString();
  const url = `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`;
  if (mode === 'push') {
    window.history.pushState(window.history.state, '', url);
  } else {
    window.history.replaceState(window.history.state, '', url);
  }
}

/** A bookmark carrying a retired period is rewritten in place, without a history entry. */
function rewriteLegacyLocation(filters: TransactionFilterState, preserve: readonly string[]) {
  if (hasLegacyPeriod(new URLSearchParams(window.location.search))) {
    writeToLocation(filters, 'replace', preserve);
  }
}

/**
 * Keeps the transaction filter set in sync with the URL query string: it is the single
 * source of truth, so a reload or a back navigation restores exactly what was applied.
 * Changes replace the current history entry rather than pushing a new one: a filter bar
 * is not a sequence of pages to step back through: except the month stepper, which asks
 * for a pushed entry so back returns to the previous month. A `popstate` coming from
 * elsewhere (browser back/forward) still re-reads the URL and updates the state.
 *
 * `preserve` names the query parameters the host page owns (the account page's `tab`): they are
 * carried over unchanged, so filtering never navigates the page elsewhere.
 *
 * `revision` changes whenever the set is replaced from outside the filter bar (reset, history
 * navigation), so fields holding a not-yet-committed draft can discard it even when the
 * committed value they mirror stays the same.
 */
export function useTransactionFilters(preserve: readonly string[] = NO_PRESERVED_KEYS) {
  const [filters, setFilters] = useState<TransactionFilterState>(() =>
    typeof window === 'undefined' ? DEFAULT_FILTERS : readFromLocation(),
  );
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    rewriteLegacyLocation(readFromLocation(), preserve);
  }, [preserve]);

  useEffect(() => {
    function onPopState() {
      const next = readFromLocation();
      rewriteLegacyLocation(next, preserve);
      setFilters(next);
      setRevision((current) => current + 1);
    }
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [preserve]);

  const update = useCallback(
    (next: TransactionFilterState, mode: 'replace' | 'push' = 'replace') => {
      writeToLocation(next, mode, preserve);
      setFilters(next);
    },
    [preserve],
  );

  const reset = useCallback(() => {
    update(DEFAULT_FILTERS);
    setRevision((current) => current + 1);
  }, [update]);

  return { filters, reset, revision, setFilters: update } as const;
}
