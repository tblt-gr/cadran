import { useCallback, useEffect, useRef, useState } from 'react';

/** Share of the visible width one button press scrolls, so the next tabs keep some context. */
const SCROLL_STEP = 0.8;

interface StripOverflow {
  canScrollBack: boolean;
  canScrollForward: boolean;
}

const FITS: StripOverflow = { canScrollBack: false, canScrollForward: false };

/**
 * Horizontal overflow of a tab strip: which way it can still scroll, a stepwise scroll for the
 * edge buttons, and the active tab kept in view whenever `activeKey` changes.
 */
export function useStripOverflow<T extends HTMLElement>(activeKey: string | undefined) {
  const ref = useRef<T>(null);
  const [overflow, setOverflow] = useState<StripOverflow>(FITS);

  const measure = useCallback(() => {
    const node = ref.current;
    if (node === null) {
      return;
    }
    // One pixel of slack absorbs sub-pixel widths that never let scrollLeft reach the end.
    const next = {
      canScrollBack: node.scrollLeft > 1,
      canScrollForward: node.scrollLeft + node.clientWidth < node.scrollWidth - 1,
    };
    setOverflow((current) =>
      current.canScrollBack === next.canScrollBack &&
      current.canScrollForward === next.canScrollForward
        ? current
        : next,
    );
  }, []);

  useEffect(() => {
    const node = ref.current;
    if (node === null) {
      return;
    }
    measure();
    node.addEventListener('scroll', measure, { passive: true });
    if (typeof ResizeObserver === 'undefined') {
      return () => node.removeEventListener('scroll', measure);
    }
    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => {
      node.removeEventListener('scroll', measure);
      observer.disconnect();
    };
  }, [measure]);

  useEffect(() => {
    const node = ref.current;
    const active = node?.querySelector<HTMLElement>('[aria-current="page"]');
    if (node === null || node === undefined || active === null || active === undefined) {
      return;
    }
    // Scrolls the strip only: `scrollIntoView` would also move the page vertically.
    const start = active.offsetLeft;
    const end = start + active.offsetWidth;
    if (start < node.scrollLeft) {
      node.scrollLeft = start;
    } else if (end > node.scrollLeft + node.clientWidth) {
      node.scrollLeft = end - node.clientWidth;
    }
    measure();
  }, [activeKey, measure]);

  const scrollStep = useCallback((direction: -1 | 1) => {
    const node = ref.current;
    if (node !== null) {
      node.scrollLeft += direction * node.clientWidth * SCROLL_STEP;
    }
  }, []);

  return {
    ref,
    ...overflow,
    overflows: overflow.canScrollBack || overflow.canScrollForward,
    scrollStep,
  };
}
