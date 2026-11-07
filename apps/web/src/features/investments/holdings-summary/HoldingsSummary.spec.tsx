import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
import { HoldingsSummary } from './HoldingsSummary';

afterEach(cleanup);

describe('HoldingsSummary', () => {
  it('shows the securities value, cash and the valuation range of mixed dates', () => {
    render(<HoldingsSummary holdings={holdingsFixture()} />);
    expect(screen.getByText('Valeur des titres')).toBeTruthy();
    expect(screen.getByText(/2\s510,00/)).toBeTruthy();
    expect(screen.getByText(/170,00/)).toBeTruthy();
    expect(screen.getByText('Valorisations du 31 octobre 2026 au 3 novembre 2026')).toBeTruthy();
  });

  it('names the unknown cost instead of showing a zero gain', () => {
    render(<HoldingsSummary holdings={holdingsFixture()} />);
    expect(screen.getByText("Plus-value non calculable : coût d'origine inconnu")).toBeTruthy();
    expect(screen.queryByText(/^0,00/)).toBeNull();
  });

  it('shows the signed gain and percentage when the totals are known', () => {
    const base = holdingsFixture();
    render(
      <HoldingsSummary
        holdings={holdingsFixture({
          warnings: [],
          valuedFrom: '2026-11-03',
          totals: {
            ...base.totals,
            costBasis: '2380.00',
            unrealizedGain: '130.00',
            performance: '0.094203',
            reason: null,
          },
        })}
      />,
    );
    expect(screen.getByText(/\+130,00/)).toBeTruthy();
    expect(screen.getByText('(+9,42 %)')).toBeTruthy();
    expect(screen.getByText('Valorisé au 3 novembre 2026')).toBeTruthy();
  });

  it('withholds every total when the accounts use several units', () => {
    const base = holdingsFixture();
    render(
      <HoldingsSummary
        holdings={holdingsFixture({
          assetCode: null,
          totals: {
            securitiesValue: null,
            costBasis: null,
            unrealizedGain: null,
            performance: null,
            cash: null,
            totalValue: null,
            reason: 'MIXED_ASSETS',
          },
          items: base.items,
        })}
      />,
    );
    expect(
      screen.getByText('Totaux non calculés : les comptes utilisent plusieurs unités.'),
    ).toBeTruthy();
    expect(screen.queryByText(/€/)).toBeNull();
  });

  it('keeps the labels real and blocks only the values while loading', () => {
    const { container } = render(<HoldingsSummary holdings={null} />);
    for (const label of [
      'Valeur des titres',
      'Plus-value latente',
      'Prix de revient',
      'Liquidités',
    ]) {
      expect(screen.getByText(label)).toBeTruthy();
    }
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThanOrEqual(4);
  });
});
