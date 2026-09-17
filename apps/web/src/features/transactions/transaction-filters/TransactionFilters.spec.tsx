import type { Account, Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DEFAULT_FILTERS, type TransactionFilterState } from './filterState';
import { TransactionFilters } from './TransactionFilters';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const account = {
  id: '00000000-0000-7000-8000-0000000000d1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const category = {
  id: '00000000-0000-7000-8000-0000000000c2',
  type: 'EXPENSE',
  label: 'Sorties',
  parentId: null,
  parentLabel: null,
  icon: null,
  color: null,
  defaultAnalyticAxes: [],
  budgetIncluded: true,
  sortOrder: 0,
  depth: 1,
  version: 1,
  used: false,
  typeEditable: true,
  typeEditReason: null,
  canAcceptChildren: true,
  archivedAt: null,
  replacement: null,
} as Category;

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

type FiltersProps = Parameters<typeof TransactionFilters>[0];

function renderFilters(props: Omit<FiltersProps, 'revision'>) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <TransactionFilters revision={0} {...props} />
    </QueryClientProvider>,
  );
}

/** Mirrors how `TransactionsPage` actually wires the filters: `onChange` feeds back into `filters`. */
function ControlledFilters(
  props: Omit<FiltersProps, 'filters' | 'onChange' | 'onReset' | 'revision'> & {
    initialFilters: TransactionFilterState;
  },
) {
  const [filters, setFilters] = useState(props.initialFilters);
  const [revision, setRevision] = useState(0);
  return (
    <TransactionFilters
      {...props}
      filters={filters}
      onChange={setFilters}
      onReset={() => {
        setFilters(DEFAULT_FILTERS);
        setRevision((current) => current + 1);
      }}
      revision={revision}
    />
  );
}

function renderControlledFilters(
  props: Omit<FiltersProps, 'filters' | 'onChange' | 'onReset' | 'revision'> & {
    initialFilters: TransactionFilterState;
  },
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <ControlledFilters {...props} />
    </QueryClientProvider>,
  );
}

describe('TransactionFilters', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('renders no chip and no reset button when every filter is at its default', () => {
    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(screen.queryByRole('button', { name: 'Réinitialiser les filtres' })).toBeNull();
  });

  it('shows a chip for each active filter and calls onReset from the global reset button', () => {
    const onReset = vi.fn();
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, period: 'thisMonth', includeVoided: true },
      onChange: vi.fn(),
      onReset,
    });

    expect(screen.getAllByText('Ce mois-ci').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Afficher les transactions annulées').length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole('button', { name: 'Réinitialiser les filtres' }));
    expect(onReset).toHaveBeenCalledOnce();
  });

  it('clears a single filter from its own chip without resetting the others', () => {
    const onChange = vi.fn();
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, period: 'thisMonth', includeVoided: true },
      onChange,
      onReset: vi.fn(),
    });

    fireEvent.click(screen.getByRole('button', { name: /Retirer le filtre Ce mois-ci/ }));

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ period: 'all', includeVoided: true }),
    );
  });

  it('toggles a state filter with the keyboard through a native, focusable checkbox', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    const checkbox = screen.getByRole('checkbox', { name: 'Comptabilisée' });
    checkbox.focus();
    expect(document.activeElement).toBe(checkbox);
    fireEvent.click(checkbox);

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: ['BOOKED'] }));
  });

  it('reveals the mobile toggle as a focusable, keyboard-operable button controlling the panel', () => {
    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    const toggle = screen.getByRole('button', { name: 'Filtres' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    toggle.focus();
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
  });

  it('normalises a comma decimal typed into the amount bounds before it reaches state', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    fireEvent.change(screen.getByLabelText('Borne haute'), { target: { value: '-200,00' } });

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ maxAmount: '-200.00' }));
  });

  it('does not bring back a search typed just before a reset once the debounce elapses', () => {
    vi.useFakeTimers();
    try {
      renderControlledFilters({
        accounts: [account],
        initialFilters: { ...DEFAULT_FILTERS, period: 'thisMonth' },
      });

      const search = screen.getByRole('searchbox', { name: 'Recherche libre' });
      fireEvent.change(search, { target: { value: 'abc' } });
      fireEvent.click(screen.getByRole('button', { name: 'Réinitialiser les filtres' }));
      act(() => {
        vi.advanceTimersByTime(400);
      });

      expect(search).toHaveProperty('value', '');
      expect(screen.queryByRole('button', { name: /Retirer le filtre Recherche/ })).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  it('hides the amount chip while the bounds have no asset code to apply them in', () => {
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, minAmount: '-200.00' },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(screen.queryByRole('button', { name: /Retirer le filtre Montant/ })).toBeNull();
    expect(
      screen.getByText('Choisissez l’actif des bornes pour appliquer le filtre de montant.'),
    ).toBeTruthy();
  });

  it('disables and ignores the voided toggle inside the to-categorise queue', () => {
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, categorization: 'NONE', includeVoided: true },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    const checkbox = screen.getByRole('checkbox', { name: 'Afficher les transactions annulées' });
    expect(checkbox).toHaveProperty('disabled', true);
    expect(checkbox).toHaveProperty('checked', false);
  });

  it('falls back to the raw id on a category chip until a label is resolved', () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 50, total: 0 }),
    );
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, categoryId: [category.id] },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(screen.getByText(category.id)).toBeTruthy();
  });

  it('shows the picked category label on its chip, including its screen-reader clear text', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [category], page: 1, perPage: 50, total: 1 }),
    );
    renderControlledFilters({ accounts: [account], initialFilters: DEFAULT_FILTERS });

    const combobox = screen.getByRole('combobox', { name: 'Filtrer par catégorie' });
    fireEvent.change(combobox, { target: { value: 'Sor' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Sorties' }));

    expect(screen.getByText('Sorties')).toBeTruthy();
    expect(screen.queryByText(category.id)).toBeNull();
    expect(screen.getByRole('button', { name: /Retirer le filtre Sorties/ })).toBeTruthy();
  });
});
