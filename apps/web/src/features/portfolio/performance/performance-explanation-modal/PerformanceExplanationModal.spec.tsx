import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { PerformanceExplanationModal } from './PerformanceExplanationModal';

afterEach(cleanup);

function open(
  figure: Parameters<typeof PerformanceExplanationModal>[0]['figure'],
  performance = performanceFixture(),
  onSource = vi.fn(),
  onClose = vi.fn(),
) {
  render(
    <PerformanceExplanationModal
      figure={figure}
      onClose={onClose}
      onSource={onSource}
      performance={performance}
    />,
  );
  return { onSource, onClose };
}

const headings = () => screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
const entries = () => screen.getByText('Détail du calcul').closest('details')!;

describe('PerformanceExplanationModal', () => {
  it('leads with the value and its period, then the calculation, details folded', () => {
    open('gain');
    const dialog = screen.getByRole('dialog', { name: 'Gain total' });
    expect(headings()).toEqual(['Calcul']);
    const text = dialog.textContent ?? '';
    expect(text.indexOf('+250,00')).toBeLessThan(text.indexOf('Calcul'));
    expect(screen.getByText('Du 01/01/2026 au 10/03/2026')).toBeTruthy();
    expect(
      screen.getByText(
        'Valeur finale moins valeur initiale moins les versements nets. Net de frais et de taxes.',
      ),
    ).toBeTruthy();
    expect(screen.getByText('Méthode version PERF-1')).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('250,00');
    expect(entries().open).toBe(false);
    expect(within(entries()).getByText(/Réalisé/)).toBeTruthy();
    expect(screen.getByText(/^Sources du calcul/).closest('details')?.open).toBe(false);
  });

  it('shows the anchors with their date, value and freshness', () => {
    open('gain');
    expect(within(entries()).getByText(/31\/12\/2025/)).toBeTruthy();
    expect(within(entries()).getByText(/70 jours/)).toBeTruthy();
    expect(within(entries()).getByText(/Prix anciens/)).toBeTruthy();
  });

  it('explains Modified Dietz as an approximation with its weights', () => {
    open('modifiedDietz');
    expect(
      screen.getByText(
        'Approximation du rendement pondéré dans le temps : chaque flux est pondéré par la part de la période où il était investi. Net de frais et de taxes.',
      ),
    ).toBeTruthy();
    expect(screen.getByText('Approximation')).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('2,35 %');
    expect(screen.getByRole('table', { name: 'Flux pondérés' })).toBeTruthy();
    expect(screen.getByText('54,41 %')).toBeTruthy();
  });

  it('explains the chained Modified Dietz of a year without the monthly inputs', () => {
    const performance = performanceFixture();
    performance.modifiedDietz.inputs = {
      ...performance.modifiedDietz.inputs,
      gain: null,
      denominatorTimesDays: null,
      averageCapital: null,
    };
    open('modifiedDietz', performance);
    expect(screen.getByTestId('formula').textContent).toContain('mensuels');
  });

  it('explains TWR with its subperiods', () => {
    open('timeWeighted');
    expect(
      screen.getByText(
        "Rendement des placements, neutre vis-à-vis des versements, calculé à partir d'une valorisation à chaque date de flux. Net de frais et de taxes.",
      ),
    ).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Sous-périodes' })).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('2,38 %');
  });

  it('explains MWR with its investor cash flows and constants', () => {
    open('moneyWeighted');
    expect(
      screen.getByText(
        'Taux annuel de votre argent, compte tenu des dates de vos versements et retraits. Net de frais et de taxes.',
      ),
    ).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Flux investisseur' })).toBeTruthy();
    expect(screen.getByText('0,98 à 1,01')).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('7,62 %');
  });

  it('says how many items are left out of a truncated list', () => {
    const performance = performanceFixture();
    performance.modifiedDietz.weightCount = 103;
    performance.timeWeighted.subperiodCount = 402;
    performance.moneyWeighted.cashFlowCount = 5;
    open('modifiedDietz', performance);
    expect(screen.getByText('+102 autres')).toBeTruthy();
    cleanup();
    open('timeWeighted', performance);
    expect(screen.getByText('+400 autres')).toBeTruthy();
    cleanup();
    open('moneyWeighted', performance);
    expect(screen.getByText('+2 autres')).toBeTruthy();
  });

  it('lists each reason as a sentence with its action', () => {
    const performance = performanceFixture();
    performance.timeWeighted.value = null;
    performance.timeWeighted.quality = 'NOT_CALCULABLE';
    performance.timeWeighted.reasons = [
      { code: 'MISSING_FLOW_VALUATION', dates: ['2026-03-25'] },
      { code: 'NOT_A_CODE' as never, dates: [] },
    ];
    open('timeWeighted', performance);
    const quality = screen.getByRole('dialog');
    expect(
      within(quality).getByText("Valorisation manquante le jour d'un flux : 25/03/2026."),
    ).toBeTruthy();
    const link = within(quality).getByRole('link', { name: 'Enregistrer une valorisation' });
    expect(link.getAttribute('href')).toBe('/portfolios/account-1?tab=valuations');
    expect(within(quality).getByText('Motif non reconnu (NOT_A_CODE)')).toBeTruthy();
    expect(within(quality).getAllByText('Non calculable').length).toBeGreaterThan(0);
  });

  it('closes before following an action link', () => {
    const performance = performanceFixture();
    performance.gain.reasons = [{ code: 'NO_END_VALUATION', dates: [] }];
    const { onClose } = open('gain', performance);
    fireEvent.click(screen.getByRole('link', { name: 'Enregistrer une valorisation' }));
    expect(onClose).toHaveBeenCalled();
  });

  it('opens a source in its detail modal', () => {
    const { onSource } = open('gain');
    fireEvent.click(screen.getByRole('button', { name: 'Valorisation du 31/12/2025' }));
    expect(onSource).toHaveBeenCalledWith({
      kind: 'VALUATION',
      id: 'valuation-1',
      date: '2025-12-31',
    });
    expect(screen.getByRole('button', { name: 'Opération du 01/02/2026' })).toBeTruthy();
  });

  it('closes with Escape', () => {
    const { onClose } = open('gain');
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
  });
});
