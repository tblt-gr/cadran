import { describe, expect, it } from 'vitest';
import { buildOperationRequest, emptyValues, type OperationFormValues } from './operationRequest';

function values(overrides: Partial<OperationFormValues>): OperationFormValues {
  return { ...emptyValues('2026-03-10'), ...overrides };
}
const instrument = { id: 'ins-1', name: 'ETF Monde' };

describe('buildOperationRequest', () => {
  it('keeps both switch directions and leaves gross and balance to the server', () => {
    const result = buildOperationRequest(
      values({
        type: 'SWITCH',
        grossAmount: '999',
        fees: '10',
        legs: [
          {
            key: 'out',
            direction: 'OUT',
            instrument: { id: 'euro', name: 'Fonds euros', instrumentType: 'EURO_FUND' },
            quantity: '',
            unitPrice: '',
            amount: '2000',
          },
          {
            key: 'in',
            direction: 'IN',
            instrument,
            quantity: '20',
            unitPrice: '99.50',
            amount: '1990.01',
          },
        ],
      }),
      'EUR',
    );
    expect(result.errors).toBeNull();
    expect(result.request).not.toHaveProperty('grossAmount');
    expect(result.request?.legs?.map((leg) => leg.direction)).toEqual(['OUT', 'IN']);
    expect(result.request?.legs?.[1]?.amount.value).toBe('1990.01');
  });

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

describe('insurance requests', () => {
  it('refuses a 21st support before submitting', () => {
    const legs = Array.from({ length: 21 }, (_, index) => ({
      key: String(index),
      instrument: { ...instrument, id: String(index) },
      quantity: '1',
      unitPrice: '1',
      amount: '1',
    }));
    expect(
      buildOperationRequest(values({ type: 'PREMIUM', grossAmount: '21', legs }), 'EUR').errors
        ?.legs,
    ).toBeDefined();
  });
  it('keeps the reference premium exact and sends only amount for a euro fund', () => {
    const result = buildOperationRequest(
      values({
        type: 'PREMIUM',
        grossAmount: '10000.00',
        fees: '100.00',
        legs: [
          {
            key: 'euro',
            instrument: { id: 'euro', name: 'Fonds euros', instrumentType: 'EURO_FUND' },
            quantity: '',
            unitPrice: '',
            amount: '6000.00',
          },
          { key: 'units', instrument, quantity: '40', unitPrice: '97.50', amount: '3900.00' },
        ],
      }),
      'EUR',
    );
    expect(result.errors).toBeNull();
    expect(result.request?.legs).toEqual([
      { direction: 'IN', instrumentId: 'euro', amount: { value: '6000.00', assetCode: 'EUR' } },
      {
        direction: 'IN',
        instrumentId: 'ins-1',
        quantity: '40',
        unitPrice: '97.50',
        amount: { value: '3900.00', assetCode: 'EUR' },
      },
    ]);
  });
  it('sends observed withholdings, never derived taxes or net paid', () => {
    const result = buildOperationRequest(
      values({
        type: 'PARTIAL_SURRENDER',
        grossAmount: '1050.00',
        withheldIncomeTax: '7.50',
        withheldSocialContributions: '8.60',
        taxes: '16.10',
      }),
      'EUR',
    );
    expect(result.request?.withheldIncomeTax?.value).toBe('7.50');
    expect(result.request?.withheldSocialContributions?.value).toBe('8.60');
    expect(result.request).not.toHaveProperty('taxes');
    expect(result.request).not.toHaveProperty('netPaidAmount');
  });
  it('preserves large quantities and reports zero or malformed leg values by row', () => {
    const result = buildOperationRequest(
      values({
        type: 'FULL_SURRENDER',
        grossAmount: '1',
        legs: [
          {
            key: 'a',
            instrument,
            quantity: '999999999999999999999.123456789',
            unitPrice: '0',
            amount: 'abc',
          },
        ],
      }),
      'EUR',
    );
    expect(result.errors?.['legs.1.unitPrice']).toBeDefined();
    expect(result.errors?.['legs.1.amount']).toBeDefined();
    expect(result.errors?.['legs.1.quantity']).toBeUndefined();
  });
});
