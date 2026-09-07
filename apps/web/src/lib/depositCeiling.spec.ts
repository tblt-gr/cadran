import { describe, expect, it } from 'vitest';
import { ceilingFillPercent, depositCeilingOf } from './depositCeiling';
import type { Account, Product, ProductModel } from '@cadran/api-client';

const account = {
  productCode: 'FR_LIVRET_A',
  productModelId: null,
} as Account;

const livretA = {
  code: 'FR_LIVRET_A',
  rules: [{ kind: 'DEPOSIT_CEILING', amount: { value: '22950', assetCode: 'EUR' } }],
} as Product;

describe('depositCeilingOf', () => {
  it('reads the catalogue ceiling and invents no figure', () => {
    expect(depositCeilingOf(account, [livretA], [])).toEqual({
      assetCode: 'EUR',
      value: '22950',
    });
  });

  it('keeps the product ceiling when a reduced model also names a figure', () => {
    const reduced = {
      id: 'model-reduced',
      rules: [{ kind: 'DEPOSIT_CEILING', amount: { value: '19125', assetCode: 'EUR' } }],
    } as ProductModel;

    expect(
      depositCeilingOf({ ...account, productModelId: reduced.id }, [livretA], [reduced]),
    ).toEqual({
      assetCode: 'EUR',
      value: '22950',
    });
  });

  it('returns nothing when the origin states no ceiling', () => {
    expect(depositCeilingOf(account, [], [])).toBeNull();
  });
});

describe('ceilingFillPercent', () => {
  it('fills the track from the observed balance against the product ceiling', () => {
    expect(ceilingFillPercent('20100.00', '22950')).toBeCloseTo((20100 / 22950) * 100, 10);
  });

  it('returns nothing when the ceiling cannot size a bar', () => {
    expect(ceilingFillPercent('20100.00', '0')).toBeNull();
  });
});
