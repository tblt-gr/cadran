import { describe, expect, it } from 'vitest';
import { plotRuns, polylinePoints, smoothPath, type PlotArea } from './plotGeometry';

const area: PlotArea = { height: 100, padding: 10, width: 300 };

describe('plotRuns', () => {
  it('places the lowest value at the bottom and the highest at the top', () => {
    const runs = plotRuns(['100', '200', '300'], area);

    expect(runs).toHaveLength(1);
    expect(polylinePoints(runs[0])).toBe('0,90 150,50 300,10');
  });

  it('breaks the line on a month without a figure instead of drawing through zero', () => {
    const runs = plotRuns(['100', null, '300'], area);

    expect(runs.map((run) => run.map((point) => point.index))).toEqual([[0], [2]]);
  });

  it('draws a flat series on the middle line rather than dividing by zero', () => {
    const runs = plotRuns(['500', '500'], area);

    expect(runs[0].map((point) => point.y)).toEqual([50, 50]);
  });

  it('returns nothing when no point is computable', () => {
    expect(plotRuns([null, null], area)).toEqual([]);
  });

  it('scales a series that crosses zero', () => {
    const runs = plotRuns(['-100', '0', '100'], area);

    expect(polylinePoints(runs[0])).toBe('0,90 150,50 300,10');
  });
});

describe('smoothPath', () => {
  it('turns a three-point run into a cubic starting at M', () => {
    const path = smoothPath(plotRuns(['100', '200', '300'], area)[0]);

    expect(path.startsWith('M')).toBe(true);
    expect(path).toContain('C');
    expect(path.includes('L') && !path.includes('C')).toBe(false);
  });

  it('draws nothing, a move, or a line for shorter runs', () => {
    expect(smoothPath([])).toBe('');
    expect(smoothPath([{ index: 0, x: 10, y: 20 }])).toBe('M10,20');
    expect(
      smoothPath([
        { index: 0, x: 0, y: 10 },
        { index: 1, x: 20, y: 30 },
      ]),
    ).toBe('M0,10 L20,30');
  });
});
