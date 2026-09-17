import type { TransactionState } from '@cadran/api-client';
import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import '@/i18n';
import i18n from '@/i18n';
import { DEFAULT_FILTERS } from './filterState';
import { useFilterChips } from './useFilterChips';

const { t } = i18n;

describe('useFilterChips', () => {
  it('carries no chip for the unfiltered default state', () => {
    const { result } = renderHook(() =>
      useFilterChips({
        accounts: [],
        categoryLabels: {},
        filters: DEFAULT_FILTERS,
        onChange: vi.fn(),
        onClearQuery: vi.fn(),
        queue: false,
        t,
      }),
    );

    expect(result.current).toEqual([]);
  });

  it('clears only its own filter, leaving the rest of the state untouched', () => {
    const onChange = vi.fn();
    const filters = {
      ...DEFAULT_FILTERS,
      state: ['BOOKED', 'VOIDED'] as TransactionState[],
      period: 'thisMonth' as const,
    };
    const { result } = renderHook(() =>
      useFilterChips({
        accounts: [],
        categoryLabels: {},
        filters,
        onChange,
        onClearQuery: vi.fn(),
        queue: false,
        t,
      }),
    );

    const stateChip = result.current.find((chip) => chip.key === 'state-VOIDED');
    expect(stateChip).toBeDefined();
    stateChip?.onClear();

    expect(onChange).toHaveBeenLastCalledWith({ ...filters, state: ['BOOKED'] });
  });

  it('resolves an account chip to its label and falls back to the id otherwise', () => {
    const { result } = renderHook(() =>
      useFilterChips({
        accounts: [{ id: 'acc-1', label: 'Compte courant' } as never],
        categoryLabels: {},
        filters: { ...DEFAULT_FILTERS, accountId: ['acc-1', 'acc-unknown'] },
        onChange: vi.fn(),
        onClearQuery: vi.fn(),
        queue: false,
        t,
      }),
    );

    const labels = result.current.map((chip) => chip.label);
    expect(labels).toContain('Compte courant');
    expect(labels).toContain('acc-unknown');
  });

  it('routes the free-text chip through the dedicated query-clearing callback', () => {
    const onClearQuery = vi.fn();
    const onChange = vi.fn();
    const { result } = renderHook(() =>
      useFilterChips({
        accounts: [],
        categoryLabels: {},
        filters: { ...DEFAULT_FILTERS, q: 'carrefour' },
        onChange,
        onClearQuery,
        queue: false,
        t,
      }),
    );

    result.current.find((chip) => chip.key === 'q')?.onClear();

    expect(onClearQuery).toHaveBeenCalledTimes(1);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('adds the categorisation-queue chip only while the queue filter is active', () => {
    const { result } = renderHook(() =>
      useFilterChips({
        accounts: [],
        categoryLabels: {},
        filters: DEFAULT_FILTERS,
        onChange: vi.fn(),
        onClearQuery: vi.fn(),
        queue: true,
        t,
      }),
    );

    expect(result.current.some((chip) => chip.key === 'categorization')).toBe(true);
  });
});
