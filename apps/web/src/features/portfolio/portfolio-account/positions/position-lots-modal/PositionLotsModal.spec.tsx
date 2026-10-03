import type { PortfolioPosition, PortfolioPositionLots } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PositionLotsModal } from './PositionLotsModal';

const api = vi.hoisted(() => ({ readPortfolioPositionLots: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const position = {
  instrument: {
    id: 'instrument-1',
    name: 'ETF Monde',
    isin: 'FR0014000MR3',
    ticker: 'CW8',
    status: 'ACTIVE',
    quantityPrecision: 6,
  },
  quantity: '3',
  fifoCostBasis: { value: '360.60', assetCode: 'EUR' },
  averageCostBasis: { value: '320.60', assetCode: 'EUR' },
  averageUnitCost: { value: '106.866666666666666666666667', assetCode: 'EUR' },
  costReason: null,
  firstAcquiredOn: '2026-01-10',
  lastOperationOn: '2026-03-04',
  openLotCount: 1,
  flags: [],
} as PortfolioPosition;

const lots = {
  asOf: '2026-03-04',
  instrument: position.instrument,
  openLots: [
    {
      lotId: 'buy-2#0',
      originOperationId: 'buy-2',
      originType: 'BUY',
      acquiredOn: '2026-02-10',
      originalQuantity: '5',
      openQuantity: '3',
      costBasis: null,
      unitCost: null,
    },
  ],
  movements: [
    {
      operationId: 'buy-2',
      tradeDate: '2026-02-10',
      sequence: 2,
      type: 'BUY',
      quantityDelta: '5',
      costDelta: null,
      lotId: 'buy-2#0',
    },
  ],
  fifoCostBasis: null,
} as PortfolioPositionLots;

function renderModal() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PositionLotsModal
        accountId="account-1"
        asOf="2026-03-04"
        onClose={vi.fn()}
        position={position}
      />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PositionLotsModal', () => {
  it('keeps its final chrome during loading then shows lots and unknown costs', async () => {
    let resolve: ((value: unknown) => void) | undefined;
    api.readPortfolioPositionLots.mockImplementation(() => new Promise((done) => (resolve = done)));
    renderModal();

    expect(screen.getByRole('dialog', { name: 'Lots : ETF Monde' })).toBeTruthy();
    expect(screen.getByText('Lots ouverts')).toBeTruthy();
    expect(screen.getByText('Mouvements')).toBeTruthy();
    expect(screen.getByTestId('position-lots-skeleton')).toBeTruthy();

    resolve?.({ data: lots, response: new Response('{}', { status: 200 }) });
    await screen.findAllByText('Achat');
    const lotTable = screen.getByRole('table', { name: 'Lots ouverts' });
    const origin = within(lotTable).getByText('Achat').closest('a');
    expect(origin?.getAttribute('href')).toBe(
      '/portfolios/account-1?tab=operations&operation=buy-2',
    );
    expect(within(lotTable).getAllByText('Coût inconnu')).toHaveLength(2);
    expect(screen.getAllByText('Coût inconnu')).toHaveLength(4);
    expect(screen.queryByTestId('position-lots-skeleton')).toBeNull();
  });
});
