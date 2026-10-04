import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { annualFixture, emptyYearFixture } from './annualFixtures';
import { PerformanceTab } from './PerformanceTab';

const api = vi.hoisted(() => ({
  getSession: vi.fn(),
  readPortfolioAnnualPerformance: vi.fn(),
  readPortfolioPerformance: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const accountId = 'account-1';
const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
const refused = (status: number, body: object) =>
  Promise.resolve({ error: body, response: new Response('{}', { status }) });

function renderTab(asOf = '2026-03-10') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const session = {
    provisioned: true,
    authenticated: true,
    setupRequired: false,
    user: { id: 'u', email: 'o@example.test', displayName: 'Owner' },
    workspace: { id: 'w', role: 'OWNER', timeZone: 'Europe/Paris', baseCurrency: 'EUR' },
  };
  client.setQueryData(['session'], session);
  api.getSession.mockImplementation(() => ok(session));
  return render(
    <QueryClientProvider client={client}>
      <PerformanceTab accountId={accountId} asOf={asOf} />
    </QueryClientProvider>,
  );
}

async function loaded() {
  await waitFor(() => expect(screen.queryByTestId('performance-skeleton')).toBeNull());
}

beforeEach(() => {
  window.history.replaceState({}, '', `/portfolios/${accountId}?tab=performance`);
  api.readPortfolioAnnualPerformance.mockImplementation(() => ok(annualFixture()));
  api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PerformanceTab', () => {
  it('shows the real chrome while loading then the summary, curve and month table', async () => {
    renderTab();
    expect(screen.getByTestId('performance-skeleton')).toBeTruthy();
    expect(screen.getByRole('heading', { name: '2026' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Année précédente' }).hasAttribute('disabled')).toBe(
      true,
    );
    expect(screen.getByRole('heading', { name: 'Rendement chaîné (base 100)' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Valeur début' })).toBeTruthy();

    await loaded();
    expect(api.readPortfolioAnnualPerformance).toHaveBeenCalledWith(
      expect.objectContaining({ path: { accountId }, query: { year: '2026' } }),
    );
    expect(screen.getByRole('heading', { name: 'Année 2026' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: "Depuis l'ouverture" })).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Performance par mois' })).toBeTruthy();
  });

  it('defaults the year to the year of Au and reads ?year= from the URL', async () => {
    window.history.replaceState({}, '', `/portfolios/${accountId}?tab=performance&year=2025`);
    renderTab();
    await waitFor(() =>
      expect(api.readPortfolioAnnualPerformance).toHaveBeenCalledWith(
        expect.objectContaining({ query: { year: '2025' } }),
      ),
    );
  });

  it('navigates between years, writes the URL and disables the buttons at the bounds', async () => {
    renderTab();
    await loaded();
    const next = screen.getByRole('button', { name: 'Année suivante' });
    const previous = screen.getByRole('button', { name: 'Année précédente' });
    expect(next.hasAttribute('disabled')).toBe(true);
    expect(previous.hasAttribute('disabled')).toBe(false);

    fireEvent.click(previous);
    expect(window.location.search).toContain('year=2025');
    expect(window.location.search).toContain('tab=performance');
    await waitFor(() =>
      expect(api.readPortfolioAnnualPerformance).toHaveBeenCalledWith(
        expect.objectContaining({ query: { year: '2025' } }),
      ),
    );
  });

  it('shows the table of dashes with the empty-year notice when nothing is valued', async () => {
    api.readPortfolioAnnualPerformance.mockImplementation(() => ok(emptyYearFixture()));
    renderTab();
    await loaded();
    expect(screen.getByText('Aucune valorisation pour cette année')).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Performance par mois' })).toBeTruthy();
  });

  it('shows an error with Réessayer that refetches', async () => {
    api.readPortfolioAnnualPerformance.mockImplementationOnce(() => refused(500, {}));
    renderTab();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('La performance est indisponible');
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await waitFor(() =>
      expect(screen.getByRole('table', { name: 'Performance par mois' })).toBeTruthy(),
    );
  });

  it('explains an out-of-range year', async () => {
    api.readPortfolioAnnualPerformance.mockImplementationOnce(() =>
      refused(422, { code: 'invalid_performance_period' }),
    );
    renderTab();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain("Cette année n'est pas disponible pour ce compte");
    fireEvent.click(within(alert).getByRole('button', { name: "Revenir à l'année en cours" }));
    await waitFor(() =>
      expect(screen.getByRole('table', { name: 'Performance par mois' })).toBeTruthy(),
    );
  });

  it('opens the explanation of a month figure fetched on demand', async () => {
    renderTab();
    await loaded();
    const row = screen.getByRole('row', { name: /^janvier/ });
    fireEvent.click(within(row).getByRole('button', { name: /Gain total/ }));
    await waitFor(() =>
      expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
        expect.objectContaining({ query: { scope: 'MONTH', period: '2026-01' } }),
      ),
    );
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
  });

  it('opens the summary explanation with the year response already loaded', async () => {
    renderTab();
    await loaded();
    const summary = screen.getByRole('region', { name: 'Année 2026' });
    await waitFor(() =>
      expect(
        within(summary)
          .getByRole('button', { name: /Gain total/ })
          .hasAttribute('disabled'),
      ).toBe(false),
    );
    fireEvent.click(within(summary).getByRole('button', { name: /Gain total/ }));
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
    expect(api.readPortfolioPerformance).not.toHaveBeenCalledWith(
      expect.objectContaining({ query: { scope: 'MONTH', period: expect.any(String) } }),
    );
  });

  it('renders cards instead of the table under 768 px', async () => {
    window.matchMedia = ((query: string) => ({
      matches: query.includes('max-width: 767px'),
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
    renderTab();
    await loaded();
    expect(screen.queryByRole('table', { name: 'Performance par mois' })).toBeNull();
    expect(screen.getAllByRole('article', { name: /janvier/ })).toHaveLength(1);
    // @ts-expect-error restore the jsdom default of no matchMedia
    delete window.matchMedia;
  });
});
