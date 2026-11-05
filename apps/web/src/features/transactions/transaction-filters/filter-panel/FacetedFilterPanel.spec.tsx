import type { TransactionFacets } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DEFAULT_FILTERS } from '@/features/transactions/transaction-filters/filterState';
import { FacetedFilterPanel } from './FacetedFilterPanel';

const api = vi.hoisted(() => ({ listCategories: vi.fn(), readTransactionFacets: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const facets: TransactionFacets = {
  accountId: {},
  categoryId: {},
  nature: {},
  state: { BOOKED: 5, PENDING: 2 },
  source: {},
  axis: {},
};

const query = { direction: 'OUTFLOW' } as const;

function mount(facetsEnabled: boolean, onChange = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const tree = (enabled: boolean) => (
    <QueryClientProvider client={queryClient}>
      <FacetedFilterPanel
        accounts={[]}
        activeCount={0}
        assetCodes={[]}
        disabled={false}
        facetsEnabled={enabled}
        facetsQuery={query}
        filters={DEFAULT_FILTERS}
        onChange={onChange}
        revision={0}
        titleId="filters-title"
      />
    </QueryClientProvider>
  );
  const view = render(tree(facetsEnabled));

  return { enable: () => view.rerender(tree(true)), onChange };
}

function openStates() {
  const summary = [...document.querySelectorAll('summary')].find(
    (candidate) => candidate.textContent === 'État',
  )!;
  fireEvent.click(summary);
}

describe('FacetedFilterPanel', () => {
  beforeEach(() => {
    api.listCategories.mockResolvedValue({
      data: { items: [], page: 1, pageSize: 100, total: 0 },
      response: new Response('{}', { status: 200 }),
    });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('counts the options under the given filters once the answer arrives', async () => {
    api.readTransactionFacets.mockResolvedValue({
      data: facets,
      response: new Response(JSON.stringify(facets), { status: 200 }),
    });
    mount(true);
    openStates();

    expect(
      await screen.findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
    ).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Annulée, 0 transaction' })).toBeTruthy();
    expect(api.readTransactionFacets).toHaveBeenCalledOnce();
    expect(api.readTransactionFacets.mock.calls[0]?.[0].query).toEqual(query);
  });

  it('asks for nothing and shows no count while disabled, then asks once it is enabled', async () => {
    api.readTransactionFacets.mockResolvedValue({
      data: facets,
      response: new Response(JSON.stringify(facets), { status: 200 }),
    });
    const { enable } = mount(false);
    openStates();
    await act(async () => {
      await new Promise((done) => setTimeout(done, 300));
    });

    expect(api.readTransactionFacets).not.toHaveBeenCalled();
    expect(screen.getByRole('checkbox', { name: 'Comptabilisée' })).toBeTruthy();
    expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(0);

    enable();
    await waitFor(() => expect(api.readTransactionFacets).toHaveBeenCalledOnce());
    expect(
      await screen.findByRole('checkbox', { name: 'Comptabilisée, 5 transactions' }),
    ).toBeTruthy();
  });

  it('keeps every other behaviour of the plain panel', () => {
    api.readTransactionFacets.mockImplementation(() => new Promise(() => undefined));
    const { onChange } = mount(true);
    openStates();

    fireEvent.click(screen.getByRole('checkbox', { name: 'Comptabilisée' }));

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: ['BOOKED'] }));
  });
});
