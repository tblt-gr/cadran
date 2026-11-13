import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CategoryLabel } from './CategoryLabel';

describe('CategoryLabel', () => {
  afterEach(cleanup);

  it('strokes the category icon in its colour before the name', () => {
    const { container } = render(
      <CategoryLabel color="#58b8c0" icon="utensils">
        Restaurants
      </CategoryLabel>,
    );
    expect(container.querySelector('[data-icon="utensils"] path')?.getAttribute('stroke')).toBe(
      '#58B8C0',
    );
    expect(container.textContent).toBe('Restaurants');
  });

  it('falls back to the colour dot, and draws no mark without icon nor colour', () => {
    const dot = render(<CategoryLabel color="#58B8C0">Courses</CategoryLabel>);
    expect(dot.container.querySelector('[data-color-dot]')).toBeTruthy();
    cleanup();
    const bare = render(<CategoryLabel>Divers</CategoryLabel>);
    expect(bare.container.querySelector('[data-icon]')).toBeNull();
  });
});
