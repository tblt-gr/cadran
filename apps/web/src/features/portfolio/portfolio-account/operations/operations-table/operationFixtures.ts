import type { PortfolioOperation } from '@cadran/api-client';

/** A complete operation read model, overridden per test. */
export function operationFixture(overrides: Partial<PortfolioOperation> = {}): PortfolioOperation {
  return {
    id: '00000000-0000-7000-8000-0000000000a1',
    accountId: '00000000-0000-7000-8000-0000000000ac',
    type: 'DEPOSIT',
    tradeDate: '2026-03-01',
    settlementDate: null,
    sequence: 1,
    instrument: null,
    counterInstrument: null,
    quantity: null,
    unitPrice: null,
    grossAmount: { value: '1000', assetCode: 'EUR' },
    fees: { value: '0', assetCode: 'EUR' },
    taxes: { value: '0', assetCode: 'EUR' },
    cashAmount: { value: '1000', assetCode: 'EUR' },
    costBasis: null,
    counterQuantity: null,
    ratio: null,
    costAllocationRatio: null,
    roundingResidual: null,
    reference: null,
    note: null,
    linkedTransactionId: null,
    state: 'ACTIVE',
    voidedAt: null,
    voidReason: null,
    voidKind: null,
    correctsOperationId: null,
    correctedByOperationId: null,
    version: 1,
    ...overrides,
  };
}
