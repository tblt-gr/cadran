import { describe, expect, it } from 'vitest';
import { transactionRequestError } from './transactionError';

describe('transactionRequestError', () => {
  it('classifies the stable closed-period problem apart from a plain conflict', () => {
    const error = transactionRequestError({
      error: { type: '/problems/period-closed', status: 409, title: 't', detail: 'd' },
      response: { status: 409 } as Response,
    });

    expect(error.kind).toBe('periodClosed');
  });
});
