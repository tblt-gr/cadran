import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import '@/i18n';
import { PortfolioKpiGrid } from './PortfolioKpiGrid';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
afterEach(cleanup);
describe('PortfolioKpiGrid', () => {
  it('explains unknown gains and displays a loss with words and sign', () => {
    const summary = summaryFixture();
    summary.totalValue = null;
    summary.unrealizedGain = {
      value: null,
      securitiesValue: null,
      costBasis: null,
      reason: 'UNKNOWN_LOT_COST',
    };
    summary.explanations.UNREALIZED_GAIN.reason = 'UNKNOWN_LOT_COST';
    const view = render(
      <PortfolioKpiGrid summary={summary} asOf="2026-03-10" onExplain={vi.fn()} />,
    );
    expect(screen.getByText('Non valorisé')).toBeTruthy();
    expect(screen.getAllByText('Prix de revient inconnu').length).toBeGreaterThan(0);
    summary.unrealizedGain.value = '-12.345';
    view.rerender(<PortfolioKpiGrid summary={summary} asOf="2026-03-10" onExplain={vi.fn()} />);
    expect(screen.getByText(/Perte −12,35/)).toBeTruthy();
  });

  it('keeps known labels and disabled explanation controls while loading', () => {
    render(<PortfolioKpiGrid summary={null} asOf="2026-03-10" onExplain={vi.fn()} />);
    expect(screen.getByRole('heading', { name: 'Valeur totale' })).toBeTruthy();
    expect(
      screen.getByRole('button', { name: 'Expliquer : Valeur totale' }).matches(':disabled'),
    ).toBe(true);
  });
  it('renders exact server totals and exposes explanation and uncertainty', () => {
    const explain = vi.fn();
    render(<PortfolioKpiGrid summary={summaryFixture()} asOf="2026-03-10" onExplain={explain} />);
    expect(screen.getByText(/Gain/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Valeur totale' }));
    expect(explain).toHaveBeenCalledWith('TOTAL_VALUE');
  });
  it('leads with the total value and shows no period caption', () => {
    render(<PortfolioKpiGrid summary={summaryFixture()} asOf="2026-03-10" onExplain={vi.fn()} />);
    const headings = screen.getAllByRole('heading').map((heading) => heading.textContent);
    expect(headings[0]).toBe('Valeur totale');
    expect(headings).toContain('Versements nets 2026');
    expect(headings).toContain('Frais et taxes 2026');
    expect(screen.queryByText(/Du /)).toBeNull();
    expect(screen.queryByText(/Valorisation du/)).toBeNull();
    expect(screen.queryByText(/Calculées au/)).toBeNull();
    expect(screen.queryByText(/prix de revient/)).toBeNull();
  });
  it('opens the points to review from the Rapprochement pill and drops it once reconciled', () => {
    const summary = summaryFixture();
    summary.reconciliation = {
      status: 'NEEDS_REVIEW',
      warnings: [
        { code: 'NEGATIVE_CASH', count: 30 },
        { code: 'PARTIAL_VALUATION', count: 1 },
      ],
    };
    const view = render(
      <PortfolioKpiGrid summary={summary} asOf="2026-03-10" onExplain={vi.fn()} />,
    );
    expect(screen.queryByRole('dialog')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'À vérifier (2)' }));
    const dialog = screen.getByRole('dialog', { name: '2 points à vérifier' });
    expect(
      Array.from(dialog.querySelectorAll('li strong')).map((item) => item.textContent),
    ).toEqual(['Espèces négatives (30 opérations)', 'Valorisation partielle']);
    fireEvent.keyDown(document, { key: 'Escape' });
    view.rerender(
      <PortfolioKpiGrid summary={summaryFixture()} asOf="2026-03-10" onExplain={vi.fn()} />,
    );
    expect(screen.queryByRole('button', { name: /À vérifier/ })).toBeNull();
    expect(screen.queryByText('Rapproché')).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Rapprochement' })).toBeNull();
  });
  it('shows valuation qualifiers under the total value', () => {
    render(
      <PortfolioKpiGrid
        summary={summaryFixture()}
        asOf="2026-03-10"
        onExplain={vi.fn()}
        qualifiers={<span>Valorisation ancienne (12 jours)</span>}
      />,
    );
    expect(screen.getByText('Valorisation ancienne (12 jours)')).toBeTruthy();
  });
});
