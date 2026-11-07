import type { HoldingsKnownCostFigures } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import {
  allTotalHoldings,
  holdingsFixture,
  knownCostFigures,
} from '@/features/investments/holdingsFixture';
import { expectExplanation } from '@/features/investments/explain-info/explanationDialog';
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

  it('shows a dash and an explanation of the unknown cost instead of a zero gain', async () => {
    render(<HoldingsSummary holdings={holdingsFixture()} />);
    expect(screen.queryByText("Plus-value non calculable : coût d'origine inconnu")).toBeNull();
    expect(screen.queryByText(/^0,00/)).toBeNull();
    await expectExplanation(
      screen.getByRole('button', { name: 'Expliquer : Plus-value latente non calculable' }),
      'Plus-value latente',
      "Plus-value non calculable : coût d'origine inconnu",
    );
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
            knownCost: null,
          },
          items: base.items,
        })}
      />,
    );
    expect(
      screen.getByText('Totaux non calculés : les comptes utilisent plusieurs unités.'),
    ).toBeTruthy();
    expect(screen.queryByText(/€/)).toBeNull();
    expect(
      screen.getByRole('button', { name: 'Expliquer : Prix de revient non calculable' }),
    ).toBeTruthy();
  });

  it('fills the cards from the totals of accounts valued as a total, never with zero', () => {
    render(<HoldingsSummary holdings={allTotalHoldings()} />);
    expect(screen.getByText(/800,00/)).toBeTruthy();
    expect(screen.getByText(/10,00/)).toBeTruthy();
    expect(
      screen.getByRole('button', { name: 'Expliquer : Plus-value latente non calculable' }),
    ).toBeTruthy();
    expect(screen.getAllByText('-').length).toBeGreaterThan(0);
    expect(screen.queryByText(/^0,00/)).toBeNull();
  });

  function withKnownCost(knownCost: HoldingsKnownCostFigures | null) {
    const base = holdingsFixture();
    return holdingsFixture({ totals: { ...base.totals, knownCost } });
  }

  function cardOf(label: string) {
    return screen.getByRole('heading', { name: label }).closest('article')!;
  }

  it('shows the known-cost figures as the card values, with the explanation in a modal', async () => {
    render(<HoldingsSummary holdings={withKnownCost(knownCostFigures)} />);
    const gain = cardOf('Plus-value latente');
    expect(within(gain).getByText(/\+2\s454,65/)).toBeTruthy();
    expect(within(gain).getByText('(+6,15 %)')).toBeTruthy();
    expect(screen.queryByText('Partiel')).toBeNull();
    expect(within(gain).queryByText(/Plus-value non calculable/)).toBeNull();
    expect(within(gain).queryByText(/Sur les positions au coût connu/)).toBeNull();
    const info = within(gain).getByRole('button', {
      name: 'Expliquer : Plus-value latente calculé sur une partie des positions',
    });
    // Pinned to the card itself, not inside the value line.
    expect(info.parentElement).toBe(gain);
    expect(screen.queryByText(/Calculé sur les positions/)).toBeNull();
    await expectExplanation(
      info,
      'Plus-value latente',
      /^Calculé sur les positions dont le coût est connu uniquement\. 1 position sans coût exclue \(1\s239,00\s€\)\.$/,
    );

    const cost = cardOf('Prix de revient');
    expect(within(cost).getByText(/39\s887,16/)).toBeTruthy();
    expect(
      within(cost).getByRole('button', {
        name: 'Expliquer : Prix de revient calculé sur une partie des positions',
      }),
    ).toBeTruthy();
  });

  it('pluralises the exclusion and omits an unknown excluded value', async () => {
    render(
      <HoldingsSummary
        holdings={withKnownCost({ ...knownCostFigures, excludedCount: 2, excludedValue: null })}
      />,
    );
    await expectExplanation(
      within(cardOf('Plus-value latente')).getByRole('button'),
      'Plus-value latente',
      'Calculé sur les positions dont le coût est connu uniquement. 2 positions sans coût exclues.',
    );
  });

  it('keeps the dash and its explanation when no known-cost figure exists', () => {
    render(<HoldingsSummary holdings={withKnownCost(null)} />);
    expect(
      within(cardOf('Plus-value latente')).getByRole('button', {
        name: 'Expliquer : Plus-value latente non calculable',
      }),
    ).toBeTruthy();
    expect(screen.queryByText(/Plus-value non calculable/)).toBeNull();
    expect(
      screen.queryByRole('button', { name: /calculé sur une partie des positions/ }),
    ).toBeNull();
  });

  it('leaves exact totals untouched even if known-cost figures were sent', () => {
    const base = holdingsFixture();
    render(
      <HoldingsSummary
        holdings={holdingsFixture({
          totals: {
            ...base.totals,
            costBasis: '2380.00',
            unrealizedGain: '130.00',
            performance: '0.094203',
            reason: null,
            knownCost: knownCostFigures,
          },
        })}
      />,
    );
    expect(within(cardOf('Plus-value latente')).getByText(/\+130,00/)).toBeTruthy();
    expect(
      screen.queryByRole('button', { name: /calculé sur une partie des positions/ }),
    ).toBeNull();
  });

  it('puts the four figures in one named panel with the date line as its footer', () => {
    render(<HoldingsSummary holdings={holdingsFixture()} />);
    const panel = screen.getByRole('region', { name: 'Synthèse des positions' });
    for (const label of [
      'Valeur des titres',
      'Plus-value latente',
      'Prix de revient',
      'Liquidités',
    ]) {
      expect(within(panel).getByRole('heading', { name: label })).toBeTruthy();
    }
    expect(panel.querySelectorAll(':scope > div > article')).toHaveLength(4);
    expect(within(panel).getByText(/^Valorisations du/)).toBeTruthy();
    // No explain button on the exact figures.
    expect(within(cardOf('Valeur des titres')).queryByRole('button')).toBeNull();
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
