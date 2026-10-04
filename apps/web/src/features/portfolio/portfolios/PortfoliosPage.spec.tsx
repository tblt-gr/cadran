import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest';
import '@/i18n';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
import { historyFixture } from '@/features/portfolio/portfolio-account/value-history-card/valueHistoryFixtures';
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
    const valuation = { ...summaryFixture().valuation!, valuedOn: '2026-09-30' };
    api.listPortfolios.mockResolvedValue({
      data: {
        items: [
          {
            accountId: 'account-1',
            label: 'Mon PEA',
            institution: 'Courtier',
            productCode: 'PEA',
            assetCode: 'EUR',
            status: 'ACTIVE',
            latestValuation: valuation,
            dueMonths: [],
            newerPartialValuation: null,
            currentCash: '100',
            positionCount: 1,
          },
        ],
        truncated: false,
      },
      response: new Response('{}'),
    });
    renderPage();
    expect(await screen.findByRole('link', { name: 'Mon PEA' })).toBeTruthy();
    expect(screen.getByText('PEA')).toBeTruthy();
    expect(screen.getByText(/3\s000,42/)).toBeTruthy();
    expect(await screen.findByRole('group', { name: 'Valeur totale en EUR' })).toBeTruthy();
    expect(api.readPortfolioValuationHistory).toHaveBeenCalledTimes(1);
    expect(screen.queryByText('Espèces')).toBeNull();
  });

  it('keeps real title and navigation during loading', () => {
    api.listPortfolios.mockImplementation(() => new Promise(() => undefined));
    renderPage();
    expect(screen.getByRole('heading', { name: 'Investissements' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Instruments' })).toBeTruthy();
    expect(screen.getByTestId('portfolios-skeleton')).toBeTruthy();
  });
  it('links an empty workspace to account creation', async () => {
    api.listPortfolios.mockResolvedValue({
      data: { items: [], truncated: false },
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
          {
            accountId: 'account-1',
            label: 'PEA',
            institution: 'Courtier',
            productCode: 'PEA',
            assetCode: 'EUR',
            status: 'ACTIVE',
            latestValuation: null,
            dueMonths: [],
            newerPartialValuation: null,
            currentCash: '100',
            positionCount: 0,
          },
        ],
        truncated: false,
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
    expect(screen.getByText('Non valorisé')).toBeTruthy();
    expect(await screen.findByText('Aucune valorisation.')).toBeTruthy();
  });
  it.each([403, 500])('shows the %s state with recovery', async (status) => {
    api.listPortfolios.mockResolvedValue({ response: new Response('{}', { status }) });
    renderPage();
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });
});
