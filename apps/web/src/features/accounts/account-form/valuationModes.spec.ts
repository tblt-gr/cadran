import type { Product, ProductModel } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { defaultValuationMode } from './valuationModes';

const livretA = {
  code: 'FR_LIVRET_A',
  displayName: 'Livret A',
  accountKind: 'SAVINGS',
  capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'],
} as Product;

const snapshotTemplate = {
  id: '00000000-0000-7000-8000-0000000000f1',
  name: 'Livret valorisé par photo',
  family: 'SAVINGS',
  valuationMode: 'SNAPSHOTS',
  capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS'],
} as ProductModel;

describe('defaultValuationMode', () => {
  it('starts a snapshot-valued template on snapshots even when movements are supported', () => {
    expect(defaultValuationMode({ type: 'template', template: snapshotTemplate })).toBe(
      'SNAPSHOTS',
    );
  });

  it('keeps ranking catalogue products by capability, not by a mode they do not declare', () => {
    expect(defaultValuationMode({ type: 'product', product: livretA })).toBe('TRANSACTIONS');
  });
});
