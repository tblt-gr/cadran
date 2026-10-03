import { describe, expect, it } from 'vitest';
import {
  isStaleValuationError,
  valuationFieldErrors,
  valuationRequestError,
  valuationSaveMessage,
} from './valuationError';

const refused = (status: number, body: object) =>
  valuationRequestError({
    error: body,
    response: status === 0 ? undefined : new Response('{}', { status }),
  });

describe('valuationFieldErrors', () => {
  it('maps a row-level refusal to its JSON pointer', () => {
    const error = refused(422, { code: 'price_precision_exceeded', field: '/prices/1/price' });
    expect(valuationFieldErrors(error)).toEqual({
      '/prices/1/price': 'Le prix dépasse la précision de l’instrument.',
    });
  });

  it('returns nothing for a refusal that is not field level', () => {
    expect(valuationFieldErrors(refused(409, { code: 'valuation_exists' }))).toEqual({});
    expect(valuationFieldErrors(new Error('x'))).toEqual({});
  });
});

describe('valuationSaveMessage', () => {
  it('explains each general refusal', () => {
    expect(valuationSaveMessage(refused(409, { code: 'valuation_exists' }))).toMatch(/existe déjà/);
    expect(valuationSaveMessage(refused(409, { code: 'stale_valuation_version' }))).toMatch(
      /modifiée ailleurs/,
    );
    expect(valuationSaveMessage(refused(409, { type: '/problems/period-closed' }))).toMatch(
      /clôturé/,
    );
    expect(valuationSaveMessage(refused(422, { code: 'valuation_price_entry_missing' }))).toMatch(
      /chaque position/,
    );
    expect(valuationSaveMessage(refused(0, {}))).toMatch(/connexion/);
  });

  it('leaves a field-level refusal to its field', () => {
    expect(
      valuationSaveMessage(
        refused(422, { code: 'invalid_valuation_price', field: '/prices/0/price' }),
      ),
    ).toBeUndefined();
  });

  it('flags a stale version for a list refresh', () => {
    expect(isStaleValuationError(refused(409, { code: 'stale_valuation_version' }))).toBe(true);
    expect(isStaleValuationError(refused(409, { code: 'valuation_exists' }))).toBe(false);
  });
});
