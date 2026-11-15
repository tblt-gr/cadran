import { describe, expect, it } from 'vitest';

import { AccountRequestError } from './accountError';

describe('AccountRequestError', () => {
  it('ranks a feature problem type above the status', () => {
    expect(new AccountRequestError(409, '/problems/account-archived').kind).toBe('archived');
    expect(new AccountRequestError(409, '/problems/stale-account-order').kind).toBe('stale');
  });

  it('shows a refused session as unauthorized, never as a network failure', () => {
    expect(new AccountRequestError(401, undefined).kind).toBe('unauthorized');
    expect(new AccountRequestError(403, undefined).kind).toBe('unauthorized');
  });

  it('falls back on the status for conflicts, invalid requests and the rest', () => {
    expect(new AccountRequestError(409, undefined).kind).toBe('conflict');
    expect(new AccountRequestError(400, undefined).kind).toBe('invalid');
    expect(new AccountRequestError(500, undefined).kind).toBe('network');
  });
});
