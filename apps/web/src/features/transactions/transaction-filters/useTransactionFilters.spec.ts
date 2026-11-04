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

  it('rewrites a legacy period in place, without adding a history entry', () => {
    setLocation('?period=lastMonth&q=loyer');
    const before = window.history.length;

    const { result } = renderHook(() => useTransactionFilters());

    const params = new URLSearchParams(window.location.search);
    expect(params.get('period')).toBe('month');
    expect(params.get('month')).toMatch(/^\d{4}-\d{2}$/);
    expect(params.get('q')).toBe('loyer');
    expect(result.current.filters.period).toBe('month');
    expect(window.history.length).toBe(before);
  });

  it('pushes a history entry when asked to', () => {
    setLocation('');
    const { result } = renderHook(() => useTransactionFilters());
    const before = window.history.length;

    act(() => {
      result.current.setFilters({ ...DEFAULT_FILTERS, period: 'month', month: '2026-10' }, 'push');
    });

    expect(window.history.length).toBe(before + 1);
    expect(window.location.search).toBe('?period=month&month=2026-10');
  });
});
