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

  it('classifies a portfolio-mode account refusal apart from a generic invalid request', () => {
    const error = transactionRequestError({
      error: {
        type: '/problems/portfolio-account-requires-operations',
        status: 422,
        title: 't',
        detail: 'd',
      },
      response: { status: 422 } as Response,
    });

    expect(error.kind).toBe('portfolioOperationsRequired');
  });
});
