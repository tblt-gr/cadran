import { describe, expect, it } from 'vitest';
import { goalErrorKind, goalErrorMismatch, goalRequestError, GoalRequestError } from './goalError';

describe('goalRequestError', () => {
  it.each([
    'invalid_emergency_fund_category',
    'emergency_fund_scope_too_large',
    'correction_span_exceeded',
  ])('classifies %s by its code', (code) => {
    const error = goalRequestError({
      response: new Response(null, { status: 422 }),
      error: { type: `/problems/${code}`, title: 'x', status: 422, detail: 'x', code },
    });
    expect(error.kind).toBe(code);
  });

  it('classifies a generic 422 as invalid when the problem carries no known code', () => {
    const error = goalRequestError({ response: new Response(null, { status: 422 }) });
    expect(error.kind).toBe('invalid');
  });

  it('classifies a schedule_sum_mismatch problem by its code, not its status', () => {
    const error = goalRequestError({
      response: new Response(null, { status: 422 }),
      error: {
        type: '/problems/schedule_sum_mismatch',
        title: 'Unprocessable',
        status: 422,
        detail: 'sum mismatch',
        code: 'schedule_sum_mismatch',
        expected: '2000.00',
        actual: '1999.99',
        difference: '0.01',
      },
    });
    expect(error.kind).toBe('schedule_sum_mismatch');
    expect(error.mismatch).toEqual({ expected: '2000.00', actual: '1999.99', difference: '0.01' });
  });

  it('classifies schedule_negative_part and schedule_precision_exceeded by code', () => {
    const negative = goalRequestError({
      response: new Response(null, { status: 422 }),
      error: { type: 't', title: 't', status: 422, detail: 'd', code: 'schedule_negative_part' },
    });
    expect(negative.kind).toBe('schedule_negative_part');

    const precision = goalRequestError({
      response: new Response(null, { status: 422 }),
      error: {
        type: 't',
        title: 't',
        status: 422,
        detail: 'd',
        code: 'schedule_precision_exceeded',
      },
    });
    expect(precision.kind).toBe('schedule_precision_exceeded');
  });

  it('carries no mismatch details for a non-sum-mismatch error', () => {
    const error = goalRequestError({ response: new Response(null, { status: 409 }) });
    expect(error.kind).toBe('conflict');
    expect(error.mismatch).toBeNull();
  });
});

describe('goalErrorKind / goalErrorMismatch', () => {
  it('reads kind and mismatch off a GoalRequestError instance', () => {
    const error = new GoalRequestError(422, {
      type: 't',
      title: 't',
      status: 422,
      detail: 'd',
      code: 'schedule_sum_mismatch',
      expected: '10',
      actual: '9',
      difference: '1',
    });
    expect(goalErrorKind(error, true)).toBe('schedule_sum_mismatch');
    expect(goalErrorMismatch(error)).toEqual({ expected: '10', actual: '9', difference: '1' });
  });

  it('falls back to network for a non-GoalRequestError failure', () => {
    expect(goalErrorKind(new Error('boom'), true)).toBe('network');
    expect(goalErrorMismatch(new Error('boom'))).toBeNull();
  });
});
