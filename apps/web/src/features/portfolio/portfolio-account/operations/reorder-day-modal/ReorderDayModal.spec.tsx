import type { PortfolioOperation } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { operationFixture } from '@/features/portfolio/portfolio-account/operations/operations-table/operationFixtures';
import { ReorderDayModal } from './ReorderDayModal';

const api = vi.hoisted(() => ({
  listPortfolioOperations: vi.fn(),
  reorderPortfolioOperations: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const buy = operationFixture({ id: 'buy-1', type: 'BUY', sequence: 1 });
const sell = operationFixture({ id: 'sell-1', type: 'SELL', sequence: 2 });

function response(items: PortfolioOperation[]) {
  return Promise.resolve({
    data: { items, page: 1, perPage: 100, total: items.length },
    response: new Response('{}', { status: 200 }),
  });
}

function renderModal(onSaved = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <ReorderDayModal
        accountId="account-1"
        onClose={vi.fn()}
        onSaved={onSaved}
        tradeDate="2026-03-01"
      />
    </QueryClientProvider>,
  );
  return { onSaved };
}

beforeEach(() => {
  api.listPortfolioOperations.mockImplementation(() => response([buy, sell]));
  api.reorderPortfolioOperations.mockImplementation(() =>
    Promise.resolve({ data: undefined, response: new Response('', { status: 204 }) }),
  );
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('ReorderDayModal', () => {
  it('exposes labelled keyboard-focusable move controls and saves their order', async () => {
    renderModal();
    const down = await screen.findByRole('button', { name: 'Descendre Achat' });
    down.focus();
    expect(document.activeElement).toBe(down);
    fireEvent.click(down);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’ordre' }));

    await waitFor(() => expect(api.reorderPortfolioOperations).toHaveBeenCalled());
    expect(api.reorderPortfolioOperations.mock.calls[0]![0].body.operationIds).toEqual([
      'sell-1',
      'buy-1',
    ]);
  });

  it('reports a stale set, refetches it and resets the displayed order', async () => {
    api.reorderPortfolioOperations.mockImplementation(() =>
      Promise.resolve({
        error: { code: 'stale_operation_order', type: '/problems/stale-operation-order' },
        response: new Response('{}', { status: 409 }),
      }),
    );
    const onSaved = vi.fn();
    renderModal(onSaved);
    fireEvent.click(await screen.findByRole('button', { name: 'Descendre Achat' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer l’ordre' }));

    expect(
      await screen.findByText(
        'Les opérations de cette journée ont changé. L’ordre a été actualisé.',
      ),
    ).toBeTruthy();
    await waitFor(() => expect(api.listPortfolioOperations).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const labels = within(screen.getByRole('list')).getAllByText(/Achat|Vente/);
    expect(labels.map((label) => label.textContent)).toEqual(['Achat', 'Vente']);
  });

  it('renders the final table chrome while the day is loading', () => {
    api.listPortfolioOperations.mockImplementation(() => new Promise(() => undefined));
    renderModal();

    expect(screen.getByTestId('reorder-day-skeleton')).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Opération' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Ordre' })).toBeTruthy();
  });
});
