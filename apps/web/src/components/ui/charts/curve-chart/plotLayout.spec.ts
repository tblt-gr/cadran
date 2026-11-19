import { describe, expect, it } from 'vitest';
import type { PlotPoint } from './plotGeometry';
import { yLevels } from './plotLayout';

const at = (index: number, y: number) => ({ index, x: index * 10, y }) as PlotPoint;

describe('yLevels', () => {
  it('returns nothing without a point and one level for a flat series', () => {
    expect(yLevels([])).toEqual([]);
    expect(yLevels([at(0, 80)])).toHaveLength(1);
  });

  it('adds two intermediate real points between the extremes, top to bottom', () => {
    const levels = yLevels([at(0, 150), at(1, 110), at(2, 70), at(3, 20)]);
    expect(levels.map((level) => level.index)).toEqual([3, 2, 1, 0]);
  });

  it('skips an intermediate point that would print its label over another', () => {
    const levels = yLevels([at(0, 100), at(1, 90), at(2, 80)]);
    expect(levels.map((level) => level.index)).toEqual([2, 0]);
  });
});
