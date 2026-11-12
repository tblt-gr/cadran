import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CategoryIdentity } from './CategoryIdentity';

function tag(container: HTMLElement) {
  return container.firstElementChild as HTMLElement;
}

describe('CategoryIdentity', () => {
  afterEach(cleanup);

  it('strokes the glyph with the canonical colour and keeps the label in the interface ink', () => {
    const { container } = render(
      <CategoryIdentity color="#aabbcc" icon="utensils" label="Restaurants" />,
    );

    expect(screen.getByText('Restaurants')).toBeTruthy();
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    expect(container.querySelector('path')?.getAttribute('stroke')).toBe('#AABBCC');
    expect(tag(container).getAttribute('data-color')).toBe('#AABBCC');
    expect(container.querySelector('[style]')).toBeNull();
  });

  it('marks a colour without a glyph with a dot', () => {
    const { container } = render(<CategoryIdentity color="#2E7D32" label="Courses" />);

    expect(container.querySelector('[data-color-dot] circle')?.getAttribute('fill')).toBe(
      '#2E7D32',
    );
  });

  it.each([
    null,
    'unknown-old-icon',
    '__proto__',
    '<svg onload=alert(1)>',
    'https://evil/icon.svg',
  ])('draws no glyph at all for %s', (icon) => {
    const { container } = render(
      <CategoryIdentity color="url(https://evil/color)" icon={icon} label="<img src=x>" />,
    );

    expect(screen.getByText('<img src=x>')).toBeTruthy();
    expect(container.querySelector('svg')).toBeNull();
    expect(container.querySelector('img, script, image, use, [href], [style]')).toBeNull();
    expect(container.innerHTML).not.toContain('https://evil');
  });

  it.each([null, '#FFF', '#12345678', '#AABBCC\n', 'red', 'var(--accent)', '#000000;stroke:red'])(
    'draws no colour mark for noncanonical colour input %s',
    (color) => {
      const { container } = render(<CategoryIdentity color={color} label="Restaurants" />);

      expect(container.querySelector('[style]')).toBeNull();
      expect(tag(container).hasAttribute('data-color')).toBe(false);
      expect(container.querySelector('[data-color-dot]')).toBeNull();
      expect(screen.getByText('Restaurants')).toBeTruthy();
    },
  );
});
