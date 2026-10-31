import { useState } from 'react';

/**
 * A subset of a closed set kept in localStorage, in the set's own order. Blocked storage and a
 * stored value that is not a list fall back; unknown members are dropped.
 */
export function usePersistedSelection<T extends string>(
  storageKey: string,
  allowed: readonly T[],
  fallback: readonly T[],
): [readonly T[], (value: readonly T[]) => void] {
  const ordered = (values: readonly string[]) => allowed.filter((item) => values.includes(item));
  const [value, setValue] = useState<readonly T[]>(() => {
    try {
      const stored: unknown = JSON.parse(window.localStorage.getItem(storageKey) ?? 'null');
      return Array.isArray(stored) ? ordered(stored.map(String)) : ordered(fallback);
    } catch {
      return ordered(fallback);
    }
  });

  function update(next: readonly T[]) {
    const kept = ordered(next);
    setValue(kept);
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(kept));
    } catch {
      // The selection still applies until the next visit.
    }
  }

  return [value, update];
}
