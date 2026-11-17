import type { Account, Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { transferCounterparts } from './transferCounterparts';

const leg = (id: string, accountId: string, transferId: string | null, nature = 'TRANSFER') =>
  ({ id, accountId, transferId, nature }) as unknown as Transaction;
const account = (id: string, label: string) => ({ id, label }) as unknown as Account;

describe('transferCounterparts', () => {
  it('names the account of the other leg for each side of a transfer', () => {
    const result = transferCounterparts(
      [leg('a', 'acc-1', 't1'), leg('b', 'acc-2', 't1')],
      [account('acc-1', 'Courant'), account('acc-2', 'Livret')],
    );
    expect(result.get('a')).toBe('Livret');
    expect(result.get('b')).toBe('Courant');
  });

  it('ignores non-transfer transactions and lone legs', () => {
    const result = transferCounterparts(
      [leg('x', 'acc-1', null, 'EXPENSE'), leg('y', 'acc-1', 't2')],
      [account('acc-1', 'Courant')],
    );
    expect(result.has('x')).toBe(false);
    expect(result.get('y')).toBeUndefined();
  });
});
