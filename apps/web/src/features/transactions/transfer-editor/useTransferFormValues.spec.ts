import type { Account } from '@cadran/api-client';
import { act, renderHook } from '@testing-library/react';
import { expect, it } from 'vitest';
import { useTransferFormValues } from './useTransferFormValues';

const source = { id: 'source', status: 'ACTIVE' } as Account;
const other = { id: 'other', status: 'ACTIVE' } as Account;
const primary = { id: 'primary', status: 'ACTIVE', isPrimary: true } as Account;

it.each(['other', ''])(
  'preserves an explicitly chosen destination %j when the primary loads',
  (destination) => {
    const { result, rerender } = renderHook(
      ({ accounts }) =>
        useTransferFormValues(accounts, '2026-03-10', { lockedSourceAccountId: source.id }),
      { initialProps: { accounts: [source, other] } },
    );
    act(() => result.current.set('targetAccountId', destination));
    rerender({ accounts: [source, other, primary] });
    expect(result.current.values.targetAccountId).toBe(destination);
    expect(result.current.values.sourceAccountId).toBe(source.id);
  },
);
