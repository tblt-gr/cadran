import { cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useSwipeNavigation } from './use-swipe-navigation';

function Probe({ path, navigate }: { path: string; navigate: (href: string) => void }) {
  useSwipeNavigation(path, navigate);
  return <main>page</main>;
}

function touch(
  type: 'touchstart' | 'touchend',
  x: number,
  y: number,
  target: Element = document.body,
) {
  const event = new Event(type, { bubbles: true });
  const point = { clientX: x, clientY: y };
  Object.defineProperty(event, type === 'touchstart' ? 'touches' : 'changedTouches', {
    value: [point],
  });
  if (type === 'touchend') Object.defineProperty(event, 'touches', { value: [] });
  target.dispatchEvent(event);
}

function swipe(fromX: number, toX: number, y = 400, target?: Element) {
  touch('touchstart', fromX, y, target);
  touch('touchend', toX, y + 10, target);
}

describe('useSwipeNavigation', () => {
  const original = window.matchMedia;
  beforeEach(() => {
    window.matchMedia = ((query: string) => ({
      matches: true,
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
  });
  afterEach(() => {
    cleanup();
    window.matchMedia = original;
    document.body.innerHTML = '';
  });

  it('goes to the next destination on a left swipe and back on a right swipe', () => {
    const navigate = vi.fn();
    render(<Probe navigate={navigate} path="/" />);
    swipe(300, 100);
    expect(navigate).toHaveBeenLastCalledWith('/budget');
    cleanup();
    const back = vi.fn();
    render(<Probe navigate={back} path="/budget" />);
    swipe(100, 300);
    expect(back).toHaveBeenLastCalledWith('/');
  });

  it('stops at both ends of the navigation', () => {
    const navigate = vi.fn();
    render(<Probe navigate={navigate} path="/" />);
    swipe(100, 300);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('ignores short, vertical and edge swipes, and anything inside a dialog or a no-swipe zone', () => {
    const navigate = vi.fn();
    render(<Probe navigate={navigate} path="/" />);
    swipe(300, 260);
    touch('touchstart', 300, 100);
    touch('touchend', 150, 300);
    swipe(5, 200);
    expect(navigate).not.toHaveBeenCalled();
    const zone = document.createElement('div');
    zone.setAttribute('data-no-swipe', '');
    document.body.append(zone);
    swipe(300, 100, 400, zone);
    expect(navigate).not.toHaveBeenCalled();
    const dialog = document.createElement('div');
    dialog.setAttribute('role', 'dialog');
    document.body.append(dialog);
    swipe(300, 100);
    expect(navigate).not.toHaveBeenCalled();
  });
});
