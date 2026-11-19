import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import {
  AllocationBreakdown,
  type AllocationBreakdownProps,
  type AllocationBreakdownSlice,
} from './AllocationBreakdown';
import { isSignedRatio } from './isSignedRatio';

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

  it('draws no donut and explains a signed allocation while keeping the table', () => {
    render(panel({ status: { kind: 'signed' } }));
    expect(screen.getByRole('status').textContent).toContain('parts négatives');
    expect(screen.queryByRole('img', { name: 'Répartition test' })).toBeNull();
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

describe('isSignedRatio', () => {
  it.each([
    ['-0.01', true],
    ['1.000000000000000000000001', true],
    ['1', false],
    ['0', false],
    ['0.75', false],
    [null, false],
  ])('%s is %s', (ratio, expected) => {
    expect(isSignedRatio(ratio)).toBe(expected);
  });
});
