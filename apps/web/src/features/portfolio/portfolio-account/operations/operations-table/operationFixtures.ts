import type { PortfolioOperation } from '@cadran/api-client';

/** A complete operation read model, overridden per test. */
export function operationFixture(overrides: Partial<PortfolioOperation> = {}): PortfolioOperation {
  return {
    id: '00000000-0000-7000-8000-0000000000a1',
    accountId: '00000000-0000-7000-8000-0000000000ac',
    applicableSwitchFeeRate: null,
    applicableSwitchFeeFixed: null,
    legs: [],
    withheldIncomeTax: null,
    withheldSocialContributions: null,
    netPaidAmount: null,
    type: 'DEPOSIT',
    tradeDate: '2026-03-01',
    settlementDate: null,
    sequence: 1,
    activeDayOperationCount: 1,
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

/** Reference INS-004 switch: outgoing 2000, manual fee 10, incoming 1990. */
export function switchFixture(overrides: Partial<PortfolioOperation> = {}): PortfolioOperation {
  return operationFixture({
    type: 'SWITCH',
    grossAmount: { value: '2000.00', assetCode: 'EUR' },
    fees: { value: '10.00', assetCode: 'EUR' },
    cashAmount: { value: '0', assetCode: 'EUR' },
    applicableSwitchFeeRate: '0.5',
    applicableSwitchFeeFixed: null,
    legs: [
      {
        index: 1,
        direction: 'OUT',
        instrument: {
          id: 'euro',
          name: 'Fonds euros',
          isin: null,
          ticker: null,
          status: 'ACTIVE',
        },
        quantity: '2000.00',
        unitPrice: '1',
        amount: { value: '2000.00', assetCode: 'EUR' },
        roundingResidual: null,
      },
      {
        index: 2,
        direction: 'IN',
        instrument: {
          id: 'fund',
          name: 'Fonds actions',
          isin: null,
          ticker: null,
          status: 'ACTIVE',
        },
        quantity: '20',
        unitPrice: '99.50',
        amount: { value: '1990.00', assetCode: 'EUR' },
        roundingResidual: null,
      },
    ],
    ...overrides,
  });
}
