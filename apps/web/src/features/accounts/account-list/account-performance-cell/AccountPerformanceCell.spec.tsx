import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { buildAccount } from '@/features/accounts/account-overview/overviewFixtures';
import { AccountPerformanceCell } from './AccountPerformanceCell';
import { accountCardFixture, julyTrend, trendFixture } from './performanceFixtures';

afterEach(cleanup);

const investment = buildAccount('account-1', 'Mon PEA', {
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
});

function ready(item: ReturnType<typeof accountCardFixture>) {
  return { status: 'ready', item } as const;
}

describe('AccountPerformanceCell', () => {
  it('shows a quiet dash named "Sans objet" for an account that is not an investment', () => {
    render(
      <AccountPerformanceCell
        account={buildAccount('a', 'Livret')}
        portfolio={{ status: 'none' }}
      />,
    );

    expect(screen.getByRole('img', { name: 'Sans objet' })).toBeTruthy();
  });

  it('shows the unrealized gain, the sparkline and the since-start caption of worked example 1', () => {
    render(<AccountPerformanceCell account={investment} portfolio={ready(accountCardFixture())} />);

    expect(screen.getByText(/\+219,50/)).toBeTruthy();
    expect(screen.getByText(/\+7,32\s?%/)).toBeTruthy();
    expect(
      screen.getByRole('img', {
        name: 'Rendement mensuel chaîné de juillet 2026 à septembre 2026 : +1,95 %',
      }),
    ).toBeTruthy();
    const caption = screen.getByRole('link', { name: '+1,95 % depuis juillet 2026' });
    expect(caption.getAttribute('href')).toBe('/portfolios/account-1?tab=performance');
  });

  it('writes a full-window caption and a minus sign for a loss', () => {
    const indexes = Array.from({ length: 12 }, (_, month) => (100 - month).toFixed(2));
    const trend = trendFixture(indexes, { from: '2025-10', cumulativeReturn: '-0.04' });
    render(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(accountCardFixture({ returnTrend: trend }))}
      />,
    );

    expect(screen.getByRole('link', { name: '−4,00 % sur 12 mois' })).toBeTruthy();
  });

  it('states why the 12-month return is not computable, never zero', () => {
    const { rerender } = render(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(
          accountCardFixture({
            returnTrend: trendFixture([], { reason: 'NO_HISTORY' }),
          }),
        )}
      />,
    );
    expect(screen.getByText('Rendement 12 mois : pas encore d’historique')).toBeTruthy();
    expect(screen.queryByRole('img', { name: /Rendement mensuel/ })).toBeNull();

    rerender(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(
          accountCardFixture({
            returnTrend: trendFixture(['101.00'], {
              from: '2026-07',
              reason: 'MISSING_MONTH',
              reasonMonth: '2026-08',
            }),
          }),
        )}
      />,
    );
    expect(
      screen.getByText('Rendement 12 mois non calculable : août 2026 sans valorisation'),
    ).toBeTruthy();

    rerender(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(
          accountCardFixture({
            returnTrend: trendFixture([], {
              reason: 'NON_POSITIVE_GROWTH_FACTOR',
              reasonMonth: '2026-03',
            }),
          }),
        )}
      />,
    );
    expect(
      screen.getByText('Rendement 12 mois non calculable : perte totale en mars 2026'),
    ).toBeTruthy();
  });

  it('says the gain is not computable without a valuation', () => {
    render(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(
          accountCardFixture({
            unrealizedGain: { value: null, costBasis: null, performance: null, reason: null },
            returnTrend: julyTrend(),
          }),
        )}
      />,
    );

    expect(screen.getByText('Plus-value non calculable')).toBeTruthy();
  });

  it('links the oldest due month to the valuations tab', () => {
    render(
      <AccountPerformanceCell
        account={investment}
        portfolio={ready(accountCardFixture({ dueMonths: ['2026-08', '2026-09'] }))}
      />,
    );

    const badge = screen.getByRole('link', { name: 'Fin août à saisir' });
    expect(badge.getAttribute('href')).toBe('/portfolios/account-1?tab=valuations');
  });

  it('has no badge when no month is due', () => {
    render(<AccountPerformanceCell account={investment} portfolio={ready(accountCardFixture())} />);

    expect(screen.queryByText(/à saisir/)).toBeNull();
  });

  it('blocks only the values while the portfolios are read', () => {
    const { container } = render(
      <AccountPerformanceCell account={investment} portfolio={{ status: 'pending' }} />,
    );

    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThanOrEqual(2);
    expect(container.textContent).toBe('');
  });

  it('says the performance is unavailable when the read failed or the item is missing', () => {
    const { rerender } = render(
      <AccountPerformanceCell account={investment} portfolio={{ status: 'error' }} />,
    );
    expect(screen.getByText('Performance indisponible')).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();

    rerender(
      <AccountPerformanceCell
        account={investment}
        portfolio={{ status: 'ready', item: undefined }}
      />,
    );
    const cell = screen.getByText('Performance indisponible').parentElement!;
    expect(within(cell).queryByRole('button')).toBeNull();
  });
});
