import { describe, expect, it } from 'vitest';
import { transactionErrorDay, transactionRequestError } from './transactionError';

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

  it('keeps the account input and the first negative day of an insufficient balance', () => {
    const error = transactionRequestError({
      error: {
        type: '/problems/account-balance-insufficient',
        status: 422,
        title: 't',
        detail: 'd',
        field: 'sourceAmount',
        firstNegativeOn: '2026-11-05',
      },
      response: { status: 422 } as Response,
    });

    expect(error.kind).toBe('insufficientBalance');
    expect(error.field).toBe('sourceAmount');
    expect(transactionErrorDay(error)).toBe('2026-11-05');
  });
});
