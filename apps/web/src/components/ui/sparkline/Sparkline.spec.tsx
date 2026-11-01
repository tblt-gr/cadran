import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Sparkline } from './Sparkline';

afterEach(cleanup);

function points(values: (string | null)[]) {
  return values.map((value, index) => ({
    label: `2026-${String(index + 1).padStart(2, '0')}`,
    value,
  }));
}

describe('Sparkline', () => {
  it('is an image named by its label', () => {
    render(<Sparkline label="Rendement mensuel chaîné" points={points(['100.00', '101.00'])} />);

    expect(screen.getByRole('img', { name: 'Rendement mensuel chaîné' })).toBeTruthy();
  });

  it('draws the run that starts at the first non-null point', () => {
    const { container } = render(
      <Sparkline label="x" points={points([null, null, '100.00', '101.00', '98.98'])} />,
    );

    const path = container.querySelector('path')!;
    expect(path.getAttribute('d')!.match(/[ML]/g)).toHaveLength(3);
    expect(container.querySelectorAll('circle')).toHaveLength(1);
  });

  it('stops at the first null after the run started', () => {
    const { container } = render(
      <Sparkline label="x" points={points(['100.00', '101.00', null, '102.00', '103.00'])} />,
    );

    expect(container.querySelector('path')!.getAttribute('d')!.match(/[ML]/g)).toHaveLength(2);
  });

  it('renders nothing with fewer than two points in the run', () => {
    const { container: single } = render(<Sparkline label="x" points={points([null, '100.00'])} />);
    const { container: none } = render(<Sparkline label="x" points={points([null, null])} />);

    expect(single.querySelector('svg')).toBeNull();
    expect(none.querySelector('svg')).toBeNull();
  });

  it('draws a flat run on the middle line', () => {
    const { container } = render(<Sparkline label="x" points={points(['100.00', '100.00'])} />);

    const commands = container
      .querySelector('path')!
      .getAttribute('d')!
      .split(/(?=[ML])/);
    const ys = commands.map((command) => command.trim().split(' ')[2]);
    expect(new Set(ys).size).toBe(1);
  });

  it('marks a rising run and a falling run for the stroke colour, a flat run for neither', () => {
    const rising = render(<Sparkline label="x" points={points(['100.00', '101.00'])} />);
    expect(rising.container.querySelector('svg')!.getAttribute('class')).toContain('rising');
    const falling = render(<Sparkline label="x" points={points(['101.00', '100.00'])} />);
    expect(falling.container.querySelector('svg')!.getAttribute('class')).toContain('falling');
    const flat = render(<Sparkline label="x" points={points(['100.00', '100.00'])} />);
    const flatClass = flat.container.querySelector('svg')!.getAttribute('class')!;
    expect(flatClass).not.toContain('rising');
    expect(flatClass).not.toContain('falling');
  });
});
