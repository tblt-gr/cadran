import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, it, expect, vi } from 'vitest';
import '@/i18n';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
import { PortfoliosPage } from './PortfoliosPage';
const api = vi.hoisted(() => ({ listPortfolios: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
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
  it('dates retained complete values and warns about the newer partial observation', async () => {
    const valuation = { ...summaryFixture().valuation!, valuedOn: '2026-09-30' };
    api.listPortfolios.mockResolvedValue({
      data: {
        items: [
          {
            accountId: 'account-1',
            label: 'PEA',
            institution: null,
            productCode: null,
            assetCode: 'EUR',
            status: 'ACTIVE',
            latestValuation: valuation,
            newerPartialValuation: {
              ...valuation,
              valuedOn: '2026-10-03',
              completeness: 'PARTIAL',
              totalValue: null,
            },
            currentCash: '100',
            positionCount: 1,
          },
        ],
        truncated: false,
      },
      response: new Response('{}'),
    });
    renderPage();
    expect(
      await screen.findByText(
        'Valorisation du 03/10/2026 partielle : dernières valeurs complètes du 30/09/2026 affichées.',
      ),
    ).toBeTruthy();
    expect(screen.getByText('au 2026-09-30')).toBeTruthy();
    expect(screen.getByText(/3\s000,42/)).toBeTruthy();
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
  it('shows a valued account and keeps the whole card one link', async () => {
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
            newerPartialValuation: null,
            currentCash: '100',
            positionCount: 0,
          },
        ],
        truncated: false,
      },
      response: new Response('{}'),
    });
    renderPage();
    expect(
      (await screen.findByRole('link', { name: 'Ouvrir le portefeuille PEA' })).getAttribute(
        'href',
      ),
    ).toBe('/portfolios/account-1');
    expect(screen.getByText('Non valorisé')).toBeTruthy();
  });
  it.each([403, 500])('shows the %s state with recovery', async (status) => {
    api.listPortfolios.mockResolvedValue({ response: new Response('{}', { status }) });
    renderPage();
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });
});
