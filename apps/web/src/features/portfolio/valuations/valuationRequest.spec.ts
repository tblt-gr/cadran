import { describe, expect, it } from 'vitest';
import { draftFixture } from './valuationFixtures';
import { buildValuationRequest, valuesFromDraft } from './valuationRequest';

describe('valuesFromDraft', () => {
  it('prefills each line with its last price and date and preselects the suggested detail', () => {
    const values = valuesFromDraft(draftFixture({ suggestedDetail: 'POSITIONS' }));
    expect(values.detail).toBe('POSITIONS');
    expect(values.rows.map((row) => [row.instrumentId, row.price, row.priceDate])).toEqual([
      ['ins-ind', '52.30', '2026-03-03'],
      ['ins-etf', '231.20', '2026-03-03'],
    ]);
  });

  it('starts a line without a known price as unknown-free and empty', () => {
    const draft = draftFixture();
    draft.positions[0] = {
      ...draft.positions[0]!,
      lastPrice: null,
      lastPriceDate: null,
      lastPriceOrigin: null,
    };
    const row = valuesFromDraft(draft).rows[0]!;
    expect(row).toMatchObject({ price: '', priceDate: '', unknown: false });
  });
});

describe('buildValuationRequest', () => {
  const base = valuesFromDraft(draftFixture());

  it('builds a TOTAL body with decimals kept as strings and no prices', () => {
    const built = buildValuationRequest(
      { ...base, detail: 'TOTAL', totalValue: '3664,70', observedCash: '1193.28' },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(built.errors).toBeNull();
    expect(built.request).toEqual({
      valuedOn: '2026-03-10',
      detail: 'TOTAL',
      totalValue: { value: '3664.70', assetCode: 'EUR' },
      observedCash: { value: '1193.28', assetCode: 'EUR' },
    });
  });

  it('requires the total of a TOTAL valuation and refuses a malformed one', () => {
    const missing = buildValuationRequest({ ...base, detail: 'TOTAL' }, '2026-03-10', 'EUR', null);
    expect(missing.errors?.['/totalValue']).toBe('Ce champ est obligatoire.');
    const malformed = buildValuationRequest(
      { ...base, detail: 'TOTAL', totalValue: '1e3' },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(malformed.errors?.['/totalValue']).toMatch(/nombre décimal/);
  });

  it('builds a POSITIONS body with one entry per line, null for an unknown price, never totalValue', () => {
    const rows = base.rows.map((row, index) =>
      index === 0
        ? { ...row, unknown: true }
        : { ...row, price: '231.4521', priceDate: '2026-03-10' },
    );
    const built = buildValuationRequest(
      { ...base, detail: 'POSITIONS', totalValue: '99', rows, observedTotal: '3664.70' },
      '2026-03-10',
      'EUR',
      2,
    );
    expect(built.errors).toBeNull();
    expect(built.request).toEqual({
      valuedOn: '2026-03-10',
      detail: 'POSITIONS',
      prices: [
        { instrumentId: 'ins-ind', price: null, priceDate: null },
        { instrumentId: 'ins-etf', price: '231.4521', priceDate: '2026-03-10' },
      ],
      observedTotal: { value: '3664.70', assetCode: 'EUR' },
      version: 2,
    });
  });

  it('flags a missing, zero or future-dated price on its own row', () => {
    const rows = [
      { ...base.rows[0]!, price: '', priceDate: '2026-03-10' },
      { ...base.rows[1]!, price: '0', priceDate: '2026-03-10' },
    ];
    const built = buildValuationRequest(
      { ...base, detail: 'POSITIONS', rows },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(built.errors?.['/prices/0/price']).toBe('Saisissez un prix ou cochez Prix inconnu.');
    expect(built.errors?.['/prices/1/price']).toBe('Le prix doit être strictement positif.');
    const future = buildValuationRequest(
      {
        ...base,
        detail: 'POSITIONS',
        rows: [{ ...base.rows[0]!, priceDate: '2026-03-11' }, base.rows[1]!],
      },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(future.errors?.['/prices/0/priceDate']).toMatch(/au plus la date de valorisation/);
  });

  it('keeps a trimmed note and refuses one over 200 characters', () => {
    const ok = buildValuationRequest(
      { ...base, detail: 'TOTAL', totalValue: '1', note: '  Relevé mars ' },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(ok.request?.note).toBe('Relevé mars');
    const long = buildValuationRequest(
      { ...base, detail: 'TOTAL', totalValue: '1', note: 'x'.repeat(201) },
      '2026-03-10',
      'EUR',
      null,
    );
    expect(long.errors?.['/note']).toMatch(/200 caractères/);
  });
});
