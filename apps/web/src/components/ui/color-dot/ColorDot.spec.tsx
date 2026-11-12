import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ColorDot } from './ColorDot';

afterEach(cleanup);

describe('ColorDot', () => {
  it('draws a decorative dot in the given colour', () => {
    const { container } = render(<ColorDot color="#2F9E6E" />);

    const dot = container.querySelector('[data-color-dot]');
    expect(dot?.getAttribute('aria-hidden')).toBe('true');
    expect(dot?.querySelector('circle')?.getAttribute('fill')).toBe('#2F9E6E');
  });

  it('draws nothing without a colour', () => {
    const { container } = render(<ColorDot color={null} />);

    expect(container.querySelector('[data-color-dot]')).toBeNull();
  });
});
