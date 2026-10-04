import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';
import '@/i18n';
import { historyFixture } from '@/features/portfolio/portfolio-account/value-history-card/valueHistoryFixtures';
import { accountCardFixture, totalFixture } from './portfoliosFixtures';
import { PortfoliosPage } from './PortfoliosPage';
const api = vi.hoisted(() => ({
  listPortfolios: vi.fn(),
  readPortfolioValuationHistory: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
beforeEach(() => {
  api.readPortfolioValuationHistory.mockResolvedValue({
    data: historyFixture(),
    response: new Response('{}'),
  });
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});
function renderPage() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <PortfoliosPage />
    </QueryClientProvider>,
  );
}
describe('PortfoliosPage', () => {
  it('shows title, type, latest total and the curve of one account', async () => {
    api.listPortfolios.mockResolvedValue({
      data: {
        items: [accountCardFixture()],
        truncated: false,
        total: totalFixture(),
      },
      response: new Response('{}'),
    });
    renderPage();
    expect(await screen.findByRole('link', { name: 'Mon PEA' })).toBeTruthy();
    expect(screen.getByText('Courtier')).toBeTruthy();
    expect(screen.getByText(/3\s000,42/)).toBeTruthy();
    expect(await screen.findByRole('group', { name: 'Valeur totale en EUR' })).toBeTruthy();
    expect(api.readPortfolioValuationHistory).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Espèces')).toBeNull();
  });

  it('keeps real title and navigation during loading', () => {
    api.listPortfolios.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    expect(screen.queryByRole('heading', { name: 'Investissements' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Total investissements' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Instruments' })).toBeTruthy();
    expect(screen.getByTestId('portfolios-skeleton')).toBeTruthy();
  });
  it('links an empty workspace to account creation', async () => {
    api.listPortfolios.mockResolvedValue({
      data: { items: [], truncated: false, total: totalFixture({ reason: 'NO_VALUATION' }) },
      response: new Response('{}'),
    });
    renderPage();
    expect(await screen.findByText(/Aucun compte d’investissement/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Créer un compte' }).getAttribute('href')).toBe(
      '/accounts',
    );
  });
  it('shows an unvalued account without a curve', async () => {
    api.listPortfolios.mockResolvedValue({
      data: {
        items: [
          accountCardFixture({
            label: 'PEA',
            latestValuation: null,
            unrealizedGain: {
              value: null,
              costBasis: null,
              performance: null,
              reason: 'NO_VALUATION',
            },
          }),
        ],
        truncated: false,
        total: totalFixture({ reason: 'NO_VALUATION', totalValue: null }),
      },
      response: new Response('{}'),
    });
    api.readPortfolioValuationHistory.mockResolvedValue({
      data: historyFixture({ points: [] }),
      response: new Response('{}'),
    });
    renderPage();
    expect((await screen.findByRole('link', { name: 'PEA' })).getAttribute('href')).toBe(
      '/portfolios/account-1',
    );
    expect(screen.getAllByText('Non valorisé').length).toBeGreaterThan(0);
    expect(await screen.findByText('Aucune valorisation.')).toBeTruthy();
  });
  it('shows the total card above the accounts', async () => {
    api.listPortfolios.mockResolvedValue({
      data: { items: [accountCardFixture()], truncated: false, total: totalFixture() },
      response: new Response('{}'),
    });
    renderPage();
    expect(await screen.findByText(/4\s500,00/)).toBeTruthy();
    expect(screen.getByText('+9,76 %')).toBeTruthy();
  });
  it.each([403, 500])('shows the %s state with recovery', async (status) => {
    api.listPortfolios.mockResolvedValue({ response: new Response('{}', { status }) });
    renderPage();
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });
});
