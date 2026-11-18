import { useEffect } from 'react';
import { navigationItems } from '@/lib/navigation';

/** Fingers cover a short distance: far enough to be a choice, mostly horizontal, and quick. */
const MIN_DISTANCE = 80;
const HORIZONTAL_RATIO = 2;
const MAX_DURATION_MS = 700;
/** A swipe starting at a screen edge belongs to the browser's own back and forward gesture. */
const EDGE_GUARD = 24;
const PHONE_QUERY = '(max-width: 767px)';

/** Something that already uses a horizontal drag: a scrolling strip or table, a chart, a field. */
function usesHorizontalDrag(start: EventTarget | null): boolean {
  let element = start instanceof Element ? start : null;
  while (element && element !== document.body) {
    if (element.matches('input, textarea, select, [role="slider"], [data-no-swipe]')) return true;
    const overflow = getComputedStyle(element).overflowX;
    if ((overflow === 'auto' || overflow === 'scroll') && element.scrollWidth > element.clientWidth)
      return true;
    element = element.parentElement;
  }
  return false;
}

/**
 * On a phone, a horizontal swipe moves to the next or previous destination of the main
 * navigation: swiping left goes on, swiping right goes back. It stays out of the way of an open
 * dialog and of anything that scrolls or drags sideways.
 */
export function useSwipeNavigation(path: string, navigate: (href: string) => void): void {
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return undefined;
    let start: { x: number; y: number; at: number; target: EventTarget | null } | null = null;

    const onStart = (event: TouchEvent) => {
      const touch = event.touches[0];
      const blocked =
        event.touches.length !== 1 ||
        !touch ||
        !window.matchMedia(PHONE_QUERY).matches ||
        touch.clientX < EDGE_GUARD ||
        touch.clientX > window.innerWidth - EDGE_GUARD ||
        document.querySelector('[role="dialog"], [aria-modal="true"]') !== null ||
        usesHorizontalDrag(event.target);
      start = blocked
        ? null
        : { x: touch.clientX, y: touch.clientY, at: Date.now(), target: event.target };
    };

    const onEnd = (event: TouchEvent) => {
      const from = start;
      start = null;
      const touch = event.changedTouches[0];
      if (!from || !touch) return;
      const dx = touch.clientX - from.x;
      const dy = touch.clientY - from.y;
      if (Math.abs(dx) < MIN_DISTANCE) return;
      if (Math.abs(dx) < Math.abs(dy) * HORIZONTAL_RATIO) return;
      if (Date.now() - from.at > MAX_DURATION_MS) return;
      const current = navigationItems.findIndex((item) => item.match(path));
      if (current === -1) return;
      const next = navigationItems[current + (dx < 0 ? 1 : -1)];
      if (next) navigate(next.href);
    };

    document.addEventListener('touchstart', onStart, { passive: true });
    document.addEventListener('touchend', onEnd, { passive: true });
    return () => {
      document.removeEventListener('touchstart', onStart);
      document.removeEventListener('touchend', onEnd);
    };
  }, [path, navigate]);
}
