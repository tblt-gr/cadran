import { useState } from 'react';

export const LIST_WINDOW_SIZE = 200;

/** Advancing beyond the bounded DOM is explicit, so rows never vanish while being read. */
export function useListWindow<T>(items: T[], resetKey: string) {
  const [window, setWindow] = useState({ resetKey, start: 0 });
  const start = window.resetKey === resetKey && window.start < items.length ? window.start : 0;
  const end = Math.min(start + LIST_WINDOW_SIZE, items.length);
  return {
    items: items.slice(start, end),
    start,
    end,
    full: end - start >= LIST_WINDOW_SIZE,
    hasPrevious: start > 0,
    hasNextLoaded: end < items.length,
    previous: () => setWindow({ resetKey, start: Math.max(0, start - LIST_WINDOW_SIZE) }),
    next: () => setWindow({ resetKey, start: start + LIST_WINDOW_SIZE }),
  };
}
