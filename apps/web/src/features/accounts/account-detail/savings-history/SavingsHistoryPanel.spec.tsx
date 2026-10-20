import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { SavingsHistoryPanel } from './SavingsHistoryPanel';
const api = vi.hoisted(() => ({ readAccountValuationHistory: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const point = {
  date: '2026-10-01',
  amount: '1000',
  assetCode: 'EUR',
  quality: 'CURRENT',
  source: 'MANUAL',
  reason: null,
  asOf: '2026-10-01',
  ageDays: 0,
  basis: 'SNAPSHOT',
  derivation: null,
};
const data = {
  accountId: 'savings',
  from: '2025-10-20',
  to: '2026-10-20',
  hasHistory: true,
  points: [
    point,
    {
      ...point,
      date: '2026-10-10',
      amount: '1400.123456789012345678901234',
      quality: 'STALE',
      ageDays: 9,
    },
  ],
};
function ok(value: unknown) {
  return Promise.resolve({ data: value, response: new Response('{}', { status: 200 }) });
}
function mount() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <SavingsHistoryPanel accountId="savings" today="2026-10-20" />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  api.readAccountValuationHistory.mockReset().mockImplementation(() => ok(data));
});
afterEach(cleanup);
it('uses bounded periods and exposes exact dated amounts and freshness to keyboard and table readers', async () => {
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  expect(api.readAccountValuationHistory.mock.calls[0][0].query).toEqual({
    from: '2025-10-20',
    to: '2026-10-20',
  });
  const hit = screen.getByRole('button', { name: /10\/10\/2026, / });
  act(() => hit.focus());
  expect(screen.getByRole('tooltip').textContent).toContain('123456789012345678901234');
  expect(screen.getByRole('tooltip').textContent).toContain('Dernier solde connu');
  expect(screen.getByRole('table').textContent).toContain('01/10/2026');
  fireEvent.click(screen.getByRole('button', { name: 'Année en cours' }));
  await waitFor(() =>
    expect(api.readAccountValuationHistory.mock.calls.at(-1)?.[0].query.from).toBe('2026-01-01'),
  );
});
it('keeps static chrome and disabled controls while loading', () => {
  api.readAccountValuationHistory.mockReturnValue(new Promise(() => {}));
  const { container } = mount();
  expect(screen.getByRole('heading', { name: 'Historique du solde' })).toBeTruthy();
  expect((screen.getByRole('button', { name: '12 mois' }) as HTMLButtonElement).disabled).toBe(
    true,
  );
  expect(container.querySelector('[data-skeleton]')).toBeTruthy();
  expect(container.querySelectorAll('tbody th time [data-skeleton]')).toHaveLength(3);
  expect(screen.getAllByRole('status')).toHaveLength(1);
});
it('shows an explicit empty history and retries failed reads', async () => {
  api.readAccountValuationHistory
    .mockResolvedValueOnce({ error: {}, response: new Response('{}', { status: 500 }) })
    .mockImplementation(() => ok({ ...data, hasHistory: false, points: [] }));
  mount();
  const alert = await screen.findByRole('alert');
  fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
  expect(await screen.findByText('Aucun solde enregistré sur cette période.')).toBeTruthy();
});
it('preserves a reasoned gap without substituting zero', async () => {
  api.readAccountValuationHistory.mockImplementation(() =>
    ok({
      ...data,
      points: [{ ...point, amount: null, quality: 'MISSING', reason: 'MIXED_ASSETS' }],
    }),
  );
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  const table = screen.getByRole('table');
  expect(table.textContent).toContain('Actifs incompatibles');
  expect(screen.queryByRole('button', { name: /01\/10\/2026,/ })).toBeNull();
});
