import type { Problem } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import {
  CategorizationRuleRequestError,
  categorizationRuleErrorKind,
  categorizationRuleRequestError,
} from './categorizationRuleError';

function problem(type: string, status = 422): Problem {
  return { detail: 'Rejected.', status, title: 'Rejected', type };
}

describe('categorizationRuleRequestError', () => {
  it('maps the execution limit problem type to a dedicated kind', () => {
    const error = categorizationRuleRequestError({
      error: problem('/problems/rules.execution_limit'),
      response: new Response('', { status: 422 }),
    });

    expect(error.kind).toBe('executionLimit');
  });

  it('still maps a generic 422 problem to invalid', () => {
    const error = new CategorizationRuleRequestError(422, problem('/problems/validation'));

    expect(error.kind).toBe('invalid');
  });

  it('maps a preview_stale problem to stale even at 422', () => {
    const error = new CategorizationRuleRequestError(422, problem('/problems/rules.preview_stale'));

    expect(error.kind).toBe('stale');
  });

  it('maps 401 and 403 to unauthorized regardless of problem type', () => {
    expect(new CategorizationRuleRequestError(401).kind).toBe('unauthorized');
    expect(new CategorizationRuleRequestError(403).kind).toBe('unauthorized');
  });

  it('maps every other status to network', () => {
    expect(new CategorizationRuleRequestError(500).kind).toBe('network');
  });
});

describe('categorizationRuleErrorKind', () => {
  it('returns the error kind for a CategorizationRuleRequestError', () => {
    const error = new CategorizationRuleRequestError(
      422,
      problem('/problems/rules.execution_limit'),
    );

    expect(categorizationRuleErrorKind(error, true)).toBe('executionLimit');
  });

  it('falls back to network for an unrecognized error when isError is true', () => {
    expect(categorizationRuleErrorKind(new Error('boom'), true)).toBe('network');
  });

  it('returns null when there is no error', () => {
    expect(categorizationRuleErrorKind(undefined, false)).toBeNull();
  });
});
