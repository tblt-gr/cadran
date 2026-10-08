import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { TaxTrackingPanel } from './TaxTrackingPanel';
const api = vi.hoisted(() => ({ readLifeInsuranceSummary: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
beforeEach(() => vi.resetAllMocks());
afterEach(cleanup);
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <TaxTrackingPanel accountId="account-1" asOf="2026-03-10" />
    </QueryClientProvider>,
  );
  return client;
}
it('offers retry when the fiscal read fails and then replaces the loading values', async () => {
  api.readLifeInsuranceSummary.mockResolvedValue({ response: new Response('{}', { status: 404 }) });
  mount();
  expect(await screen.findByRole('alert')).toBeTruthy();
  api.readLifeInsuranceSummary.mockResolvedValue({
    data: summaryFixture(),
    response: new Response('{}', { status: 200 }),
  });
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  expect(await screen.findByText(/Contrat ouvert depuis 6 ans/)).toBeTruthy();
  await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
});
it('retains recorded fiscal amounts on a failed refresh', async () => {
  api.readLifeInsuranceSummary.mockResolvedValue({
    data: summaryFixture(),
    response: new Response('{}', { status: 200 }),
  });
  const client = mount();
  await screen.findByText(/Contrat ouvert depuis 6 ans/);
  api.readLifeInsuranceSummary.mockRejectedValue(new Error('offline'));
  await client.invalidateQueries({ queryKey: ['life-insurance-summary'] });
  expect(await screen.findByText('Les dernières données restent affichées.')).toBeTruthy();
  expect(screen.getByText(/34,40/)).toBeTruthy();
});
