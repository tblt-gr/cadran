import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { usePersistedSelection } from './use-persisted-selection';

const ALLOWED = ['A', 'B', 'C'] as const;

afterEach(() => {
  window.localStorage.clear();
  vi.restoreAllMocks();
});

describe('usePersistedSelection', () => {
  it('starts on the fallback and persists a change in the set order', () => {
    const { result } = renderHook(() => usePersistedSelection('k', ALLOWED, ['A']));
    expect(result.current[0]).toEqual(['A']);
    act(() => result.current[1](['C', 'A']));
    expect(result.current[0]).toEqual(['A', 'C']);
    expect(window.localStorage.getItem('k')).toBe('["A","C"]');
    expect(renderHook(() => usePersistedSelection('k', ALLOWED, ['A'])).result.current[0]).toEqual([
      'A',
      'C',
    ]);
  });

  it('keeps an empty choice and drops unknown members', () => {
    window.localStorage.setItem('k', '[]');
    expect(renderHook(() => usePersistedSelection('k', ALLOWED, ['A'])).result.current[0]).toEqual(
      [],
    );
    window.localStorage.setItem('k', '["Z","B"]');
    expect(renderHook(() => usePersistedSelection('k', ALLOWED, ['A'])).result.current[0]).toEqual([
      'B',
    ]);
  });

  it('falls back on a malformed value or blocked storage', () => {
    window.localStorage.setItem('k', '{oops');
    expect(renderHook(() => usePersistedSelection('k', ALLOWED, ['A'])).result.current[0]).toEqual([
      'A',
    ]);
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const { result } = renderHook(() => usePersistedSelection('k', ALLOWED, ['B']));
    expect(result.current[0]).toEqual(['B']);
    act(() => result.current[1](['C']));
    expect(result.current[0]).toEqual(['C']);
  });
});
