import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { BalanceSnapshotsPanel } from './BalanceSnapshotsPanel';

const api = vi.hoisted(() => ({
  listAccountBalances: vi.fn(),
  withdrawAccountBalance: vi.fn(),
}));

vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

const active = {
  id: '00000000-0000-7000-8000-0000000000b1',
  accountId: 'courant',
  asOf: '2026-01-15',
  amount: { value: '100.00', assetCode: 'EUR' },
  source: 'MANUAL' as const,
  reconciliationStatus: 'UNRECONCILED' as const,
  comment: null,
  active: true,
  version: 1,
  recordedAt: '2026-10-17T09:00:00+00:00',
  supersededAt: null,
};

function ok(value: unknown, status = 200) {
  return Promise.resolve({ data: value, response: new Response('{}', { status }) });
}

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <BalanceSnapshotsPanel accountId="courant" />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  api.listAccountBalances.mockImplementation(() =>
    ok({ items: [active], page: 1, pageSize: 100, total: 1 }),
  );
});

afterEach(() => cleanup());

it('lists an active relevé and withdraws it', async () => {
  api.withdrawAccountBalance.mockImplementation(() =>
    ok({ ...active, active: false, version: 2, supersededAt: '2026-10-21T16:00:00+00:00' }),
  );
  renderPanel();

  expect(await screen.findByRole('heading', { name: 'Relevés' })).toBeTruthy();
  expect(await screen.findByText('100,00 €')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: 'Retirer le relevé du 15/01/2026' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Retirer le relevé' }));

  await waitFor(() => expect(api.withdrawAccountBalance).toHaveBeenCalledOnce());
  expect(api.withdrawAccountBalance.mock.calls[0]?.[0].body).toEqual({ version: 1 });
  expect(api.withdrawAccountBalance.mock.calls[0]?.[0].path).toEqual({
    id: 'courant',
    snapshotId: active.id,
  });
});
