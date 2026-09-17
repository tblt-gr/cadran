import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { DEFAULT_FILTERS } from './filterState';
import { useTransactionFilters } from './useTransactionFilters';

function setLocation(search: string) {
  window.history.replaceState({}, '', `/transactions${search}`);
}

describe('useTransactionFilters', () => {
  afterEach(() => {
    setLocation('');
  });

  it('reads the initial state from the URL query string', () => {
    setLocation('?q=carrefour&includeVoided=1');

    const { result } = renderHook(() => useTransactionFilters());

    expect(result.current.filters.q).toBe('carrefour');
    expect(result.current.filters.includeVoided).toBe(true);
  });

  it('defaults to the unfiltered state when the URL carries no filter', () => {
    setLocation('');

    const { result } = renderHook(() => useTransactionFilters());

    expect(result.current.filters).toEqual(DEFAULT_FILTERS);
  });

  it('writes a change to the URL query string, surviving a reload', () => {
    setLocation('');
    const { result } = renderHook(() => useTransactionFilters());

    act(() => {
      result.current.setFilters({ ...DEFAULT_FILTERS, q: 'loyer' });
    });

    expect(new URLSearchParams(window.location.search).get('q')).toBe('loyer');

    // Simulates a reload: a fresh hook instance re-reads the same URL.
    const { result: afterReload } = renderHook(() => useTransactionFilters());
    expect(afterReload.current.filters.q).toBe('loyer');
  });

  it('resets to the default filters and clears the query string', () => {
    setLocation('?q=loyer&includeVoided=1');
    const { result } = renderHook(() => useTransactionFilters());

    act(() => {
      result.current.reset();
    });

    expect(result.current.filters).toEqual(DEFAULT_FILTERS);
    expect(window.location.search).toBe('');
  });

  it('re-reads the URL on a back-navigation popstate event', () => {
    setLocation('?q=loyer');
    const { result } = renderHook(() => useTransactionFilters());
    expect(result.current.filters.q).toBe('loyer');

    act(() => {
      setLocation('?q=carrefour');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });

    expect(result.current.filters.q).toBe('carrefour');
  });
});
