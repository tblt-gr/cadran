import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { totalFixture } from '@/features/portfolio/portfolios/portfoliosFixtures';
import { PortfolioTotalCard } from './PortfolioTotalCard';

afterEach(cleanup);

describe('PortfolioTotalCard', () => {
  it('shows total, unrealized gain and performance', () => {
    render(<PortfolioTotalCard total={totalFixture()} />);
    expect(screen.getByRole('heading', { name: 'Total investissements' })).toBeTruthy();
    expect(screen.getByText(/4\s500,00/)).toBeTruthy();
    expect(screen.getByText(/\+400,00/)).toBeTruthy();
    expect(screen.getByText('+9,76 %')).toBeTruthy();
  });

  it('keeps real labels and no value while loading', () => {
    render(<PortfolioTotalCard total={null} />);
    expect(screen.getByRole('heading', { name: 'Total investissements' })).toBeTruthy();
    expect(screen.getByText('Plus-value latente')).toBeTruthy();
    expect(screen.queryByText(/€/)).toBeNull();
  });

  it('says why nothing is valued', () => {
    render(
      <PortfolioTotalCard
        total={totalFixture({
          totalValue: null,
          unrealizedGain: null,
          costBasis: null,
          performance: null,
          valuedAccounts: 0,
          unvaluedAccounts: 2,
          reason: 'NO_VALUATION',
        })}
      />,
    );
    expect(screen.getAllByText('Aucune valorisation').length).toBeGreaterThan(0);
    expect(screen.getByText('2 comptes non valorisés')).toBeTruthy();
  });

  it('dashes everything for mixed assets', () => {
    render(
      <PortfolioTotalCard
        total={totalFixture({
          assetCode: null,
          totalValue: null,
          unrealizedGain: null,
          costBasis: null,
          performance: null,
          reason: 'MIXED_ASSETS',
        })}
      />,
    );
    expect(screen.getAllByText('Actifs différents')).toHaveLength(2);
  });

  it('keeps the total when the gain is incomplete', () => {
    render(
      <PortfolioTotalCard
        total={totalFixture({
          unrealizedGain: null,
          costBasis: null,
          performance: null,
          unvaluedAccounts: 1,
          reason: 'INCOMPLETE_GAIN',
        })}
      />,
    );
    expect(screen.getByText(/4\s500,00/)).toBeTruthy();
    expect(screen.getAllByText('Plus-value incomplète').length).toBeGreaterThan(0);
    expect(screen.getByText('1 compte non valorisé')).toBeTruthy();
  });

  it('shows the oldest valuation date and leaves newer partial accounts to their page', () => {
    render(
      <PortfolioTotalCard
        total={totalFixture({ oldestValuedOn: '2026-08-31', partialNewerAccounts: 2 })}
      />,
    );
    expect(screen.getByText('Valorisations au 31 août 2026')).toBeTruthy();
    expect(screen.queryByText(/partielle/)).toBeNull();
  });

  it('hides the quality line without valuation date', () => {
    render(<PortfolioTotalCard total={totalFixture({ oldestValuedOn: null })} />);
    expect(screen.queryByText(/Valorisations au/)).toBeNull();
  });
});
