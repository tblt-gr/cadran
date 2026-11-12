import type { Account, Category, TransactionFacets } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DEFAULT_FILTERS, type TransactionFilterState } from './filterState';
import { TransactionFilters } from './TransactionFilters';
import type { TransactionFacetsRequest } from './useTransactionFacets';

const api = vi.hoisted(() => ({
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
  readTransactionFacets: vi.fn(),
}));

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

/** Distinct numbers per section: a section reading another one's counts would be caught. */
const facets: TransactionFacets = {
  accountId: { [account.id]: 11 },
  categoryId: { [category.id]: 13 },
  nature: { EXPENSE: 4, INCOME: 1 },
  state: { BOOKED: 5, PENDING: 2, VOIDED: 1 },
  source: { IMPORT: 3 },
  axis: { ESSENTIAL: 7 },
};

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

function filterColumn() {
  return document.getElementById(toggle().getAttribute('aria-controls') ?? '');
}

function section(title: string) {
  const summary = [...document.querySelectorAll('summary')].find((candidate) =>
    candidate.textContent?.startsWith(title),
  );
  return summary?.closest('details') ?? null;
}

function openSection(title: string) {
  fireEvent.click(section(title)!.querySelector('summary')!);
}

/** Narrow screens open the filters in a sheet; jsdom has no `matchMedia`, so it is stubbed. */
function stubCompactScreen() {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: true,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }));
}

describe('TransactionFilters', () => {
  it('keeps list actions in the search toolbar instead of reserving a separate row', () => {
    renderFilters({
      accounts: [account],
      actions: <button type="button">Modifier la sélection</button>,
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    const action = screen.getByRole('button', { name: 'Modifier la sélection' });
    const search = screen.getByRole('searchbox');
    expect(action.parentElement).toBe(search.closest('label')?.parentElement);
    expect(action.parentElement?.contains(toggle())).toBe(true);
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    window.localStorage.clear();
  });

  it('renders the search beside an open filter column, with no chip, at the default filters', () => {
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
    expect(toggle().getAttribute('aria-label')).toBe('Filtres');
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
    expect(filterColumn()?.tagName).toBe('ASIDE');
    expect(filterColumn()?.hidden).toBe(false);
    expect(screen.getByRole('heading', { name: 'Filtres' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Tout effacer' })).toBeNull();
  });

  it('hides and shows the filter column from the toggle and remembers the choice', () => {
    const { unmount } = renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    fireEvent.click(toggle());
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
    expect(filterColumn()?.hidden).toBe(true);
    unmount();

    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    expect(filterColumn()?.hidden).toBe(true);
    fireEvent.click(toggle());
    expect(filterColumn()?.hidden).toBe(false);
  });

  it('starts with every section closed, a section holding choices naming their count', () => {
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, state: ['BOOKED'] },
      onChange: vi.fn(),
      onReset: vi.fn(),
    });

    const sections = [...filterColumn()!.querySelectorAll('details')];
    expect(sections).toHaveLength(9);
    expect(sections.every((details) => !details.open)).toBe(true);
    expect(section('État')?.querySelector('summary')?.textContent).toContain('1 choix');
  });

  it('lays the sections out in the documented order', () => {
    renderFilters({
      accounts: [account],
      filters: DEFAULT_FILTERS,
      onChange: vi.fn(),
      onReset: vi.fn(),
    });
    const titles = [...filterColumn()!.querySelectorAll('summary')].map(
      (summary) => summary.textContent,
    );

    expect(titles).toEqual([
      'Période',
      'Sens',
      'Montant',
      'Comptes',
      'Catégories',
      'Nature',
      'État',
      'Origine',
      'Axes analytiques',
    ]);
    const text = filterColumn()!.textContent ?? '';
    const order = ['Axes analytiques', 'Afficher les transactions annulées'].map((label) =>
      text.indexOf(label),
    );

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

    expect(toggle().getAttribute('aria-label')).toBe('Filtres · 2');
    expect(toggle().textContent).toBe('2');
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

  it('toggles a state filter through its checkbox', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openSection('État');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Comptabilisée' }));

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: ['BOOKED'] }));
  });

  it('sets the direction from the Sens control', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openSection('Sens');
    fireEvent.click(screen.getByRole('button', { name: 'Sorties' }));

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ direction: 'OUTFLOW' }));
  });

  it('strips a typed minus and converts a comma in the amount bounds before state', () => {
    const onChange = vi.fn();
    renderFilters({ accounts: [account], filters: DEFAULT_FILTERS, onChange, onReset: vi.fn() });

    openSection('Montant');
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

  it('checks a category in the tree and names it on its chip', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [category], page: 1, pageSize: 100, total: 1 }),
    );
    renderControlledFilters({ accounts: [account], initialFilters: DEFAULT_FILTERS });

    openSection('Catégories');
    fireEvent.click(await screen.findByRole('checkbox', { name: 'Sorties' }));

    expect(screen.queryByText(category.id)).toBeNull();
    expect(await screen.findByRole('button', { name: /Retirer le filtre Sorties/ })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Sorties' })).toHaveProperty('checked', true);
  });

  it('keeps "Sans catégorie" and picked categories exclusive', async () => {
    api.listCategories.mockImplementation(() =>
      success({ items: [category], page: 1, pageSize: 100, total: 1 }),
    );
    renderControlledFilters({
      accounts: [account],
      initialFilters: { ...DEFAULT_FILTERS, axis: ['FIXED'], categoryId: [category.id] },
    });
    openSection('Catégories');
    const picked = await screen.findByRole('checkbox', { name: 'Sorties' });
    const uncategorized = screen.getByRole('checkbox', { name: 'Sans catégorie' });

    fireEvent.click(uncategorized);
    expect(uncategorized).toHaveProperty('checked', true);
    expect(picked).toHaveProperty('checked', false);
    expect(screen.queryByRole('button', { name: /Retirer le filtre Fixe/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Retirer le filtre Sans catégorie' })).toBeTruthy();

    fireEvent.click(picked);
    expect(uncategorized).toHaveProperty('checked', false);
    expect(picked).toHaveProperty('checked', true);
  });

  it('moves the amount bounds over the slider thresholds', () => {
    const onChange = vi.fn();
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, assetCode: 'EUR', maxAmount: '50', minAmount: '10' },
      onChange,
      onReset: vi.fn(),
    });
    openSection('Montant');
    const minimum = screen.getByRole('slider', { name: 'Montant minimum' });
    const maximum = screen.getByRole('slider', { name: 'Montant maximum' });
    expect(minimum.getAttribute('aria-valuetext')).toMatch(/^10,00\s€$/);
    expect(maximum.getAttribute('aria-valuetext')).toMatch(/^50,00\s€$/);

    fireEvent.change(maximum, { target: { value: '12' } });
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ assetCode: 'EUR', maxAmount: '', minAmount: '10' }),
    );

    fireEvent.change(minimum, { target: { value: '0' } });
    expect(onChange).toHaveBeenLastCalledWith(
      expect.objectContaining({ assetCode: 'EUR', maxAmount: '50', minAmount: '' }),
    );
  });

  it('opens the filters in a sheet on a narrow screen, with its own reset', () => {
    stubCompactScreen();
    const onReset = vi.fn();
    renderFilters({
      accounts: [account],
      filters: { ...DEFAULT_FILTERS, includeVoided: true },
      onChange: vi.fn(),
      onReset,
    });
    expect(screen.queryByRole('complementary')).toBeNull();
    expect(toggle().getAttribute('aria-haspopup')).toBe('dialog');

    fireEvent.click(toggle());
    const sheet = screen.getByRole('dialog', { name: 'Filtres' });
    expect(sheet.querySelectorAll('summary')).toHaveLength(9);

    fireEvent.click(within(sheet).getByRole('button', { name: 'Tout effacer' }));
    expect(onReset).toHaveBeenCalledOnce();

    fireEvent.click(within(sheet).getByRole('button', { name: 'Afficher les transactions' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  describe('counts', () => {
    const outflows: TransactionFacetsRequest = { enabled: true, query: { direction: 'OUTFLOW' } };
    const inflows: TransactionFacetsRequest = { enabled: true, query: { direction: 'INFLOW' } };

    function openEverySection() {
      for (const title of [
        'Comptes',
        'Catégories',
        'Nature',
        'État',
        'Origine',
        'Axes analytiques',
      ]) {
        openSection(title);
      }
    }

    /** One client for the whole test, so a later `update` sees what an earlier answer cached. */
    function mount(initial: Omit<FiltersProps, 'revision'>) {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
      });
      const tree = (props: Omit<FiltersProps, 'revision'>) => (
        <QueryClientProvider client={queryClient}>
          <TransactionFilters revision={0} {...props} />
        </QueryClientProvider>
      );
      const view = render(tree(initial));

      return {
        ...view,
        update: (props: Omit<FiltersProps, 'revision'>) => view.rerender(tree(props)),
      };
    }

    function baseProps(facetsRequest?: TransactionFacetsRequest, onChange = vi.fn()) {
      return {
        accounts: [account],
        facetsRequest,
        filters: DEFAULT_FILTERS,
        onChange,
        onReset: vi.fn(),
      };
    }

    function answerWith(counts: TransactionFacets = facets) {
      api.readTransactionFacets.mockImplementation(() => success(counts));
    }

    /** Lets the debounce of a changed request elapse, with nothing on screen to wait for. */
    async function settle() {
      await act(async () => {
        await new Promise((done) => setTimeout(done, 300));
      });
    }

    function stubCategories() {
      api.listCategories.mockImplementation(() =>
        success({ items: [category], page: 1, pageSize: 100, total: 1 }),
      );
    }

    function openSheet() {
      fireEvent.click(toggle());
      return screen.getByRole('dialog', { name: 'Filtres' });
    }

    function openSectionIn(scope: HTMLElement, title: string) {
      const summary = [...scope.querySelectorAll('summary')].find(
        (candidate) => candidate.textContent === title,
      )!;
      fireEvent.click(summary);
    }

    it('reads every option as its label then its count, each section from its own counts', async () => {
      stubCategories();
      answerWith();
      mount(baseProps(outflows));
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties, 13 transactions' });

      const names = [
        'Compte courant, 11 transactions',
        'Dépense, 4 transactions',
        'Revenu, 1 transaction',
        'Virement interne, 0 transaction',
        'Comptabilisée, 5 transactions',
        'En attente, 2 transactions',
        'Annulée, 1 transaction',
        'Rejetée, 0 transaction',
        'Import, 3 transactions',
        'Saisie manuelle, 0 transaction',
        'Fournisseur connecté, 0 transaction',
        'Essentiel, 7 transactions',
        'Fixe, 0 transaction',
      ];
      for (const name of names) {
        expect(screen.getByRole('checkbox', { name }), name).toBeTruthy();
      }
      expect(api.readTransactionFacets).toHaveBeenCalledOnce();
      expect(api.readTransactionFacets.mock.calls[0]?.[0].query).toEqual(outflows.query);
    });

    it('counts neither the uncategorized choice nor the voided switch', async () => {
      stubCategories();
      answerWith();
      mount(baseProps(outflows));
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties, 13 transactions' });

      expect(screen.getByRole('checkbox', { name: 'Sans catégorie' })).toBeTruthy();
      expect(
        screen.getByRole('checkbox', { name: 'Afficher les transactions annulées' }),
      ).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Inclure les sous-catégories' })).toBeTruthy();
    });

    it('shows a skeleton count on every option while pending and keeps the options usable', async () => {
      stubCategories();
      api.readTransactionFacets.mockImplementation(() => new Promise(() => undefined));
      const onChange = vi.fn();
      mount(baseProps(outflows, onChange));
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties' });

      expect(section('État')!.querySelectorAll('[data-skeleton]')).toHaveLength(4);
      expect(section('Nature')!.querySelectorAll('[data-skeleton]')).toHaveLength(6);
      const booked = screen.getByRole('checkbox', { name: 'Comptabilisée' });
      expect(booked).toHaveProperty('disabled', false);

      fireEvent.click(booked);
      expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: ['BOOKED'] }));
    });

    it('shows no count after a failed request while every filter keeps working', async () => {
      stubCategories();
      api.readTransactionFacets.mockImplementation(() =>
        Promise.resolve({ data: undefined, response: new Response('{}', { status: 500 }) }),
      );
      const onChange = vi.fn();
      mount(baseProps(outflows, onChange));
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties' });
      await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());

      await waitFor(() =>
        expect(filterColumn()!.querySelectorAll('[data-skeleton]')).toHaveLength(0),
      );
      expect(section('État')!.textContent).not.toMatch(/\d/);
      fireEvent.click(screen.getByRole('checkbox', { name: 'Dépense' }));
      expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ nature: ['EXPENSE'] }));
    });

    it('asks for nothing and shows no count where no request is passed, as on the account page', async () => {
      stubCategories();
      answerWith();
      mount(baseProps());
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties' });
      await settle();

      expect(api.readTransactionFacets).not.toHaveBeenCalled();
      expect(filterColumn()!.querySelectorAll('[data-skeleton]')).toHaveLength(0);
      expect(section('État')!.textContent).not.toMatch(/\d/);
      expect(section('Nature')!.textContent).not.toMatch(/\d/);
    });

    it('asks for nothing while the filters can match nothing', async () => {
      stubCategories();
      answerWith();
      mount(baseProps({ ...outflows, enabled: false }));
      openEverySection();
      await screen.findByRole('checkbox', { name: 'Sorties' });
      await settle();

      expect(api.readTransactionFacets).not.toHaveBeenCalled();
      expect(filterColumn()!.querySelectorAll('[data-skeleton]')).toHaveLength(0);
      expect(section('État')!.textContent).not.toMatch(/\d/);
    });

    describe('while the panel can be seen', () => {
      it('asks for no count while the filter column is hidden, and asks as soon as it is shown', async () => {
        window.localStorage.setItem('cadran.transactions.filters-hidden', '1');
        answerWith();
        mount(baseProps(outflows));
        await settle();

        expect(filterColumn()?.hidden).toBe(true);
        expect(api.readTransactionFacets).not.toHaveBeenCalled();

        fireEvent.click(toggle());
        openSection('État');

        expect(
          await screen.findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
        ).toBeTruthy();
        expect(api.readTransactionFacets).toHaveBeenCalledOnce();
      });

      it('stops asking once the column is hidden, whatever changes, and asks for the latest filters when it is shown', async () => {
        answerWith();
        const view = mount(baseProps(outflows));
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());

        fireEvent.click(toggle());
        expect(filterColumn()?.hidden).toBe(true);
        view.update(baseProps(inflows));
        await settle();
        expect(api.readTransactionFacets).toHaveBeenCalledOnce();

        fireEvent.click(toggle());
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledTimes(2));
        expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual(inflows.query);
      });

      it('shows the counts it already has when the column comes back with the same filters', async () => {
        answerWith();
        mount(baseProps(outflows));
        openSection('État');
        await screen.findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' });

        fireEvent.click(toggle());
        let resolveRefresh: (value: unknown) => void = () => {};
        api.readTransactionFacets.mockImplementation(
          () => new Promise((done) => (resolveRefresh = done)),
        );
        fireEvent.click(toggle());

        // The panel keeps its open sections, and the earlier answer is back before the refresh.
        expect(
          screen.getByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
        ).toBeTruthy();
        expect(section('État')!.querySelectorAll('[data-skeleton]')).toHaveLength(0);
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledTimes(2));

        resolveRefresh(await success({ ...facets, state: { BOOKED: 6 } }));
        expect(
          await screen.findByRole('checkbox', { name: 'Comptabilisée, 6 transactions' }),
        ).toBeTruthy();
      });

      it('asks for no count while the sheet of a narrow screen is closed, and once when it opens', async () => {
        stubCompactScreen();
        let resolveFirst: (value: unknown) => void = () => {};
        api.readTransactionFacets.mockImplementation(
          () => new Promise((done) => (resolveFirst = done)),
        );
        mount(baseProps(outflows));
        await settle();
        expect(api.readTransactionFacets).not.toHaveBeenCalled();

        const sheet = openSheet();
        openSectionIn(sheet, 'État');
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());
        // The first open has nothing to show yet: each option carries a skeleton count.
        expect(within(sheet).getByRole('checkbox', { name: 'Comptabilisée' })).toBeTruthy();
        expect(section('État')!.querySelectorAll('[data-skeleton]')).toHaveLength(4);

        resolveFirst(await success(facets));
        expect(
          await within(sheet).findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
        ).toBeTruthy();
        expect(api.readTransactionFacets).toHaveBeenCalledOnce();
      });

      it('shows the cached answer at once when the sheet reopens with the same filters', async () => {
        stubCompactScreen();
        answerWith();
        mount(baseProps(outflows));
        const sheet = openSheet();
        openSectionIn(sheet, 'État');
        await within(sheet).findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' });

        fireEvent.click(within(sheet).getByRole('button', { name: 'Fermer les filtres' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        await settle();
        let resolveRefresh: (value: unknown) => void = () => {};
        api.readTransactionFacets.mockImplementation(
          () => new Promise((done) => (resolveRefresh = done)),
        );

        const reopened = openSheet();
        openSectionIn(reopened, 'État');
        expect(
          within(reopened).getByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
        ).toBeTruthy();
        expect(reopened.querySelectorAll('[data-skeleton]')).toHaveLength(0);
        resolveRefresh(await success(facets));
      });

      it('asks again for the filters it holds when the sheet reopens after they changed', async () => {
        stubCompactScreen();
        answerWith();
        const view = mount(baseProps(outflows));
        const sheet = openSheet();
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());
        fireEvent.click(within(sheet).getByRole('button', { name: 'Fermer les filtres' }));

        view.update(baseProps(inflows));
        await settle();
        expect(api.readTransactionFacets).toHaveBeenCalledOnce();

        openSheet();
        await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledTimes(2));
        expect(api.readTransactionFacets.mock.calls[1]?.[0].query).toEqual(inflows.query);
      });
    });
  });
});
