import { useState } from 'react';

/** One value among a closed set kept in localStorage; blocked storage and unknown values fall back. */
export function usePersistedChoice<T extends string>(
  storageKey: string,
  allowed: readonly T[],
  fallback: T,
): [T, (value: T) => void] {
  const [value, setValue] = useState<T>(() => {
    try {
      const stored = window.localStorage.getItem(storageKey);
      return allowed.find((candidate) => candidate === stored) ?? fallback;
    } catch {
      return fallback;
    }
  });

  function update(next: T) {
    setValue(next);
    try {
      window.localStorage.setItem(storageKey, next);
    } catch {
      // The choice still applies until the next visit.
    }
  }

  return [value, update];
}
