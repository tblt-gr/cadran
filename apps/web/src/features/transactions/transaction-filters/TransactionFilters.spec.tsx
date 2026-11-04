import type { Account, Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DEFAULT_FILTERS, type TransactionFilterState } from './filterState';
import { TransactionFilters } from './TransactionFilters';

const api = vi.hoisted(() => ({ listAccounts: vi.fn(), listCategories: vi.fn() }));

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
  effectiveColor: null,
  effectiveIcon: null,
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

function toggle() {
  return screen.getByRole('button', { name: /^Filtres/ });
}

function openFilters() {
  fireEvent.click(toggle());
}

describe('TransactionFilters', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('renders the search and a closed toggle, with no chip, at the default filters', () => {
    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(
      screen.getByRole('searchbox', {
        name: 'Rechercher un libellé, une contrepartie ou un montant',
      }),
    ).toBeTruthy();
    expect(toggle().textContent).toBe('Filtres');
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('button', { name: 'Tout effacer' })).toBeNull();
  });

  it('wires the toggle to the panel it unfolds', () => {
    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    const panel = document.getElementById(toggle().getAttribute('aria-controls') ?? '');

    expect(panel?.hidden).toBe(true);
    openFilters();

    expect(toggle().getAttribute('aria-expanded')).toBe('true');
    expect(panel?.hidden).toBe(false);
  });

  it('opens on load only when a filter other than search, queue tab or month is set', () => {
    const { unmount } = renderFilters({
      accounts: [account],
      filters: {
        ...DEFAULT_FILTERS,
        categorization: 'NONE',
        month: '2026-11',
        period: 'month',
        q: 'loyer',
      },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
    unmount();

    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, direction: 'INFLOW' },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
  });

  it('lays the panel out in the documented order', () => {
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, direction: 'INFLOW' },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    const panel = document.getElementById(toggle().getAttribute('aria-controls') ?? '')!;
    const text = panel.textContent ?? '';
    const order = [
      'Période',
      'Filtrer par compte',
      'Sens',
      'Montant min',
      'Filtrer par catégorie',
      'Axes analytiques',
      'Nature',
      'État',
      'Origine',
      'Afficher les transactions annulées',
    ].map((label) => text.indexOf(label));

    expect(order.every((position) => position >= 0)).toBe(true);
    expect([...order].sort((a, b) => a - b)).toEqual(order);
  });

  it('counts the active filters except the search in the toggle', () => {
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, direction: 'OUTFLOW', includeVoided: true, q: 'loyer' },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(toggle().textContent).toBe('Filtres · 2');
  });

  it('keeps the chips visible with the panel closed and clears one on its own', () => {
    const onChange = vi.fn();
    renderFilters({
      accounts: [account],
      filters: {
        ...DEFAULT_FILTERS,
        categorization: 'ANY',
        includeVoided: true,
        month: '2026-11',
        period: 'month',
      },
      onChange,
      onReset: vi.fn(),
    });
    fireEvent.click(toggle());
    expect(toggle().getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(screen.getByRole('button', { name: 'Retirer le filtre Novembre 2026' }));

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ period: 'all', includeVoided: true }),
    );
  });

  it('clears everything from the text button', () => {
    const onReset = vi.fn();
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, includeVoided: true },
      onChange: vi.fn(),
      onReset,
    });

    fireEvent.click(screen.getByRole('button', { name: 'Tout effacer' }));

    expect(onReset).toHaveBeenCalledOnce();
  });

  it('toggles a state filter through a native, focusable checkbox', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openFilters();
    const checkbox = screen.getByRole('checkbox', { name: 'Comptabilisée' });
    checkbox.focus();
    expect(document.activeElement).toBe(checkbox);
    fireEvent.click(checkbox);

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: ['BOOKED'] }));
  });

  it('sets the direction from the Sens control', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openFilters();
    fireEvent.click(screen.getByRole('button', { name: 'Sorties' }));

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ direction: 'OUTFLOW' }));
  });

  it('strips a typed minus and converts a comma in the amount bounds before state', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openFilters();
    fireEvent.change(screen.getByLabelText('Montant max'), { target: { value: '-200,00' } });

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({ assetCode: 'EUR', maxAmount: '200.00' }),
    );
  });

  it('does not bring back a search typed just before a reset once the debounce elapses', () => {
    vi.useFakeTimers();
    try {
      renderControlledFilters({
        accounts: [account],
        initialFilters: { ...DEFAULT_FILTERS, includeVoided: true },
      });

      const search = screen.getByRole('searchbox');
      fireEvent.change(search, { target: { value: 'abc' } });
      fireEvent.click(screen.getByRole('button', { name: 'Tout effacer' }));
      act(() => {
        vi.advanceTimersByTime(400);
      });

      expect(search).toHaveProperty('value', '');
      expect(screen.queryByRole('button', { name: /Retirer le filtre Recherche/ })).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  it('hides the amount chip while the bounds have no asset to apply them in', () => {
    renderFilters({
      accounts: [account, { ...account, id: 'other', assetCode: 'USD' }],
      filters: { ...DEFAULT_FILTERS, minAmount: '200.00' },
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

  it('renders the search and toggle inert while disabled', () => {
    renderFilters({
      accounts: [account],
      disabled: true,
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(screen.getByRole('searchbox')).toHaveProperty('disabled', true);
    expect(toggle()).toHaveProperty('disabled', true);
  });

  it('shows a skeleton, never the id, then the label of a category restored from the URL', async () => {
    let resolve: (value: unknown) => void = () => {};
    api.listCategories.mockImplementation(() => new Promise((done) => (resolve = done)));
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, categoryId: [category.id] },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(screen.queryByText(category.id)).toBeNull();
    expect(document.querySelector('[data-skeleton]')).not.toBeNull();

    await act(async () => {
      resolve(await success({ items: [category], page: 1, pageSize: 100, total: 1 }));
    });

    expect(await screen.findByRole('button', { name: 'Retirer le filtre Sorties' })).toBeTruthy();
    expect(document.querySelector('[data-skeleton]')).toBeNull();
  });

  it('reads "Catégorie inconnue" for an id the workspace does not have', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [], page: 1, pageSize: 100, total: 0 }),
    );
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, categoryId: [category.id] },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(await screen.findByText('Catégorie inconnue')).toBeTruthy();
    expect(screen.queryByText(category.id)).toBeNull();
  });

  it('names an archived account that is not among the loaded ones', async () => {
    api.listAccounts.mockImplementation(() =>
      success({
        items: [{ ...account, id: 'archived-1', label: 'Ancien livret', status: 'ARCHIVED' }],
        page: 1,
        pageSize: 100,
        total: 1,
      }),
    );
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, accountId: ['archived-1'] },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    expect(await screen.findByText('Ancien livret')).toBeTruthy();
    expect(screen.queryByText('archived-1')).toBeNull();
  });

  it('shows the picked category label on its chip, including its remove name', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [category], page: 1, pageSize: 50, total: 1 }),
    );
    renderControlledFilters({ accounts: [account], initialFilters: DEFAULT_FILTERS });

    openFilters();
    const combobox = screen.getByRole('combobox', { name: 'Filtrer par catégorie' });
    fireEvent.change(combobox, { target: { value: 'Sor' } });
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Sorties' }));

    expect(screen.queryByText(category.id)).toBeNull();
    expect(screen.getByRole('button', { name: /Retirer le filtre Sorties/ })).toBeTruthy();
  });
});
