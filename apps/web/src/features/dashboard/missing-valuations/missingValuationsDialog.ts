import { useSyncExternalStore } from 'react';

/**
 * Whether the missing-valuations dialog is open. Two triggers live in
 * different trees (the application header and the net worth card), so the
 * flag is a tiny external store rather than state threaded through the shell.
 */
let open = false;
const listeners = new Set<() => void>();

function emit() {
  for (const listener of listeners) listener();
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function openMissingValuations() {
  open = true;
  emit();
}

export function closeMissingValuations() {
  open = false;
  emit();
}

export function useMissingValuationsOpen(): boolean {
  return useSyncExternalStore(subscribe, () => open);
}
