import type { Account } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { initialValues } from './recurrenceFormValues';

const account = (id: string, isPrimary: boolean, status = 'ACTIVE') =>
  ({ id, isPrimary, status }) as Account;

describe('initialValues account default', () => {
  it('selects the primary account', () => {
    const accounts = [account('a', false), account('b', true)];
    expect(initialValues(accounts).accountId).toBe('b');
  });

  it('falls back to the first account when no active primary exists', () => {
    const accounts = [account('a', false), account('b', true, 'ARCHIVED')];
    expect(initialValues(accounts).accountId).toBe('a');
  });
});
