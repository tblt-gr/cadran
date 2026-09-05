/**
 * Pixel geometry for the net-worth curve.
 *
 * The numbers produced here are coordinates inside an SVG viewBox, never a
 * financial figure: nothing computed in this file is shown to a reader. Every
 * amount on the screen — the headline, the tooltipless labels and the tabular
 * alternative — is the canonical decimal string the backend produced. Turning
 * a decimal into a coordinate is the one place `Number` is the right tool,
 * because a pixel has no cents.
 */

export interface PlotPoint {
  index: number;
  x: number;
  y: number;
}

export interface PlotArea {
  height: number;
  padding: number;
  width: number;
}

/**
 * Runs of consecutive computable points. A month without a figure breaks the
 * line instead of being drawn through, so a gap reads as a gap rather than as
 * a fall to zero.
 */
export function plotRuns(values: readonly (string | null)[], area: PlotArea): PlotPoint[][] {
  const known = values
    .map((value, index) => ({ index, value: value === null ? null : Number(value) }))
    .filter((entry): entry is { index: number; value: number } => entry.value !== null);

  if (known.length === 0) {
    return [];
  }

  const lowest = Math.min(...known.map((entry) => entry.value));
  const highest = Math.max(...known.map((entry) => entry.value));
  const span = highest - lowest;
  const usable = area.height - area.padding * 2;
  const step = values.length > 1 ? area.width / (values.length - 1) : 0;

  const placed = new Map<number, PlotPoint>(
    known.map((entry) => [
      entry.index,
      {
        index: entry.index,
        x: values.length > 1 ? entry.index * step : area.width / 2,
        // A flat series has no span to scale against; it sits on the middle
        // line rather than dividing by zero.
        y:
          span === 0
            ? area.padding + usable / 2
            : area.height - area.padding - ((entry.value - lowest) / span) * usable,
      },
    ]),
  );

  const runs: PlotPoint[][] = [];
  let current: PlotPoint[] = [];
  for (let index = 0; index < values.length; index += 1) {
    const point = placed.get(index);
    if (point === undefined) {
      if (current.length > 0) {
        runs.push(current);
        current = [];
      }
      continue;
    }

    current.push(point);
  }

  if (current.length > 0) {
    runs.push(current);
  }

  return runs;
}

export function polylinePoints(run: readonly PlotPoint[]): string {
  return run.map((point) => `${point.x},${point.y}`).join(' ');
}
