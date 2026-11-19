import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  AllocationBreakdown,
  type AllocationBreakdownProps,
  type AllocationBreakdownSlice,
} from './AllocationBreakdown';
import { geometrySize, isNegativeRatio } from './signedRatio';

afterEach(cleanup);

const slices: AllocationBreakdownSlice[] = [
  {
    key: 'a',
    label: 'Actions',
    caption: 'Actions',
    amountText: '60,00 €',
    shareText: '60,00 %',
    size: 0.6,
  },
  {
    key: 'b',
    label: 'Obligations',
    caption: 'Obligations',
    amountText: '40,00 €',
    shareText: null,
    size: 0.4,
  },
];

function panel(props: Partial<AllocationBreakdownProps<'x' | 'y'>> = {}) {
  return (
    <AllocationBreakdown
      criteria={[
        { value: 'x', label: 'Premier' },
        { value: 'y', label: 'Second' },
      ]}
      criterion="x"
      donutLabel="Répartition test"
      onCriterionChange={vi.fn()}
      slices={slices}
      title="Titre"
      titleId="test-title"
      totalText="100,00 €"
      {...props}
    />
  );
}

describe('AllocationBreakdown', () => {
  it('lists the slices in a captioned, focusable table with row headers', () => {
    render(panel());
    const table = screen.getByRole('table', { name: 'Détail de la répartition' });
    expect(table.parentElement?.getAttribute('role')).toBe('region');
    expect(table.parentElement?.tabIndex).toBe(0);
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual(['Libellé', 'Montant', 'Part']);
    expect(within(table).getByRole('rowheader', { name: 'Actions' })).toBeTruthy();
    expect(within(table).getByText('60,00 %')).toBeTruthy();
    expect(screen.getByRole('heading', { level: 3, name: 'Titre' })).toBeTruthy();
  });

  it('shows the reason of a non calculable share, never zero', () => {
    render(panel());
    expect(screen.getByText('Non calculable')).toBeTruthy();
  });

  it('reports the chosen criterion and renders the header extra and the notice', () => {
    const change = vi.fn();
    render(
      panel({
        onCriterionChange: change,
        headerExtra: <span>Extra</span>,
        notice: <p>Note</p>,
        headingLevel: 2,
      }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Second' }));
    expect(change).toHaveBeenCalledWith('y');
    expect(screen.getByText('Extra')).toBeTruthy();
    expect(screen.getByText('Note')).toBeTruthy();
    expect(screen.getByRole('heading', { level: 2 })).toBeTruthy();
  });

  it('notes the negative shares left out of the donut and keeps the table', () => {
    render(panel({ ignoredCount: 2 }));
    expect(screen.getByRole('status').textContent).toBe(
      '2 parts négatives ignorées dans le graphique',
    );
    expect(screen.getByRole('table')).toBeTruthy();
  });

  it('makes the chart unavailable when no share is positive', () => {
    render(
      panel({
        ignoredCount: 1,
        slices: slices.map((slice) => ({ ...slice, size: null })),
      }),
    );
    expect(screen.getByRole('status').textContent).toContain('aucune part positive');
    expect(screen.getByRole('table')).toBeTruthy();
  });

  it('shows the given reason when not calculable', () => {
    render(panel({ status: { kind: 'notCalculable', message: 'Raison' } }));
    expect(screen.getByRole('status').textContent).toBe('Raison');
  });

  it('keeps the chrome real and the values as blocks while loading', () => {
    const { container } = render(panel({ slices: null }));
    expect(screen.getByRole('heading', { name: 'Titre' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Libellé' })).toBeTruthy();
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(
      screen.getAllByRole('button').every((button) => (button as HTMLButtonElement).disabled),
    ).toBe(true);
  });

  it('omits the table when there is nothing to list', () => {
    render(panel({ slices: [], status: { kind: 'notCalculable', message: 'Vide' } }));
    expect(screen.queryByRole('table')).toBeNull();
  });
});

describe('signed ratios', () => {
  it.each([
    ['-0.01', true],
    ['0', false],
    ['0.75', false],
    ['1.5', false],
    [null, false],
  ])('%s is negative: %s', (ratio, expected) => {
    expect(isNegativeRatio(ratio)).toBe(expected);
  });

  it.each([
    ['-0.2', null],
    ['0', null],
    [null, null],
    ['0.25', 0.25],
    ['1', 1],
    ['1000000', 1],
  ])('draws %s with the geometry %s', (ratio, expected) => {
    expect(geometrySize(ratio)).toBe(expected);
  });
});
