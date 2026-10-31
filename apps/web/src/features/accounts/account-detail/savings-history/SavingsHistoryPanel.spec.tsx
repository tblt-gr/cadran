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
  truncated: false,
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
it('uses the 12 month range by default and exposes exact dated amounts and freshness to keyboard and table readers', async () => {
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  expect(api.readAccountValuationHistory.mock.calls[0][0].query).toEqual({
    from: '2025-10-20',
    to: '2026-10-20',
  });
  const hit = screen.getByRole('slider', { name: 'Historique du solde' });
  act(() => hit.focus());
  fireEvent.keyDown(hit, { key: 'End' });
  expect(screen.getByRole('tooltip').textContent).toContain('123456789012345678901234');
  expect(screen.getByRole('tooltip').textContent).toContain('Dernier solde connu');
  expect(screen.getByRole('table').textContent).toContain('01/10/2026');
  fireEvent.click(screen.getByRole('button', { name: 'Depuis janvier' }));
  await waitFor(() =>
    expect(api.readAccountValuationHistory.mock.calls.at(-1)?.[0].query.from).toBe('2025-12-31'),
  );
});
it('offers Tout, 12 mois and Depuis janvier in canonical order, defaulting to 12 mois', async () => {
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  const group = screen.getByRole('group', { name: 'Période de l’historique' });
  expect(Array.from(group.querySelectorAll('button')).map((b) => b.textContent)).toEqual([
    'Tout',
    '12 mois',
    'Depuis janvier',
  ]);
  expect(screen.getByRole('button', { name: '12 mois' }).getAttribute('aria-pressed')).toBe('true');
});
it('asks the API for the full history without any from when Tout is chosen', async () => {
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  fireEvent.click(screen.getByRole('button', { name: 'Tout' }));
  await waitFor(() => expect(api.readAccountValuationHistory.mock.calls).toHaveLength(2));
  const query = api.readAccountValuationHistory.mock.calls[1][0].query;
  expect(query).toEqual({ to: '2026-10-20' });
  expect('from' in query).toBe(false);
  expect(screen.queryByText(/Historique limité/)).toBeNull();
});
it('states that Tout is truncated when the API caps the window', async () => {
  api.readAccountValuationHistory.mockImplementation(() => ok({ ...data, truncated: true }));
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  expect(screen.getByText(/Historique limité aux 10 dernières années/)).toBeTruthy();
});
it('keeps the previous chart and flags the panel busy while a new range loads', async () => {
  const { container } = mount();
  await waitFor(() => expect(container.querySelector('[aria-busy="true"]')).toBeNull());
  api.readAccountValuationHistory.mockReturnValue(new Promise(() => {}));
  fireEvent.click(screen.getByRole('button', { name: 'Depuis janvier' }));
  await waitFor(() =>
    expect(container.querySelector('section')?.getAttribute('aria-busy')).toBe('true'),
  );
  expect(container.querySelector('[data-skeleton]')).toBeNull();
  expect(screen.getByRole('slider', { name: 'Historique du solde' })).toBeTruthy();
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
