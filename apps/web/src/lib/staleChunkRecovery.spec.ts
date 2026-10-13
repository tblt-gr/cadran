import { afterEach, describe, expect, it, vi } from 'vitest';
import { clearStaleChunkFlag, recoverFromStaleChunk } from './staleChunkRecovery';

afterEach(() => {
  window.sessionStorage.clear();
  vi.restoreAllMocks();
});

function preloadError() {
  return new Event('vite:preloadError', { cancelable: true });
}

describe('recoverFromStaleChunk', () => {
  it('reloads once and cancels the error so the page does not break first', () => {
    const reload = vi.fn();
    const event = preloadError();

    expect(recoverFromStaleChunk(event, reload)).toBe(true);

    expect(reload).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
  });

  it('lets the error through on a second failure so a broken deployment cannot loop', () => {
    const reload = vi.fn();
    recoverFromStaleChunk(preloadError(), reload);
    const second = preloadError();

    expect(recoverFromStaleChunk(second, reload)).toBe(false);

    expect(reload).toHaveBeenCalledTimes(1);
    expect(second.defaultPrevented).toBe(false);
  });

  it('reloads again after the flag was cleared by a healthy load', () => {
    const reload = vi.fn();
    recoverFromStaleChunk(preloadError(), reload);

    clearStaleChunkFlag();
    recoverFromStaleChunk(preloadError(), reload);

    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('does not reload when session storage is unavailable, as it could not stop a loop', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const reload = vi.fn();

    expect(recoverFromStaleChunk(preloadError(), reload)).toBe(false);

    expect(reload).not.toHaveBeenCalled();
  });

  it('never throws when clearing a blocked storage', () => {
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked');
    });

    expect(() => clearStaleChunkFlag()).not.toThrow();
  });
});
