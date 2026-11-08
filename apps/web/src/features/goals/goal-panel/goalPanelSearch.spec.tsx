import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { readGoalPanelOpen, useGoalPanelOpen } from './goalPanelSearch';

describe('goal panel URL state', () => {
  afterEach(() => {
    cleanup();
    window.history.replaceState({}, '', '/');
  });

  it('reads only panel=goals', () => {
    expect(readGoalPanelOpen('?panel=goals')).toBe(true);
    expect(readGoalPanelOpen('?axis=FIXED&panel=goals')).toBe(true);
    expect(readGoalPanelOpen('?panel=other')).toBe(false);
    expect(readGoalPanelOpen('')).toBe(false);
  });

  it('reopens from a reloaded URL and closes it without leaving the page', () => {
    window.history.replaceState({}, '', '/budget/2026/11?panel=goals');
    const { result } = renderHook(() => useGoalPanelOpen());
    expect(result.current.open).toBe(true);

    act(() => result.current.closePanel());
    expect(result.current.open).toBe(false);
    expect(window.location.pathname + window.location.search).toBe('/budget/2026/11');
  });

  it('pushes an entry on open, keeping the other parameters, and Back closes it', async () => {
    window.history.replaceState({}, '', '/budget/2026/11?axis=FIXED');
    const { result } = renderHook(() => useGoalPanelOpen());
    expect(result.current.open).toBe(false);

    act(() => result.current.openPanel());
    expect(result.current.open).toBe(true);
    expect(window.location.search).toBe('?axis=FIXED&panel=goals');

    await act(async () => {
      window.history.back();
      await new Promise((resolve) => setTimeout(resolve, 50));
    });
    expect(result.current.open).toBe(false);
    expect(window.location.search).toBe('?axis=FIXED');
  });

  it('closing a panel opened in this session steps back through history', async () => {
    window.history.replaceState({}, '', '/budget/2026/11');
    const { result } = renderHook(() => useGoalPanelOpen());
    act(() => result.current.openPanel());

    await act(async () => {
      result.current.closePanel();
      await new Promise((resolve) => setTimeout(resolve, 50));
    });
    expect(result.current.open).toBe(false);
    expect(window.location.search).toBe('');
  });
});
