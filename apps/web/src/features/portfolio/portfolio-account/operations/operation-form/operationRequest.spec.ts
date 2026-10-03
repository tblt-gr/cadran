import { describe, expect, it } from 'vitest';
import { buildOperationRequest, emptyValues, type OperationFormValues } from './operationRequest';

function values(overrides: Partial<OperationFormValues>): OperationFormValues {
  return { ...emptyValues('2026-03-10'), ...overrides };
}
const instrument = { id: 'ins-1', name: 'ETF Monde' };

describe('buildOperationRequest', () => {
  it('sends exact decimal strings and no cash effect for a buy', () => {
    const result = buildOperationRequest(
      values({
        type: 'BUY',
        instrument,
        quantity: '10',
        unitPrice: '230.5688',
        grossAmount: '2305.690',
        fees: '1.99',
      }),
      'EUR',
    );
    expect(result.errors).toBeNull();
    expect(result.request).toEqual({
      type: 'BUY',
      tradeDate: '2026-03-10',
      instrumentId: 'ins-1',
      quantity: '10',
      unitPrice: '230.5688',
      grossAmount: { value: '2305.690', assetCode: 'EUR' },
      fees: { value: '1.99', assetCode: 'EUR' },
    });
    expect(result.request).not.toHaveProperty('cashAmount');
  });

  it('accepts a decimal comma and omits empty optional fields', () => {
    const result = buildOperationRequest(
      values({ type: 'DEPOSIT', grossAmount: '1000,50', note: '  ' }),
      'EUR',
    );
    expect(result.request).toEqual({
      type: 'DEPOSIT',
      tradeDate: '2026-03-10',
      grossAmount: { value: '1000.50', assetCode: 'EUR' },
    });
  });

  it('drops fields the type does not carry', () => {
    const result = buildOperationRequest(
      values({ type: 'DEPOSIT', grossAmount: '5', quantity: '3', instrument }),
      'EUR',
    );
    expect(result.request).not.toHaveProperty('quantity');
    expect(result.request).not.toHaveProperty('instrumentId');
  });

  it('reports every missing required field', () => {
    const result = buildOperationRequest(values({ type: 'BUY' }), 'EUR');
    expect(Object.keys(result.errors ?? {}).sort()).toEqual(
      ['grossAmount', 'instrument', 'quantity', 'unitPrice'].sort(),
    );
  });

  it.each(['1e3', '+10', '-5', '1.', '.5', 'abc', '0'])('refuses %s as a quantity', (quantity) => {
    const result = buildOperationRequest(
      values({ type: 'SECURITY_TRANSFER_OUT', instrument, quantity }),
      'EUR',
    );
    expect(result.errors?.quantity).toBeDefined();
  });

  it('allows zero fees on a buy but not on a fee operation', () => {
    const buy = buildOperationRequest(
      values({
        type: 'BUY',
        instrument,
        quantity: '1',
        unitPrice: '1',
        grossAmount: '1',
        fees: '0',
      }),
      'EUR',
    );
    expect(buy.errors).toBeNull();
    const fee = buildOperationRequest(values({ type: 'FEE', fees: '0' }), 'EUR');
    expect(fee.errors?.fees).toBeDefined();
  });

  it('requires a split ratio with two different positive terms', () => {
    const same = buildOperationRequest(
      values({ type: 'SPLIT', instrument, ratioNumerator: '2', ratioDenominator: '2' }),
      'EUR',
    );
    expect(same.errors?.ratio).toBeDefined();
    const ok = buildOperationRequest(
      values({ type: 'SPLIT', instrument, ratioNumerator: '2', ratioDenominator: '1' }),
      'EUR',
    );
    expect(ok.request?.ratio).toEqual({ numerator: '2', denominator: '1' });
  });

  it('keeps the cost allocation ratio strictly between 0 and 1', () => {
    const base = {
      type: 'SPIN_OFF' as const,
      instrument,
      counterInstrument: { id: 'ins-2', name: 'Fille' },
      counterQuantity: '5',
    };
    expect(
      buildOperationRequest(values({ ...base, costAllocationRatio: '1' }), 'EUR').errors
        ?.costAllocationRatio,
    ).toBeDefined();
    expect(
      buildOperationRequest(values({ ...base, costAllocationRatio: '0.25' }), 'EUR').errors,
    ).toBeNull();
  });

  it('refuses a settlement date before the trade date', () => {
    const result = buildOperationRequest(
      values({ type: 'DEPOSIT', grossAmount: '5', settlementDate: '2026-03-09' }),
      'EUR',
    );
    expect(result.errors?.settlementDate).toBeDefined();
  });

  it('refuses control characters in the reference', () => {
    const result = buildOperationRequest(
      values({ type: 'DEPOSIT', grossAmount: '5', reference: 'a\u0007b' }),
      'EUR',
    );
    expect(result.errors?.reference).toBeDefined();
  });

  it('sends the linked transfer leg id', () => {
    const result = buildOperationRequest(
      values({ type: 'DEPOSIT', grossAmount: '5', linkedTransactionId: 'tx-1' }),
      'EUR',
    );
    expect(result.request?.linkedTransactionId).toBe('tx-1');
  });
});
