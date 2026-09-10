import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CategoryIdentity } from './CategoryIdentity';

function pill(container: HTMLElement) {
  return container.firstElementChild as HTMLElement;
}

describe('CategoryIdentity', () => {
  afterEach(cleanup);

  it('fills the pill with the canonical colour and derives a readable ink', () => {
    const { container } = render(
      <CategoryIdentity color="#aabbcc" icon="utensils" label="Restaurants" />,
    );

    expect(screen.getByText('Restaurants')).toBeTruthy();
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    expect(pill(container).style.background).toBe('rgb(170, 187, 204)');
    expect(pill(container).style.color).toBe('rgb(11, 11, 12)');
  });

  it('switches to light ink on a dark fill', () => {
    const { container } = render(<CategoryIdentity color="#2E7D32" label="Courses" />);

    expect(pill(container).style.color).toBe('rgb(255, 255, 255)');
  });

  // The fill is the owner's free choice, so nothing but the computed ink keeps
  // the label readable on a mid-tone colour.
  it.each([
    ['#FFFFFF', 'rgb(11, 11, 12)'],
    ['#000000', 'rgb(255, 255, 255)'],
    ['#808080', 'rgb(11, 11, 12)'],
    ['#595959', 'rgb(255, 255, 255)'],
  ])('decides the ink of %s by contrast ratio', (color, ink) => {
    const { container } = render(<CategoryIdentity color={color} label="Courses" />);

    expect(pill(container).style.color).toBe(ink);
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

  it.each([null, '#FFF', '#12345678', '#AABBCC\n', 'red', 'var(--gold-300)', '#000000;stroke:red'])(
    'renders the neutral pill for noncanonical colour input %s',
    (color) => {
      const { container } = render(<CategoryIdentity color={color} label="Restaurants" />);

      expect(container.querySelector('[style]')).toBeNull();
      expect(pill(container).className).toContain('neutral');
      expect(screen.getByText('Restaurants')).toBeTruthy();
    },
  );
});
