import { useState } from 'react';

/** Boolean preference kept in localStorage; storage can be blocked, so it degrades to memory. */
export function usePersistedToggle(storageKey: string): [boolean, (value: boolean) => void] {
  const [value, setValue] = useState(() => {
    try {
      return window.localStorage.getItem(storageKey) === '1';
    } catch {
      return false;
    }
  });

  function update(next: boolean) {
    setValue(next);
    try {
      window.localStorage.setItem(storageKey, next ? '1' : '0');
    } catch {
      // The choice still applies until the next visit.
    }
  }

  return [value, update];
}
