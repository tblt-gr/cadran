const RELOAD_FLAG = 'cadran:stale-chunk-reload';

/**
 * A tab left open across an upgrade asks for chunk files the server no longer
 * has. One reload fetches the new index and its chunks. The flag stops a loop
 * when the deployment itself is broken: a second failure is let through to the
 * route error boundary. Without usable session storage there is no way to stop
 * a loop, so the error is left to the boundary as well.
 */
export function recoverFromStaleChunk(event: Event, reload: () => void): boolean {
  try {
    if (window.sessionStorage.getItem(RELOAD_FLAG) !== null) return false;
    window.sessionStorage.setItem(RELOAD_FLAG, '1');
  } catch {
    return false;
  }

  // Vite rethrows the failed import unless the event is cancelled.
  event.preventDefault();
  reload();
  return true;
}

/** Called once a page has survived long enough to count as a healthy load. */
export function clearStaleChunkFlag(): void {
  try {
    window.sessionStorage.removeItem(RELOAD_FLAG);
  } catch {
    // Nothing to clear when storage is blocked.
  }
}
