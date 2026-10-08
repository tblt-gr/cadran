import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { SupportsTable } from './SupportsTable';
import { SupportCards } from './support-cards/SupportCards';
afterEach(cleanup);
it('keeps exact quantity, dated stale price, cost, UC gain and euro interest distinct', () => {
  render(<SupportsTable summary={summaryFixture()} />);
  const table = screen.getByRole('table', { name: 'Supports du contrat' });
  const uc = within(table).getByRole('row', { name: /Fonds UC Démo/ });
  expect(uc.textContent).toContain('49,70');
  expect(uc.textContent).toContain('Prix ancien');
  expect(uc.textContent).toContain('198,80');
  expect(uc.textContent).toContain('4,00 % latente');
  expect(uc.textContent).not.toContain('Taux servi');
  const euro = within(table).getByRole('row', { name: /Fonds euros Démo/ });
  expect(euro.textContent).toContain('Taux servi 2025 : 2,50');
  expect(euro.textContent).toContain('Intérêts crédités 2025 : 124,20');
  expect(euro.textContent).not.toContain('latente');
});
it('shows mobile support facts and missing/non-calculable data without inventing zero', () => {
  const summary = summaryFixture();
  const uc = summary.supports[1];
  summary.supports = [
    {
      ...uc,
      unitPrice: null,
      priceDate: null,
      priceQuality: 'MISSING',
      weight: null,
      unrealizedGain: null,
      unrealizedPerformance: null,
    },
  ];
  render(<SupportCards summary={summary} />);
  const card = screen.getByRole('article', { name: 'Fonds UC Démo' });
  expect(within(card).getByText('Date indisponible · Prix manquant')).toBeTruthy();
  expect(within(card).getAllByText(/Non calculable/).length).toBeGreaterThan(0);
  expect(within(card).getByText('Coût de revient')).toBeTruthy();
});

it('shows the hand-computed sub-cent price exactly in table and mobile cards: 1000 × 0.004 EUR = 4 EUR', () => {
  const summary = summaryFixture();
  summary.supports = [
    { ...summary.supports[1], quantity: '1000', unitPrice: '0.004', marketValue: '4' },
  ];
  render(<SupportsTable summary={summary} />);
  const table = screen.getByRole('table', { name: 'Supports du contrat' });
  expect(within(table).getByRole('cell', { name: '0,004 EUR' })).toBeTruthy();
  expect(within(table).getByRole('cell', { name: /4,00\s€/ })).toBeTruthy();
  const mobile = screen.getByRole('article', { name: 'Fonds UC Démo' });
  expect(within(mobile).getByText('0,004 EUR')).toBeTruthy();
});
it('keeps zero unit prices distinguishable from unavailable prices', () => {
  const summary = summaryFixture();
  summary.supports = [{ ...summary.supports[1], unitPrice: '0' }];
  const view = render(<SupportCards summary={summary} />);
  expect(screen.getByText('0 EUR')).toBeTruthy();
  view.rerender(
    <SupportCards
      summary={{ ...summary, supports: [{ ...summary.supports[0], unitPrice: null }] }}
    />,
  );
  expect(screen.getByText('Non calculable')).toBeTruthy();
});
