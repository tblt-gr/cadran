import type { TransactionState } from '@cadran/api-client';
import { cleanup, renderHook } from '@testing-library/react';
import { isValidElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import i18n from '@/i18n';
import { DEFAULT_FILTERS, type TransactionFilterState } from './filterState';
import { LABEL_UNAVAILABLE, useFilterChips, type LabelLookup } from './useFilterChips';

const { t } = i18n;

function chipsFor(
  filters: TransactionFilterState,
  options: {
    accountLabel?: LabelLookup;
    categoryLabel?: LabelLookup;
    onChange?: () => void;
    onClearQuery?: () => void;
  } = {},
) {
  return renderHook(() =>
    useFilterChips({
      accountLabel: options.accountLabel ?? (() => null),
      categoryLabel: options.categoryLabel ?? (() => null),
      filters,
      language: 'fr',
      onChange: options.onChange ?? vi.fn(),
      onClearQuery: options.onClearQuery ?? vi.fn(),
      t,
    }),
  ).result.current;
}

describe('useFilterChips', () => {
  afterEach(cleanup);

  it('carries no chip for the unfiltered default state', () => {
    expect(chipsFor(DEFAULT_FILTERS)).toEqual([]);
  });

  it('clears only its own filter, leaving the rest of the state untouched', () => {
    const onChange = vi.fn();
    const filters = {
      ...DEFAULT_FILTERS,
      state: ['BOOKED', 'VOIDED'] as TransactionState[],
      period: 'month' as const,
      month: '2026-11',
    };

    const stateChip = chipsFor(filters, { onChange }).find((chip) => chip.key === 'state-VOIDED');
    expect(stateChip).toBeDefined();
    stateChip?.onClear();

    expect(onChange).toHaveBeenLastCalledWith({ ...filters, state: ['BOOKED'] });
  });

  it('labels a month chip with the month and a direction chip with its sense', () => {
    const chips = chipsFor({
      ...DEFAULT_FILTERS,
      direction: 'OUTFLOW',
      month: '2026-11',
      period: 'month',
    });

    expect(chips.map((chip) => chip.label)).toEqual(['Novembre 2026', 'Sorties']);
  });

  it('words the amount chip by the bounds it has', () => {
    const base = { ...DEFAULT_FILTERS, assetCode: 'EUR' };

    expect(chipsFor({ ...base, minAmount: '10', maxAmount: '50' })[0].label).toBe(
      'Montant entre 10 et 50',
    );
    expect(chipsFor({ ...base, minAmount: '10' })[0].label).toBe('Montant ≥ 10');
    expect(chipsFor({ ...base, maxAmount: '50' })[0].label).toBe('Montant ≤ 50');
  });

  it('resolves account and category labels, never showing an identifier', () => {
    const chips = chipsFor(
      { ...DEFAULT_FILTERS, accountId: ['acc-1', 'acc-gone'], categoryId: ['cat-gone'] },
      {
        accountLabel: (id) => (id === 'acc-1' ? 'Compte courant' : null),
      },
    );

    expect(chips.map((chip) => chip.label)).toEqual([
      'Compte courant',
      'Compte inconnu',
      'Catégorie inconnue',
    ]);
    expect(chips.map((chip) => chip.removeLabel).join(' ')).not.toContain('gone');
  });

  it('reads "indisponible" rather than "inconnu" when the lookup failed', () => {
    const chips = chipsFor(
      { ...DEFAULT_FILTERS, accountId: ['acc-1'], categoryId: ['cat-1'] },
      { accountLabel: () => LABEL_UNAVAILABLE, categoryLabel: () => LABEL_UNAVAILABLE },
    );

    expect(chips.map((chip) => chip.label)).toEqual([
      'Compte indisponible',
      'Catégorie indisponible',
    ]);
  });

  it('puts a skeleton in place of a label still being resolved', () => {
    const [chip] = chipsFor(
      { ...DEFAULT_FILTERS, categoryId: ['cat-1'] },
      { categoryLabel: () => undefined },
    );

    expect(isValidElement(chip.label)).toBe(true);
    expect(chip.removeLabel).toBe('Retirer le filtre catégorie');
  });

  it('routes the free-text chip through the dedicated query-clearing callback', () => {
    const onClearQuery = vi.fn();
    const onChange = vi.fn();

    chipsFor({ ...DEFAULT_FILTERS, q: 'carrefour' }, { onChange, onClearQuery })
      .find((chip) => chip.key === 'q')
      ?.onClear();

    expect(onClearQuery).toHaveBeenCalledTimes(1);
    expect(onChange).not.toHaveBeenCalled();
  });

  it('does not chip the to-categorise tab, which the page shows on its own', () => {
    expect(chipsFor({ ...DEFAULT_FILTERS, categorization: 'NONE' })).toEqual([]);
  });
});
