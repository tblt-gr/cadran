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
});
