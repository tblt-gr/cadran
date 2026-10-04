import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualFixture } from '@/features/portfolio/performance/performance-tab/annualFixtures';
import { PerformanceMonthTable } from './PerformanceMonthTable';

afterEach(cleanup);

const columns = [
  'Mois',
  'Valeur début',
  'Versements',
  'Retraits',
  'Valeur fin',
  'Gain',
  'Réalisé',
  'Var. latente',
  'Revenus',
  'Frais',
  'Taxes',
  'Rendement (MD)',
  'TWR',
  'MWR (mois)',
];

function rowOf(name: string) {
  return screen.getByRole('row', { name: new RegExp(`^${name}`) });
}

describe('PerformanceMonthTable', () => {
  it('lists the fourteen columns, twelve months and the year footer', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const headers = screen.getAllByRole('columnheader').map((header) => header.textContent);
    expect(headers).toEqual(columns);
    expect(screen.getAllByRole('row')).toHaveLength(1 + 12 + 1);
    const footer = screen.getByRole('row', { name: /^Année 2026/ });
    expect(footer.textContent).toContain('+400,00');
  });

  it('labels a month by its name and gives the exact anchors in the title', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const label = screen.getByText('mars');
    expect(label.getAttribute('title')).toBe('Du 28/02 au 31/03');
  });

  it('greys a future month as a row of dashes without figures', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const row = rowOf('avril');
    expect(row.getAttribute('data-status')).toBe('FUTURE');
    expect(within(row).queryAllByRole('button')).toHaveLength(0);
    expect(within(row).getAllByText('-').length).toBe(13);
    expect(within(row).getAllByText('À venir').length).toBeGreaterThan(0);
  });

  it('shows a non-calculable cell as a dash that still carries its reason', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const row = rowOf('mars');
    expect(row.getAttribute('data-status')).toBe('NOT_CALCULABLE');
    expect(within(row).getAllByText('Non calculable').length).toBeGreaterThan(0);
    expect(within(row).getAllByText('-').length).toBeGreaterThan(0);
  });

  it('shows the month period rate of the MWR, never the annualized one', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const row = rowOf('janvier');
    expect(row.textContent).toContain('+0,61');
    expect(row.textContent).not.toContain('7,62');
  });

  it('marks the annual MWR of the footer as not chained, with its explanation', () => {
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={() => undefined} />);
    const footer = screen.getByRole('row', { name: /^Année 2026/ });
    const mention = within(footer).getByText('non chaîné');
    expect(mention.closest('[title]')?.getAttribute('title')).toBe(
      "Calculé sur les flux de l'année, pas à partir des mois.",
    );
    expect(footer.textContent).toContain('+8,21');
  });

  it('opens the explanation of a month figure from its info button, by keyboard', () => {
    const onExplain = vi.fn();
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={onExplain} />);
    const button = within(rowOf('janvier')).getByRole('button', {
      name: /Rendement \(Modified Dietz\)/,
    });
    button.focus();
    expect(document.activeElement).toBe(button);
    fireEvent.click(button);
    expect(onExplain).toHaveBeenCalledWith({
      kind: 'month',
      month: '2026-01',
      figure: 'modifiedDietz',
    });
  });

  it('explains the four figures of a month and of the year line', () => {
    const onExplain = vi.fn();
    render(<PerformanceMonthTable annual={annualFixture()} onExplain={onExplain} />);
    expect(within(rowOf('février')).getAllByRole('button')).toHaveLength(4);
    const footer = screen.getByRole('row', { name: /^Année 2026/ });
    fireEvent.click(within(footer).getByRole('button', { name: /TWR/ }));
    expect(onExplain).toHaveBeenCalledWith({ kind: 'year', figure: 'timeWeighted' });
  });

  it('keeps the real headers while loading and fills the body with blocks', () => {
    render(<PerformanceMonthTable annual={null} year={2026} onExplain={() => undefined} />);
    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual(
      columns,
    );
    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });
});
