import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { PerformanceValue } from './PerformanceValue';

afterEach(cleanup);

const accountContext = { accountId: 'account-1', assetCode: 'EUR', residual: null };

describe('PerformanceValue', () => {
  it('shows a rate with an explicit sign and its quality badge', () => {
    const fixture = performanceFixture();
    render(
      <PerformanceValue
        context={accountContext}
        figure={fixture.timeWeighted}
        label="TWR exact"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText('+2,38 %')).toBeTruthy();
    expect(screen.getByText('Exact')).toBeTruthy();
  });

  it('shows a loss with its minus sign and a gain as an amount', () => {
    const fixture = performanceFixture();
    const view = render(
      <PerformanceValue
        context={accountContext}
        figure={{ ...fixture.timeWeighted, value: '-0.012345' }}
        label="TWR exact"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText(/^-1,23 %$|^−1,23 %$/)).toBeTruthy();
    view.rerender(
      <PerformanceValue
        context={accountContext}
        figure={fixture.gain}
        label="Gain total"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText(/^\+250,00/)).toBeTruthy();
  });

  it('labels the approximation and the figure to check', () => {
    const fixture = performanceFixture();
    const view = render(
      <PerformanceValue
        context={accountContext}
        figure={fixture.modifiedDietz}
        label="Rendement (Modified Dietz)"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText('Approximation')).toBeTruthy();
    view.rerender(
      <PerformanceValue
        context={accountContext}
        figure={{ ...fixture.modifiedDietz, quality: 'DEGRADED' }}
        label="Rendement (Modified Dietz)"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText('Données à vérifier')).toBeTruthy();
  });

  it('shows a greyed dash with the reason readable by assistive technology', () => {
    const fixture = performanceFixture();
    render(
      <PerformanceValue
        context={accountContext}
        figure={{
          ...fixture.timeWeighted,
          value: null,
          quality: 'NOT_CALCULABLE',
          reasons: [{ code: 'MISSING_FLOW_VALUATION', dates: ['2026-03-25'] }],
        }}
        label="TWR exact"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText('Non calculable', { selector: '.sr-only' })).toBeTruthy();
    expect(
      screen.getByText("Valorisation manquante le jour d'un flux : 25/03/2026.", {
        selector: '.sr-only',
      }),
    ).toBeTruthy();
    expect(screen.getByText('-', { selector: '[aria-hidden="true"]' })).toBeTruthy();
  });

  it('reads Aucune valorisation when the account has none', () => {
    const fixture = performanceFixture();
    render(
      <PerformanceValue
        context={accountContext}
        emptyLabel="Aucune valorisation"
        figure={{ ...fixture.gain, value: null, quality: 'NOT_CALCULABLE' }}
        label="Gain total"
        onExplain={vi.fn()}
      />,
    );
    expect(screen.getByText('Aucune valorisation', { selector: '.sr-only' })).toBeTruthy();
  });

  it('puts the period rate first on a short money-weighted period', () => {
    const fixture = performanceFixture();
    render(
      <PerformanceValue
        context={accountContext}
        figure={fixture.moneyWeighted}
        label="Rendement personnel (MWR)"
        onExplain={vi.fn()}
      />,
    );
    const text = screen.getByTestId('performance-value').textContent ?? '';
    expect(text.indexOf('+1,39 %')).toBeGreaterThan(-1);
    expect(text.indexOf('sur la période')).toBeGreaterThan(text.indexOf('+1,39 %') - 1);
    expect(text.indexOf('+7,62 %')).toBeGreaterThan(text.indexOf('sur la période'));
    expect(text.indexOf('annualisé')).toBeGreaterThan(text.indexOf('+7,62 %'));
  });

  it('shows the annualized rate alone on a full year', () => {
    const fixture = performanceFixture();
    render(
      <PerformanceValue
        context={accountContext}
        figure={{ ...fixture.moneyWeighted, shortPeriod: false }}
        label="Rendement personnel (MWR)"
        onExplain={vi.fn()}
      />,
    );
    const text = screen.getByTestId('performance-value').textContent ?? '';
    expect(text).toContain('+7,62 %');
    expect(text).toContain('annualisé');
    expect(text).not.toContain('sur la période');
  });

  it('keeps its labels and a disabled explanation button while loading', () => {
    render(
      <PerformanceValue
        context={accountContext}
        figure={null}
        label="Gain total"
        onExplain={null}
      />,
    );
    expect(screen.getByRole('heading', { name: 'Gain total' })).toBeTruthy();
    expect(
      screen.getByRole('button', { name: 'Expliquer : Gain total' }).matches(':disabled'),
    ).toBe(true);
  });

  it('opens the explanation from the info button', () => {
    const explain = vi.fn();
    render(
      <PerformanceValue
        context={accountContext}
        figure={performanceFixture().gain}
        label="Gain total"
        onExplain={explain}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Gain total' }));
    expect(explain).toHaveBeenCalled();
  });
});
