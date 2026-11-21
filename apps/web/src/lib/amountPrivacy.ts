/** Fixed length on purpose: the mask must not reveal the order of magnitude of an amount. */
export const AMOUNT_MASK = '••••••';

const STORAGE_KEY = 'cadran.amountsHidden';

type Listener = () => void;

const listeners = new Set<Listener>();

function readStoredPreference(): boolean {
  try {
    return window.localStorage.getItem(STORAGE_KEY) === '1';
  } catch {
    // Storage can be blocked or absent: amounts then stay visible.
    return false;
  }
}

// Read at module load, before the first render, so no amount flashes on reload.
let hidden = readStoredPreference();

export function areAmountsHidden(): boolean {
  return hidden;
}

export function setAmountsHidden(next: boolean): void {
  if (next === hidden) return;
  hidden = next;
  try {
    if (next) {
      window.localStorage.setItem(STORAGE_KEY, '1');
    } else {
      window.localStorage.removeItem(STORAGE_KEY);
    }
  } catch {
    // The choice still applies to this page view.
  }
  listeners.forEach((listener) => listener());
}

export function subscribeAmountPrivacy(listener: Listener): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}
