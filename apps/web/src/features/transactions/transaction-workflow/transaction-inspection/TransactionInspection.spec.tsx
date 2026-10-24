import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionInspection } from './TransactionInspection';

const api = vi.hoisted(() => ({ getTransaction: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

const loaded = (rawLabel: string, displayLabel: string) => ({
  response: new Response('{}', { status: 200 }),
  data: {
    id: 't1',
    rawLabel,
    displayLabel,
    userLabel: rawLabel === displayLabel ? null : displayLabel,
    bookedOn: '2026-03-14',
    amount: { value: '-42.90', assetCode: 'EUR' },
    nature: 'EXPENSE',
    state: 'BOOKED',
    counterparty: null,
    note: null,
    bankReference: null,
  },
});

function renderInspection() {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <TransactionInspection transactionId="t1" close={vi.fn()} />
    </QueryClientProvider>,
  );
}

it('shows the display label and the source label once the transaction is renamed', async () => {
  api.getTransaction.mockResolvedValue(loaded('CB CARREFOUR 1234', 'Courses'));
  renderInspection();

  // The source label row is reserved while loading.
  expect(screen.getByText('Libellé d’origine')).toBeTruthy();
  expect(await screen.findByText('Courses')).toBeTruthy();
  expect(screen.getByText('CB CARREFOUR 1234')).toBeTruthy();
});

it('omits the source label row when it matches the display label', async () => {
  api.getTransaction.mockResolvedValue(loaded('CB CARREFOUR 1234', 'CB CARREFOUR 1234'));
  renderInspection();

  expect(await screen.findByText('CB CARREFOUR 1234')).toBeTruthy();
  expect(screen.queryByText('Libellé d’origine')).toBeNull();
});
