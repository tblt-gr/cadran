import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { LifeInsuranceAllocationCard } from '@/features/portfolio/life-insurance/overview/allocation-card/LifeInsuranceAllocationCard';
import { SupportsTable } from '@/features/portfolio/life-insurance/overview/supports-table/SupportsTable';
import { QualityIssues } from '@/features/portfolio/life-insurance/overview/quality-issues/QualityIssues';
import { RecordedAmounts } from '@/features/portfolio/life-insurance/overview/recorded-amounts/RecordedAmounts';
import { LifeInsuranceSummaryPanel } from './LifeInsuranceSummaryPanel';
const api = vi.hoisted(() => ({ readLifeInsuranceSummary: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = (data: unknown) => ({ data, response: new Response('{}', { status: 200 }) });
beforeEach(() => {
  vi.resetAllMocks();
  api.readLifeInsuranceSummary.mockResolvedValue(ok(summaryFixture()));
});
afterEach(cleanup);
function mount(frozen = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <LifeInsuranceSummaryPanel
        accountId="account-1"
        asOf="2026-03-10"
        frozen={frozen}
        title="Rapport indisponible"
      >
        {(summary) => (
          <>
            <LifeInsuranceAllocationCard summary={summary} />
            <SupportsTable summary={summary} />
            <QualityIssues summary={summary} accountId="account-1" />
            <RecordedAmounts summary={summary} accountId="account-1" />
          </>
        )}
      </LifeInsuranceSummaryPanel>
    </QueryClientProvider>,
  );
  return client;
}
it('preserves destination headings and known labels while frozen without requesting data', () => {
  mount(true);
  for (const title of [
    'Répartition',
    'Supports du contrat',
    'Qualité des données',
    'Montants cumulés enregistrés',
  ])
    expect(screen.getByRole('heading', { name: title })).toBeTruthy();
  expect(screen.getAllByText('Fonds en euros').length).toBeGreaterThan(0);
  expect(screen.getByRole('rowheader', { name: 'Versements bruts' })).toBeTruthy();
  expect(screen.getAllByRole('status')).toHaveLength(1);
  expect(api.readLifeInsuranceSummary).not.toHaveBeenCalled();
  expect(screen.queryByText(/9\s293,00/)).toBeNull();
});
it.each(['NO_VALUATION', 'NOT_DETAILED'] as const)(
  'retains explicit %s destination states',
  async (status) => {
    const data = summaryFixture();
    data.allocation = { status, items: [] };
    data.supports = [];
    if (status === 'NO_VALUATION') data.valuation = null;
    else data.valuation = { ...data.valuation!, detail: 'TOTAL' };
    api.readLifeInsuranceSummary.mockResolvedValue(ok(data));
    mount();
    expect(
      (
        await screen.findAllByText(
          status === 'NO_VALUATION'
            ? 'Enregistrez une valorisation pour voir la répartition.'
            : 'Répartition indisponible : la dernière valorisation ne détaille pas les supports.',
        )
      ).length,
    ).toBeGreaterThan(0);
    expect(screen.queryByRole('region', { name: 'Répartition détaillée' })).toBeNull();
  },
);
it('keeps cached destination details after a failed refresh and retries successfully', async () => {
  const client = mount();
  await waitFor(() => expect(screen.queryByTestId('allocation-donut-skeleton')).toBeNull());
  expect(
    within(screen.getByRole('region', { name: 'Répartition détaillée' })).getByText(/4\s124,20/),
  ).toBeTruthy();
  api.readLifeInsuranceSummary.mockRejectedValue(new Error('offline'));
  await client.invalidateQueries({ queryKey: ['life-insurance-summary'] });
  expect(await screen.findByText('Les dernières données restent affichées.')).toBeTruthy();
  expect(
    within(screen.getByRole('region', { name: 'Répartition détaillée' })).getByText(/4\s124,20/),
  ).toBeTruthy();
  api.readLifeInsuranceSummary.mockResolvedValue(ok(summaryFixture()));
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
});
it('replaces destination data with dated setup navigation when no contract exists', async () => {
  api.readLifeInsuranceSummary.mockResolvedValue(ok({ ...summaryFixture(), contract: null }));
  mount();
  expect(
    (await screen.findByRole('link', { name: 'Renseigner le contrat' })).getAttribute('href'),
  ).toBe('/portfolios/account-1?tab=contract&asOf=2026-03-10');
  expect(screen.queryByRole('heading', { name: 'Supports du contrat' })).toBeNull();
});
