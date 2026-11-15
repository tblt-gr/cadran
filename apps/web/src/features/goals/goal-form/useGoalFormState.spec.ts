import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useGoalFormState } from './useGoalFormState';

vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-09-15T10:00:00Z'));
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

it('starts invalid and becomes valid once the label and the amount are typed', () => {
  const { result } = renderHook(() => useGoalFormState(undefined));
  expect(result.current.formValid).toBe(false);
  expect(result.current.labelValid).toBe(false);

  act(() => {
    result.current.dispatch({ type: 'label', value: 'Fonds' });
    result.current.dispatch({ type: 'value', value: '400.50' });
    result.current.dispatch({ type: 'tolerance', value: '0' });
  });
  expect(result.current.formValid).toBe(true);
});

it('rejects an amount with more than eight decimals or a zero amount', () => {
  const { result } = renderHook(() => useGoalFormState(undefined));
  act(() => result.current.dispatch({ type: 'value', value: '0' }));
  expect(result.current.decimalValid).toBe(false);
  act(() => result.current.dispatch({ type: 'value', value: '1.123456789' }));
  expect(result.current.decimalValid).toBe(false);
});

it('requires a scope for a scoped metric and none for the workspace', () => {
  const { result } = renderHook(() => useGoalFormState(undefined));
  expect(result.current.scopeValid).toBe(true);
  act(() => result.current.dispatch({ type: 'metric', value: 'CATEGORY_SPENDING' }));
  expect(result.current.scopeValid).toBe(false);
  act(() => result.current.dispatch({ type: 'scope', value: 'category-1' }));
  expect(result.current.scopeValid).toBe(true);
});

it('shows errors only after the flag is raised', () => {
  const { result } = renderHook(() => useGoalFormState(undefined));
  expect(result.current.showErrors).toBe(false);
  act(() => result.current.setShowErrors(true));
  expect(result.current.showErrors).toBe(true);
});

it('resets to the initial state', () => {
  const { result } = renderHook(() => useGoalFormState(undefined));
  act(() => result.current.dispatch({ type: 'label', value: 'Fonds' }));
  act(() => result.current.reset(undefined));
  expect(result.current.state.label).toBe('');
});
