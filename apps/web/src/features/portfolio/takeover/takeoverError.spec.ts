import { describe, expect, it } from 'vitest';
import { takeoverRequestError, takeoverSaveMessage } from './takeoverError';

const failed = (status: number, error: object) => ({
  response: new Response('{}', { status }),
  error,
});

describe('takeoverRequestError', () => {
  it('keeps the per-line errors of a 422', () => {
    const error = takeoverRequestError(
      failed(422, {
        code: 'invalid_portfolio_takeover',
        errors: [{ line: 1, field: 'price', code: 'not_positive' }],
      }),
    );
    expect(error.status).toBe(422);
    expect(error.errors).toEqual([{ line: 1, field: 'price', code: 'not_positive' }]);
  });
});

describe('takeoverSaveMessage', () => {
  it('has no general message when the refusal is shown on the fields', () => {
    const error = takeoverRequestError(
      failed(422, {
        code: 'invalid_portfolio_takeover',
        errors: [{ line: 1, field: 'price', code: 'x' }],
      }),
    );
    expect(takeoverSaveMessage(error)).toBeUndefined();
  });

  it('explains why a non-empty account refuses', () => {
    const error = takeoverRequestError(
      failed(409, { code: 'portfolio_takeover_not_empty', blockingReason: 'SECURITY_OPERATION' }),
    );
    expect(takeoverSaveMessage(error)).toBe(
      'Ce compte contient déjà des opérations sur titres : la reprise n’est plus possible.',
    );
  });

  it('names a closed month, an existing takeover and a lost connection', () => {
    expect(
      takeoverSaveMessage(takeoverRequestError(failed(409, { type: '/problems/period-closed' }))),
    ).toBe('Le mois de reprise est clôturé. Rouvrez-le avant d’enregistrer la reprise.');
    expect(
      takeoverSaveMessage(takeoverRequestError(failed(409, { code: 'portfolio_takeover_exists' }))),
    ).toBe('Les positions de ce compte ont déjà été reprises.');
    expect(takeoverSaveMessage(takeoverRequestError({}))).toBe(
      'La connexion a échoué. Vérifiez votre connexion puis réessayez.',
    );
  });

  it('keeps a generic refusal readable', () => {
    expect(takeoverSaveMessage(takeoverRequestError(failed(500, {})))).toBe(
      'L’enregistrement a été refusé. Réessayez.',
    );
  });
});
