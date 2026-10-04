import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import '@/i18n';
import { PortfolioKpiGrid } from './PortfolioKpiGrid';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
afterEach(cleanup);
describe('PortfolioKpiGrid', () => {
  it.each([
    ['STALE_PRICES', 'Au moins un prix date de plus de 5 jours avant la valorisation.'],
    ['CASH_DIFFERENCE', 'Les espèces constatées diffèrent des espèces calculées.'],
    [
      'OBSERVED_TOTAL_DIFFERENCE',
      'Le total du relevé s’écarte du total calculé au-delà de la tolérance.',
    ],
    ['POSITIONS_CHANGED', 'Des opérations ont été enregistrées ou annulées depuis'],
    ['NEGATIVE_SECURITIES_VALUE', 'Le total est inférieur aux espèces'],
    ['LINKED_TRANSFER_CHANGED', 'Un virement lié a été annulé ou modifié'],
    [
      'NON_TRANSFER_TRANSACTION',
      'Une transaction a été enregistrée sur ce compte avant le suivi par portefeuille',
    ],
  ])('explains the known warning %s in French', (code, sentence) => {
    const summary = summaryFixture();
    summary.reconciliation = { status: 'NEEDS_REVIEW', warnings: [{ code, count: 2 }] };
    render(<PortfolioKpiGrid summary={summary} asOf="2026-03-10" onExplain={vi.fn()} />);
    expect(screen.getByRole('listitem').textContent).toContain(sentence);
    expect(screen.getByRole('listitem').textContent).not.toContain(code);
    expect(screen.getByRole('listitem').textContent).toContain('2');
  });

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
  it('leads with the total value and dates only the indicators dated otherwise', () => {
    render(<PortfolioKpiGrid summary={summaryFixture()} asOf="2026-03-10" onExplain={vi.fn()} />);
    const headings = screen.getAllByRole('heading').map((heading) => heading.textContent);
    expect(headings[0]).toBe('Valeur totale');
    expect(headings).toContain('Versements nets 2026');
    expect(headings).toContain('Frais et taxes 2026');
    expect(screen.getAllByText('Du 01/01/2026 au 10/03/2026')).toHaveLength(2);
    expect(screen.queryByText('au 2026-03-10')).toBeNull();
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
