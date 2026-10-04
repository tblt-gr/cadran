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

describe('PerformanceExplanationModal', () => {
  it('explains the total gain in the specified order', () => {
    open('gain');
    expect(screen.getByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
    expect(headings()).toEqual([
      'Méthode',
      'Formule',
      'Période',
      'Entrées',
      'Politique de coûts',
      'Qualité',
      'Sources',
    ]);
    expect(
      screen.getByText('Valeur finale moins valeur initiale moins les versements nets.'),
    ).toBeTruthy();
    expect(screen.getByText('Version PERF-1')).toBeTruthy();
    expect(screen.getByText('Du 01/01/2026 au 10/03/2026, 68 jours')).toBeTruthy();
    expect(screen.getByText('Net de frais et de taxes')).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('250,00');
    expect(screen.getByText(/Réalisé/)).toBeTruthy();
  });

  it('shows the anchors with their date, value and freshness', () => {
    open('gain');
    const entries = screen.getByRole('region', { name: 'Entrées' });
    expect(within(entries).getByText(/31\/12\/2025/)).toBeTruthy();
    expect(within(entries).getByText(/70 jours/)).toBeTruthy();
    expect(within(entries).getByText(/Prix anciens/)).toBeTruthy();
  });

  it('explains Modified Dietz as an approximation with its weights', () => {
    open('modifiedDietz');
    expect(
      screen.getByText(
        'Approximation du rendement pondéré dans le temps : chaque flux est pondéré par la part de la période où il était investi.',
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
        "Rendement des placements, neutre vis-à-vis des versements, calculé à partir d'une valorisation à chaque date de flux.",
      ),
    ).toBeTruthy();
    expect(screen.getByRole('table', { name: 'Sous-périodes' })).toBeTruthy();
    expect(screen.getByTestId('formula').textContent).toContain('2,38 %');
  });

  it('explains MWR with its investor cash flows and constants', () => {
    open('moneyWeighted');
    expect(
      screen.getByText(
        'Taux annuel de votre argent, compte tenu des dates de vos versements et retraits.',
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
    const quality = screen.getByRole('region', { name: 'Qualité' });
    expect(
      within(quality).getByText("Valorisation manquante le jour d'un flux : 25/03/2026."),
    ).toBeTruthy();
    const link = within(quality).getByRole('link', { name: 'Enregistrer une valorisation' });
    expect(link.getAttribute('href')).toBe('/portfolios/account-1?tab=valuations');
    expect(within(quality).getByText('Motif non reconnu (NOT_A_CODE)')).toBeTruthy();
    expect(within(quality).getByText('Non calculable')).toBeTruthy();
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
