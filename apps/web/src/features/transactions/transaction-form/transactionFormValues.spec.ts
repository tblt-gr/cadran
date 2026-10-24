import type { Account, Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import {
  categoryTypeForAmount,
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
  sourceRef: null,
  bookedOn: '2026-09-01',
  valueOn: null,
  authorizedOn: null,
  rawLabel: 'CARTE BOULANGERIE',
  userLabel: null,
  displayLabel: 'CARTE BOULANGERIE',
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
  transferId: null,
  refundOriginalId: null,
  refundOriginalLabel: null,
  refundedAmount: null,
  reviewReason: null,
  reconciliationCandidateIds: [],
  reconciledIntoId: null,
} as Transaction;

describe('transactionFormValues', () => {
  it('refuses a single category whose type contradicts the amount sign', () => {
    const values = {
      ...initialTransactionValues(undefined, [account], '2026-09-08'),
      amountValue: '-12.00',
      rawLabel: 'VIREMENT',
      categoryId: 'category-income',
      categoryType: 'INCOME' as const,
    };

    expect(validateTransactionValues(values, [account], '2026-09-08')).toEqual({
      categoryId: true,
    });
    expect(
      validateTransactionValues(
        { ...values, amountValue: '12.00', nature: 'INCOME' },
        [account],
        '2026-09-08',
      ),
    ).toEqual({});
    // An unknown type contradicts nothing: the server keeps the last word.
    expect(
      validateTransactionValues({ ...values, categoryType: null }, [account], '2026-09-08'),
    ).toEqual({});
  });

  it('reads the type of a saved category from the sign it was accepted with', () => {
    const categorised = {
      ...transaction,
      splits: [
        {
          id: 'split-1',
          categoryId: 'category-1',
          categoryLabel: 'Boulangerie',
          categoryIcon: null,
          categoryColor: null,
          amount: transaction.amount,
          analyticAxes: ['ESSENTIAL'],
          note: null,
        },
      ],
    } as Transaction;

    const values = initialTransactionValues(categorised, [account], '2026-09-08');

    expect(values.categoryType).toBe('EXPENSE');
    expect(values.splits[0]?.categoryType).toBe('EXPENSE');
    expect(initialTransactionValues(transaction, [account], '2026-09-08').categoryType).toBeNull();
  });

  it('uses the selected nature to load categories before an amount is entered', () => {
    expect(categoryTypeForAmount('', 'EXPENSE')).toBe('EXPENSE');
    expect(categoryTypeForAmount('', 'FEE')).toBe('EXPENSE');
    expect(categoryTypeForAmount('', 'INCOME')).toBe('INCOME');
    expect(categoryTypeForAmount('-42.90', 'INCOME')).toBe('EXPENSE');
    expect(categoryTypeForAmount('42.90', 'EXPENSE')).toBe('INCOME');
  });

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

  it('sends a display label over an imported transaction while echoing its source label', () => {
    const imported = {
      ...transaction,
      source: 'IMPORT' as const,
      rawLabel: 'CB PAIEMENT 0412',
      displayLabel: 'CB PAIEMENT 0412',
    } as Transaction;
    const values = initialTransactionValues(imported, [account], '2026-09-08');
    expect(values.userLabel).toBe('CB PAIEMENT 0412');
    expect(transactionRequest(values, [account], imported)).toMatchObject({
      rawLabel: 'CB PAIEMENT 0412',
      userLabel: null,
    });

    values.userLabel = 'Boulangerie du coin';
    expect(transactionRequest(values, [account], imported)).toMatchObject({
      rawLabel: 'CB PAIEMENT 0412',
      userLabel: 'Boulangerie du coin',
    });

    values.userLabel = '   ';
    expect(transactionRequest(values, [account], imported)).toMatchObject({ userLabel: null });
  });

  it('prefills the saved display label and rejects one over 140 characters', () => {
    const labelled = {
      ...transaction,
      source: 'PROVIDER' as const,
      userLabel: 'Pain',
      displayLabel: 'Pain',
    } as Transaction;
    const values = initialTransactionValues(labelled, [account], '2026-09-08');
    expect(values.userLabel).toBe('Pain');

    values.userLabel = 'x'.repeat(141);
    expect(validateTransactionValues(values, [account], '2026-09-08', labelled)).toMatchObject({
      userLabel: true,
    });
  });

  it('keeps editing the source label of a manual transaction', () => {
    const values = initialTransactionValues(transaction, [account], '2026-09-08');
    values.rawLabel = 'Boulangerie';

    expect(transactionRequest(values, [account], transaction)).toMatchObject({
      rawLabel: 'Boulangerie',
      userLabel: null,
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

    values.amountValue = '-42.90';
    values.nature = 'INCOME';
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

  it('accepts the immutable historical account while editing', () => {
    const historicalAccount = { ...account, status: 'ARCHIVED' } as Account;
    const values = initialTransactionValues(transaction, [historicalAccount], '2026-09-08');

    expect(
      validateTransactionValues(values, [historicalAccount], '2026-09-08', transaction),
    ).toEqual({});
    expect(validateTransactionValues(values, [], '2026-09-08', transaction)).toEqual({});
    expect(validateTransactionValues(values, [historicalAccount], '2026-09-08')).toMatchObject({
      accountId: true,
    });
  });

  describe('single-category axes', () => {
    const saved = {
      id: 'split-1',
      categoryId: 'category-1',
      categoryColor: null,
      categoryIcon: null,
      categoryLabel: 'Abonnement',
      amount: { value: '-1.50', assetCode: 'EUR' },
      analyticAxes: ['FIXED'],
      note: 'garde-moi',
    } as Transaction['splits'][number];
    const withSplit = { ...transaction, splits: [saved] } as Transaction;

    it('shows the stored axes of a saved single-split transaction', () => {
      expect(initialTransactionValues(withSplit, [account], '2026-09-08').analyticAxes).toEqual([
        'FIXED',
      ]);
      expect(initialTransactionValues(undefined, [account], '2026-09-08').analyticAxes).toBeNull();
    });

    it('inherits the category defaults, without a split, while the axes are null', () => {
      const values = {
        ...initialTransactionValues(undefined, [account], '2026-09-08'),
        amountValue: '-12.00',
        categoryId: 'category-1',
      };

      expect(transactionRequest(values, [account])).toMatchObject({
        categoryId: 'category-1',
        splits: null,
      });
    });

    it('sends an explicit override, even empty, as one split covering the amount', () => {
      const values = {
        ...initialTransactionValues(undefined, [account], '2026-09-08'),
        amountValue: '-12.00',
        categoryId: 'category-1',
        analyticAxes: [],
      };

      expect(transactionRequest(values, [account])).toMatchObject({
        categoryId: null,
        splits: [
          {
            categoryId: 'category-1',
            amount: { value: '-12.00', assetCode: 'EUR' },
            analyticAxes: [],
            note: null,
          },
        ],
      });
    });

    it('keeps the plain category path and the note when an edit leaves the axes untouched', () => {
      const values = initialTransactionValues(withSplit, [account], '2026-09-08');

      expect(transactionRequest(values, [account], withSplit)).toMatchObject({
        categoryId: 'category-1',
        splits: null,
      });
      expect(
        transactionRequest({ ...values, analyticAxes: ['VARIABLE'] }, [account], withSplit),
      ).toMatchObject({
        categoryId: null,
        splits: [{ analyticAxes: ['VARIABLE'], note: 'garde-moi' }],
      });
    });
  });
  describe('unsigned amount entry', () => {
    const base = () => ({
      ...initialTransactionValues(undefined, [account], '2026-09-08'),
      bookedOn: '2026-09-01',
      rawLabel: 'Test',
    });
    const amountOf = (values: ReturnType<typeof base>) =>
      transactionRequest(values, [account]).amount.value;

    it('records an unsigned expense, fee and a signed expense identically', () => {
      expect(amountOf({ ...base(), amountValue: '42.10' })).toBe('-42.10');
      expect(amountOf({ ...base(), amountValue: '-42.10' })).toBe('-42.10');
      expect(amountOf({ ...base(), amountValue: '42.10', nature: 'FEE' })).toBe('-42.10');
    });

    it('keeps an income positive and refuses a typed minus on it', () => {
      const income = { ...base(), amountValue: '42.10', nature: 'INCOME' as const };
      expect(amountOf(income)).toBe('42.10');
      expect(validateTransactionValues(income, [account], '2026-09-08')).toEqual({});
      expect(
        validateTransactionValues({ ...income, amountValue: '-42.10' }, [account], '2026-09-08'),
      ).toMatchObject({ amountValue: true, nature: true });
    });

    it('does not guess the sign of an adjustment', () => {
      expect(amountOf({ ...base(), amountValue: '42.10', nature: 'ADJUSTMENT' })).toBe('42.10');
      expect(amountOf({ ...base(), amountValue: '-42.10', nature: 'ADJUSTMENT' })).toBe('-42.10');
    });

    it('takes the direction of a single category on an adjustment', () => {
      expect(
        amountOf({
          ...base(),
          amountValue: '42.10',
          categoryType: 'EXPENSE',
          nature: 'ADJUSTMENT',
        }),
      ).toBe('-42.10');
    });

    it('refuses zero and keeps a 24-place amount exact', () => {
      expect(
        validateTransactionValues({ ...base(), amountValue: '0' }, [account], '2026-09-08'),
      ).toMatchObject({ amountValue: true });
      expect(
        amountOf({ ...base(), amountValue: '99999999999999999999999999.123456789012345678901234' }),
      ).toBe('-99999999999999999999999999.123456789012345678901234');
    });

    it('signs unsigned split rows that sum exactly to the signed total', () => {
      const values = {
        ...base(),
        amountValue: '87.40',
        splitMode: true,
        splits: [
          { amount: '62.10', analyticAxes: null, categoryId: 'a', key: 'a', note: '' },
          { amount: '25.30', analyticAxes: null, categoryId: 'b', key: 'b', note: '' },
        ],
      };

      expect(validateTransactionValues(values, [account], '2026-09-08')).toEqual({});
      const request = transactionRequest(values, [account]);
      expect(request.amount.value).toBe('-87.40');
      expect(request.splits?.map((split) => split.amount.value)).toEqual(['-62.10', '-25.30']);
      expect(
        validateTransactionValues(
          { ...values, splits: [{ ...values.splits[0]!, amount: '62.00' }, values.splits[1]!] },
          [account],
          '2026-09-08',
        ),
      ).toMatchObject({ splits: true });
    });

    it('shows a saved expense the way it is typed, and a saved income as is', () => {
      expect(initialTransactionValues(transaction, [account], '2026-09-08').amountValue).toBe(
        '1.50',
      );
      expect(
        initialTransactionValues(
          { ...transaction, amount: { value: '9.00', assetCode: 'EUR' }, nature: 'INCOME' },
          [account],
          '2026-09-08',
        ).amountValue,
      ).toBe('9.00');
    });
  });
});
