import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DonutPlot } from './DonutPlot';

// The chart chunk never arrives: only the placeholder can be on screen.
vi.mock('./DonutChart', () => new Promise(() => undefined));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

describe('DonutPlot while the chart library loads', () => {
  it('holds the chart place with the shared skeleton, not an empty box', () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
      bottom: 0,
      height: 200,
      left: 0,
      right: 0,
      top: 0,
      width: 200,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });

    const { container } = render(<DonutPlot label="Diagramme" slices={[]} totalText="100,00 €" />);

    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
  });
});
