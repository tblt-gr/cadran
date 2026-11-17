import type { PlotArea, PlotPoint } from './plotGeometry';

/** Drawing area of the curve: all positions are computed in these units and scaled by CSS. */
export const AREA: PlotArea = { height: 170, padding: 16, width: 620 };

export function plotX(index: number, count: number): number {
  return count > 1 ? (index * AREA.width) / (count - 1) : AREA.width / 2;
}

export function tickIndices(count: number): number[] {
  if (count <= 3) {
    return Array.from({ length: count }, (_, index) => index);
  }

  return [0, Math.round((count - 1) / 2), count - 1];
}

export function nearestByX(
  placed: readonly PlotPoint[],
  clientX: number,
  width: number,
  left: number,
): number | null {
  if (placed.length === 0 || width === 0) {
    return null;
  }

  const x = ((clientX - left) / width) * AREA.width;
  let best = placed[0];
  let bestDistance = Math.abs(best.x - x);
  for (const point of placed) {
    const distance = Math.abs(point.x - x);
    if (distance < bestDistance) {
      best = point;
      bestDistance = distance;
    }
  }

  return best.index;
}

export function tooltipEdge(
  x: number,
  y: number,
): { edge: 'start' | 'mid' | 'end'; side: 'above' | 'below' } {
  return {
    edge: x / AREA.width < 0.18 ? 'start' : x / AREA.width > 0.82 ? 'end' : 'mid',
    side: y / AREA.height < 0.28 ? 'below' : 'above',
  };
}

export function yExtent(placed: readonly PlotPoint[]): PlotPoint[] {
  if (placed.length === 0) {
    return [];
  }

  let lowest = placed[0];
  let highest = placed[0];
  for (const point of placed) {
    if (point.y > lowest.y) {
      lowest = point;
    }
    if (point.y < highest.y) {
      highest = point;
    }
  }

  return lowest.index === highest.index ? [highest] : [highest, lowest];
}
