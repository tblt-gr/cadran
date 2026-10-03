import { describe, expect, it } from 'vitest';
import { operationRequestError, operationSaveMessage } from './operationError';

describe('operationSaveMessage', () => {
  it('uses the problem instrument identifier only to resolve a readable name', () => {
    const error = operationRequestError({
      error: {
        code: 'position_would_go_negative',
        instrumentId: 'instrument-2',
        tradeDate: '2026-03-04',
      },
      response: new Response('{}', { status: 422 }),
    });

    expect(
      operationSaveMessage(error, [
        { id: 'instrument-1', name: 'ETF Monde' },
        { id: 'instrument-2', name: 'Action Exemple' },
      ]),
    ).toBe('Cette opération rendrait la position Action Exemple négative le 2026-03-04.');
  });

  it('does not expose an unresolved instrument identifier', () => {
    const error = operationRequestError({
      error: {
        code: 'position_would_go_negative',
        instrumentId: 'private-instrument-id',
        tradeDate: '2026-03-04',
      },
      response: new Response('{}', { status: 422 }),
    });

    const message = operationSaveMessage(error);

    expect(message).toContain('position concernée');
    expect(message).not.toContain('private-instrument-id');
  });
});
