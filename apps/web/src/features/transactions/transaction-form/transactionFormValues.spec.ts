import type { Account, Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import {
  initialTransactionValues,
  transactionRequest,
  validateTransactionValues,
} from './transactionFormValues';

const account = {
  id: 'account-1',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const transaction = {
  id: 'transaction-1',
  accountId: account.id,
  amount: { value: '-1.50', assetCode: 'EUR' },
  originalAmount: null,
  exchangeRate: null,
  nature: 'EXPENSE',
  state: 'BOOKED',
  source: 'MANUAL',
  bookedOn: '2026-09-01',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CARTE BOULANGERIE',
  counterparty: null,
  note: null,
  paymentMethod: 'CARD',
  mcc: null,
  maskedCard: null,
  bankReference: null,
  splits: [],
  version: 7,
  createdAt: '2026-09-01T09:00:00+02:00',
  updatedAt: '2026-09-01T09:00:00+02:00',
  voidedAt: null,
} as Transaction;

describe('transactionFormValues', () => {
  it('keeps canonical literals and immutable edit fields unchanged in the update request', () => {
    const values = initialTransactionValues(transaction, [account], '2026-09-08');
    values.amountValue = '-1.5000';
    values.counterparty = 'Boulangerie';

    expect(transactionRequest(values, [account], transaction)).toMatchObject({
      accountId: account.id,
      amount: { value: '-1.5000', assetCode: 'EUR' },
      rawLabel: 'CARTE BOULANGERIE',
      counterparty: 'Boulangerie',
      version: 7,
    });
  });

  it('rejects surrounding whitespace and whitespace-only optional fields without normalizing them', () => {
    const values = initialTransactionValues(undefined, [account], '2026-09-08');
    Object.assign(values, {
      amountValue: ' -42.90 ',
      rawLabel: ' CB BOULANGERIE ',
      counterparty: '   ',
    });

    expect(validateTransactionValues(values, [account], '2026-09-08')).toMatchObject({
      amountValue: true,
      rawLabel: true,
      counterparty: true,
    });
  });

  it('rejects zero, a contradictory sign, bad dates and full card data', () => {
    const values = initialTransactionValues(undefined, [account], '2026-09-08');
    Object.assign(values, {
      amountValue: '0.00',
      bookedOn: '2025-12-31',
      authorizedOn: '2026-09-02',
      maskedCard: '4242424242424242',
      rawLabel: 'Test',
    });

    expect(validateTransactionValues(values, [account], '2026-09-08')).toMatchObject({
      amountValue: true,
      bookedOn: true,
      authorizedOn: true,
      maskedCard: true,
    });

    values.amountValue = '42.90';
    values.bookedOn = '2026-09-01';
    expect(validateTransactionValues(values, [account], '2026-09-08')).toMatchObject({
      amountValue: true,
      nature: true,
    });
  });

  it('accepts a 24-place canonical amount without converting it to Number', () => {
    const values = initialTransactionValues(undefined, [account], '2026-09-08');
    Object.assign(values, {
      amountValue: '-99999999999999999999999999.123456789012345678901234',
      rawLabel: 'Prélèvement exact',
    });

    expect(validateTransactionValues(values, [account], '2026-09-08')).toEqual({});
    expect(transactionRequest(values, [account])).toMatchObject({
      amount: { value: '-99999999999999999999999999.123456789012345678901234' },
    });
  });
});
