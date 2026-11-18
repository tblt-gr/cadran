import type { Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { orderTransferLegs } from './orderTransferLegs';

const tx = (
  id: string,
  transferId: string | null,
  value: string,
  nature = 'TRANSFER',
  bookedOn = '2026-09-11',
) => ({ id, transferId, nature, bookedOn, amount: { value } }) as unknown as Transaction;
const plain = (id: string, bookedOn = '2026-09-11') => tx(id, null, '-3.00', 'EXPENSE', bookedOn);
const ids = (rows: Transaction[]) => orderTransferLegs(rows).map((r) => r.id);

describe('orderTransferLegs', () => {
  it('lists the withdrawal before the credit', () => {
    expect(ids([tx('credit', 't1', '25.00'), tx('debit', 't1', '-25.00')])).toEqual([
      'debit',
      'credit',
    ]);
  });

  it('keeps a pair already in money order', () => {
    expect(ids([tx('debit', 't1', '-25.00'), tx('credit', 't1', '25.00')])).toEqual([
      'debit',
      'credit',
    ]);
  });

  it('lists the fee between the withdrawal and the credit', () => {
    expect(
      ids([
        tx('fee', 't1', '-1.00', 'FEE'),
        tx('credit', 't1', '25.00'),
        tx('debit', 't1', '-25.00'),
      ]),
    ).toEqual(['debit', 'fee', 'credit']);
  });

  it('keeps the other rows in place and never mixes two transfers', () => {
    expect(
      ids([
        tx('c2', 't2', '5.00'),
        tx('d2', 't2', '-5.00'),
        plain('plain'),
        tx('c1', 't1', '9.00'),
        tx('d1', 't1', '-9.00'),
      ]),
    ).toEqual(['d2', 'c2', 'plain', 'd1', 'c1']);
  });

  it('gathers the legs of two interleaved transfers at the first leg met', () => {
    expect(
      ids([
        tx('c1', 't1', '9.00'),
        tx('c2', 't2', '5.00'),
        tx('d1', 't1', '-9.00'),
        tx('d2', 't2', '-5.00'),
      ]),
    ).toEqual(['d1', 'c1', 'd2', 'c2']);
  });

  it('gathers the legs of a transfer another row separates', () => {
    expect(ids([tx('credit', 't1', '25.00'), plain('plain'), tx('debit', 't1', '-25.00')])).toEqual(
      ['debit', 'credit', 'plain'],
    );
  });

  it('leaves a lone leg where it is', () => {
    expect(ids([tx('only', 't1', '25.00'), plain('plain')])).toEqual(['only', 'plain']);
  });

  it('never moves a leg to another day', () => {
    expect(
      ids([tx('credit', 't1', '25.00', 'TRANSFER', '2026-09-12'), tx('debit', 't1', '-25.00')]),
    ).toEqual(['credit', 'debit']);
  });
});
