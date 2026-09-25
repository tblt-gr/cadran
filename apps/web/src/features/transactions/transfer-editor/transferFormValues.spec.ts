import type { Account } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import {
  initialTransferValues,
  isCrossAssetTransfer,
  transferRequest,
  validateTransferValues,
} from './transferFormValues';

const source = {
  id: 'account-source',
  label: 'Compte courant',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const target = {
  id: 'account-target',
  label: 'Épargne',
  assetCode: 'EUR',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

const chfTarget = {
  id: 'account-chf',
  label: 'Compte suisse',
  assetCode: 'CHF',
  openedOn: '2026-01-01',
  status: 'ACTIVE',
} as Account;

function values(overrides: Partial<ReturnType<typeof initialTransferValues>> = {}) {
  return {
    ...initialTransferValues([source, target], '2026-09-08'),
    sourceAccountId: source.id,
    targetAccountId: target.id,
    sourceAmountValue: '500.00',
    label: 'Virement épargne',
    ...overrides,
  };
}

describe('transferFormValues', () => {
  it('shows the second amount field only when the accounts do not share an asset', () => {
    expect(isCrossAssetTransfer(values(), [source, target, chfTarget])).toBe(false);
    expect(
      isCrossAssetTransfer(values({ targetAccountId: chfTarget.id }), [source, target, chfTarget]),
    ).toBe(true);
  });

  it('accepts a valid same-asset transfer without a target amount', () => {
    expect(validateTransferValues(values(), [source, target], '2026-09-08')).toEqual({});
  });

  it('refuses the same account on both sides', () => {
    expect(
      validateTransferValues(
        values({ targetAccountId: source.id }),
        [source, target],
        '2026-09-08',
      ),
    ).toEqual({ targetAccountId: true });
  });

  it('refuses a zero or negative source amount', () => {
    expect(
      validateTransferValues(values({ sourceAmountValue: '0.00' }), [source, target], '2026-09-08'),
    ).toEqual({ sourceAmountValue: true });
    expect(
      validateTransferValues(
        values({ sourceAmountValue: '-500.00' }),
        [source, target],
        '2026-09-08',
      ),
    ).toEqual({ sourceAmountValue: true });
  });

  it('requires a positive target amount for a cross-asset transfer', () => {
    const crossAsset = values({ targetAccountId: chfTarget.id });
    expect(validateTransferValues(crossAsset, [source, target, chfTarget], '2026-09-08')).toEqual({
      targetAmountValue: true,
    });
    expect(
      validateTransferValues(
        { ...crossAsset, targetAmountValue: '540.25' },
        [source, target, chfTarget],
        '2026-09-08',
      ),
    ).toEqual({});
  });

  it('refuses a booked date before either account opened', () => {
    expect(
      validateTransferValues(values({ bookedOn: '2025-12-31' }), [source, target], '2026-09-08'),
    ).toEqual({
      bookedOn: true,
    });
  });

  it('refuses an empty label', () => {
    expect(validateTransferValues(values({ label: '' }), [source, target], '2026-09-08')).toEqual({
      label: true,
    });
  });

  it('requires a positive fee magnitude once a fee is toggled on', () => {
    expect(
      validateTransferValues(
        values({ hasFee: true, feeValue: '' }),
        [source, target],
        '2026-09-08',
      ),
    ).toEqual({ feeValue: true });
    expect(
      validateTransferValues(
        values({ hasFee: true, feeValue: '2.50' }),
        [source, target],
        '2026-09-08',
      ),
    ).toEqual({});
  });

  it('builds a same-asset request with no target amount and applies the fee sign', () => {
    const body = transferRequest(values({ hasFee: true, feeValue: '2.50' }), [source, target]);

    expect(body).toEqual({
      sourceAccountId: source.id,
      targetAccountId: target.id,
      sourceAmount: { value: '500.00', assetCode: 'EUR' },
      targetAmount: null,
      state: 'BOOKED',
      bookedOn: '2026-09-08',
      valueOn: null,
      label: 'Virement épargne',
      note: null,
      fee: { value: '-2.50', assetCode: 'EUR' },
    });
  });

  it('builds a cross-asset request carrying the target amount', () => {
    const body = transferRequest(
      values({ targetAccountId: chfTarget.id, targetAmountValue: '540.25' }),
      [source, target, chfTarget],
    );

    expect(body.targetAmount).toEqual({ value: '540.25', assetCode: 'CHF' });
  });
});
