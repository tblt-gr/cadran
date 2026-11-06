import { describe, expect, it } from 'vitest';
import {
  MAX_TAKEOVER_LINES,
  buildTakeoverRequest,
  emptyLine,
  mapTakeoverErrors,
  type TakeoverFormValues,
} from './takeoverRequest';

const instrument = { id: 'i-1', name: 'World ETF' };
const values = (patch: Partial<TakeoverFormValues> = {}): TakeoverFormValues => ({
  takenOverOn: '2026-10-31',
  cash: '',
  lines: [
    {
      ...emptyLine('2026-10-31'),
      instrument,
      quantity: '5',
      costBasis: '480,00',
      price: '101.5000',
    },
  ],
  ...patch,
});

describe('buildTakeoverRequest', () => {
  it('builds canonical strings, a comma accepted, blank optionals as null', () => {
    const { request, errors } = buildTakeoverRequest(values({ cash: '120,5' }));
    expect(errors).toEqual({ lines: {} });
    expect(request).toEqual({
      takenOverOn: '2026-10-31',
      cash: '120.5',
      lines: [
        {
          instrumentId: 'i-1',
          quantity: '5',
          costBasis: '480.00',
          price: '101.5000',
          priceDate: null,
        },
      ],
    });
  });

  it('keeps a price date that differs from the takeover date', () => {
    const base = values();
    const { request } = buildTakeoverRequest({
      ...base,
      lines: [{ ...base.lines[0]!, priceDate: '2026-10-30' }],
    });
    expect(request?.lines[0]?.priceDate).toBe('2026-10-30');
  });

  it('refuses an incomplete line and names the field', () => {
    const base = values();
    const { request, errors } = buildTakeoverRequest({
      ...base,
      lines: [{ ...base.lines[0]!, instrument: null, quantity: '', price: '0' }],
    });
    expect(request).toBeNull();
    expect(errors.lines[0]).toEqual({
      instrument: 'Choisissez un instrument.',
      quantity: 'Saisissez une quantité strictement positive.',
      price: 'Saisissez un cours strictement positif.',
    });
  });

  it('refuses a missing date and a malformed cash', () => {
    const { request, errors } = buildTakeoverRequest(values({ takenOverOn: '', cash: 'abc' }));
    expect(request).toBeNull();
    expect(errors.date).toBe('Saisissez la date de reprise.');
    expect(errors.cash).toBe('Saisissez un montant valide.');
  });
});

describe('mapTakeoverErrors', () => {
  it('maps 1-based server lines to 0-based rows and request-level fields', () => {
    const mapped = mapTakeoverErrors([
      { line: 2, field: 'instrumentId', code: 'instrument_currency_mismatch' },
      { line: 1, field: 'price', code: 'price_precision_exceeded' },
      { line: null, field: 'takenOverOn', code: 'before_account_opening' },
      { line: null, field: 'cash', code: 'negative' },
      { line: null, field: 'lines', code: 'lines_count' },
    ]);
    expect(mapped.lines[1]?.instrument).toBe(
      'La devise de l’instrument diffère de celle du compte.',
    );
    expect(mapped.lines[0]?.price).toBe('Le cours dépasse la précision de l’instrument.');
    expect(mapped.date).toBe('La date précède l’ouverture du compte.');
    expect(mapped.cash).toBe('Les espèces ne peuvent pas être négatives.');
    expect(mapped.form).toBe('Saisissez entre 1 et 200 lignes.');
  });

  it('falls back to a generic text for an unknown code', () => {
    const mapped = mapTakeoverErrors([{ line: 1, field: 'quantity', code: 'something_new' }]);
    expect(mapped.lines[0]?.quantity).toBe('Cette valeur est refusée.');
  });
});

it('caps a takeover at 200 lines, as the API does', () => {
  expect(MAX_TAKEOVER_LINES).toBe(200);
});
