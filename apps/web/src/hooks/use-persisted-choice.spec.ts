import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { usePersistedChoice } from './use-persisted-choice';

const ALLOWED = ['A', 'B'] as const;

afterEach(() => {
  window.localStorage.clear();
  vi.restoreAllMocks();
});

describe('usePersistedChoice', () => {
  it('starts on the fallback and persists a change', () => {
    const { result } = renderHook(() => usePersistedChoice('k', ALLOWED, 'A'));
    expect(result.current[0]).toBe('A');
    act(() => result.current[1]('B'));
    expect(result.current[0]).toBe('B');
    expect(window.localStorage.getItem('k')).toBe('B');
    expect(renderHook(() => usePersistedChoice('k', ALLOWED, 'A')).result.current[0]).toBe('B');
  });

  it('ignores an unknown stored value', () => {
    window.localStorage.setItem('k', 'Z');
    expect(renderHook(() => usePersistedChoice('k', ALLOWED, 'A')).result.current[0]).toBe('A');
  });

  it('degrades to memory when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const { result } = renderHook(() => usePersistedChoice('k', ALLOWED, 'A'));
    expect(result.current[0]).toBe('A');
    act(() => result.current[1]('B'));
    expect(result.current[0]).toBe('B');
  });
});
