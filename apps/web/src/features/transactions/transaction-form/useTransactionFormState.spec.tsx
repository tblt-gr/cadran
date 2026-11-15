import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useTransactionFormState } from './useTransactionFormState';

vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));

const account = {
  id: 'account-1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
  isPrimary: true,
} as Account;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>;
}

function setup(onSubmit = vi.fn()) {
  const hook = renderHook(() => useTransactionFormState({ accounts: [account], onSubmit }), {
    wrapper,
  });
  return { ...hook, onSubmit };
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-11-10T10:00:00Z'));
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

it('defaults a new entry to the primary active account', () => {
  const { result } = setup();
  expect(result.current.resolved.accountId).toBe('account-1');
  expect(result.current.editing).toBe(false);
});

it('keeps the account the user picked once chosen', () => {
  const { result } = setup();
  act(() => {
    result.current.markAccountChosen();
    result.current.set('accountId', '');
  });
  expect(result.current.resolved.accountId).toBe('');
  expect(result.current.errors.accountId).toBeTruthy();
});

it('turns a later date into a planned pending entry', () => {
  const { result } = setup();
  act(() => result.current.set('bookedOn', '2026-11-20'));
  expect(result.current.upcoming).toBe(true);
  expect(result.current.resolved.state).toBe('PENDING');
});

it('does not submit an invalid draft, and shows the errors instead', () => {
  const { result, onSubmit } = setup();
  act(() => result.current.submit({ preventDefault: () => undefined } as never));
  expect(onSubmit).not.toHaveBeenCalled();
  expect(result.current.showErrors).toBe(true);
});

it('submits a valid draft with the exact typed amount', () => {
  const { result, onSubmit } = setup();
  act(() => {
    result.current.set('rawLabel', 'Courses');
    result.current.set('amountValue', '12.30');
  });
  act(() => result.current.submit({ preventDefault: () => undefined } as never));
  expect(onSubmit).toHaveBeenCalledTimes(1);
  expect(JSON.stringify(onSubmit.mock.calls[0]![0])).toContain('12.30');
});
