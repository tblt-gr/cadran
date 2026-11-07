import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DonutChart } from './DonutChart';
import { DonutPlot } from './DonutPlot';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

const slices = [
  { amountText: '30,00 €', caption: 'Loyer · 30 %', key: 'a', size: 0.3 },
  { amountText: '70,00 €', caption: 'Courses · 70 %', key: 'b', size: 0.7 },
];

describe('DonutChart', () => {
  it('shows the total in the centre by default and names the drawing', () => {
    render(
      <DonutChart
        height={200}
        label="Diagramme"
        slices={slices}
        totalText="100,00 €"
        width={200}
      />,
    );

    expect(screen.getByRole('img', { name: 'Diagramme' })).toBeTruthy();
    expect(screen.getByText('100,00 €')).toBeTruthy();
  });

  it('draws nothing in the centre without a total', () => {
    render(
      <DonutChart height={200} label="Diagramme" slices={slices} totalText={null} width={200} />,
    );

    expect(screen.queryByText('100,00 €')).toBeNull();
  });
});

describe('DonutPlot', () => {
  it('waits for a measured box before drawing', () => {
    render(<DonutPlot label="Diagramme" slices={slices} totalText="100,00 €" />);

    expect(screen.queryByRole('img', { name: 'Diagramme' })).toBeNull();
  });

  it('draws once its box has a size', async () => {
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
    render(<DonutPlot label="Diagramme" slices={slices} totalText="100,00 €" />);

    // The chart library loads on demand, after the box is measured.
    expect(await screen.findByRole('img', { name: 'Diagramme' }, { timeout: 5000 })).toBeTruthy();
  });

  it('swaps the centre to the hovered slice amount and caption', () => {
    const { container } = render(
      <DonutChart
        height={200}
        label="Diagramme"
        slices={slices}
        totalText="100,00 €"
        width={200}
      />,
    );
    const sector = container.querySelectorAll('.recharts-pie-sector')[0]!;
    fireEvent.mouseEnter(sector);
    expect(screen.getByText('30,00 €')).toBeTruthy();
    // The caption is wrapped over tspans, so it is read from the drawing's text.
    expect(container.querySelector('svg')!.textContent).toMatch(/Loyer/);
    expect(screen.queryByText('100,00 €')).toBeNull();
  });
});
