import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  historyFixture,
  historyPointFixture,
} from '@/features/portfolio/portfolio-account/value-history-card/valueHistoryFixtures';
import { accountCardFixture } from '@/features/portfolio/portfolios/portfoliosFixtures';
import { PortfolioAccountCard } from './PortfolioAccountCard';

const api = vi.hoisted(() => ({ readPortfolioValuationHistory: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderCard(
  account: Parameters<typeof PortfolioAccountCard>[0]['account'],
  history = historyFixture(),
) {
  api.readPortfolioValuationHistory.mockResolvedValue({
    data: history,
    response: new Response('{}'),
  });
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <PortfolioAccountCard account={account} />
    </QueryClientProvider>,
  );
}

describe('PortfolioAccountCard', () => {
  it('shows name, institution, rounded total, gain with performance and the curve', async () => {
    renderCard(accountCardFixture());
    expect(screen.getByRole('link', { name: 'Mon PEA' }).getAttribute('href')).toBe(
      '/portfolios/account-1',
    );
    expect(screen.getByText('Courtier')).toBeTruthy();
    expect(screen.getByText(/3\s000,42/)).toBeTruthy();
    expect(screen.getByText(/\+219,50/)).toBeTruthy();
    expect(screen.getByText('+7,32 %')).toBeTruthy();
    expect(await screen.findByRole('group', { name: 'Valeur totale en EUR' })).toBeTruthy();
  });

  it('falls back to the product code without institution', () => {
    renderCard(accountCardFixture({ institution: null }));
    expect(screen.getByText('PEA')).toBeTruthy();
  });

  it('rounds the curve axis to two decimals', async () => {
    renderCard(
      accountCardFixture(),
      historyFixture({
        points: [
          historyPointFixture({ totalValue: { value: '3500.126', assetCode: 'EUR' } }),
          historyPointFixture({
            valuationId: 'second',
            valuedOn: '2026-08-31',
            totalValue: { value: '3600.999', assetCode: 'EUR' },
          }),
        ],
      }),
    );
    const axis = await screen.findByRole('group', { name: 'Valeur totale en EUR' });
    expect(axis.textContent).toMatch(/3\s601,00/);
    expect(axis.textContent).not.toMatch(/,\d{3}/);
  });

  it('shows a dash for a gain that is not calculable', () => {
    renderCard(
      accountCardFixture({
        unrealizedGain: {
          value: null,
          costBasis: null,
          performance: null,
          reason: 'UNKNOWN_LOT_COST',
        },
      }),
    );
    expect(screen.getAllByText('Coût d’acquisition inconnu').length).toBeGreaterThan(0);
  });
});
