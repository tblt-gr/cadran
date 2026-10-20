import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CurveChart, type CurvePoint } from './CurveChart';

afterEach(cleanup);

const points: CurvePoint[] = [
  {
    axisLabel: 'janv.',
    geometryValue: '100',
    hitLabel: 'janv., 100 €',
    key: '1',
    tooltipTitle: 'janvier',
    value: '100 €',
  },
  {
    axisLabel: 'févr.',
    geometryValue: null,
    hitLabel: 'févr.',
    key: '2',
    tooltipTitle: 'février',
    value: '-',
  },
  {
    axisLabel: 'mars',
    geometryValue: '300',
    hitLabel: 'mars, 300 €',
    key: '3',
    tooltipTitle: 'mars',
    value: '300 €',
  },
];

function renderChart(list = points) {
  return render(
    <CurveChart
      label="Courbe"
      points={list}
      timeAxisLabel="Axe des mois"
      valueAxisLabel="Valeurs"
    />,
  );
}

describe('CurveChart', () => {
  it('names the drawing and labels the axes with the caller strings', () => {
    renderChart();

    expect(screen.getByRole('img', { name: 'Courbe' })).toBeTruthy();
    expect(screen.getByRole('group', { name: 'Axe des mois' }).textContent).toContain('janv.');
    expect(screen.getByRole('group', { name: 'Valeurs' }).textContent).toContain('300 €');
  });

  it('gives a focus target only to computable points and shows the tooltip on focus', () => {
    renderChart();

    expect(screen.queryByRole('button', { name: 'févr.' })).toBeNull();
    act(() => screen.getByRole('button', { name: 'mars, 300 €' }).focus());
    expect(screen.getByRole('tooltip').textContent).toContain('300 €');
    fireEvent.blur(screen.getByRole('button', { name: 'mars, 300 €' }));
    expect(screen.queryByRole('tooltip')).toBeNull();
  });

  it('draws no point when nothing is computable', () => {
    renderChart(points.map((point) => ({ ...point, geometryValue: null })));

    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('shows optional detail lines in the tooltip only', () => {
    renderChart(points.map((point) => ({ ...point, tooltipDetail: `note ${point.key}` })));

    expect(screen.queryByText('note 3')).toBeNull();
    act(() => screen.getByRole('button', { name: 'mars, 300 €' }).focus());
    expect(screen.getByRole('tooltip').textContent).toContain('note 3');
  });
});

it('marks the plot frame for the visual comparison mask', () => {
  const { container } = renderChart();

  expect(container.querySelector('[data-visual-mask]')).not.toBeNull();
});

it('makes step points keyboard reachable and preserves step geometry across a gap', () => {
  const { container } = render(
    <CurveChart
      label="Steps"
      points={points.map((point, index) => ({
        ...point,
        date: ['2026-10-01', '2026-10-10', '2026-10-31'][index],
      }))}
      interpolation="step-after"
      timeAxisLabel="Date"
      valueAxisLabel="Balance"
    />,
  );
  const hit = screen.getByRole('button', { name: 'mars, 300 €' });
  expect(hit.tabIndex).toBe(0);
  act(() => hit.focus());
  expect(screen.getByRole('tooltip').textContent).toContain('300 €');
  expect(
    [...container.querySelectorAll('svg path')].some((path) =>
      path.getAttribute('d')?.includes(' H186'),
    ),
  ).toBe(true);
});

it('constrains only step axes while retaining every digit in accessible labels and titles', () => {
  const exact = '12345678901234567890123456.123456789012345678901234 EUR';
  const list = points.map((point) => ({ ...point, value: exact }));
  const { container, rerender } = render(
    <CurveChart
      label="Balance"
      points={list}
      interpolation="step-after"
      timeAxisLabel="Date"
      valueAxisLabel="Exact balance"
    />,
  );
  expect(container.querySelector('[data-interpolation="step-after"]')).toBeTruthy();
  const axis = screen.getByRole('group', { name: 'Exact balance' });
  expect(axis.textContent).toContain(exact);
  expect(axis.querySelector('[title]')?.getAttribute('title')).toBe(exact);
  rerender(
    <CurveChart
      label="Balance"
      points={list}
      timeAxisLabel="Date"
      valueAxisLabel="Exact balance"
    />,
  );
  expect(container.querySelector('[data-interpolation="step-after"]')).toBeNull();
});
