import { afterEach, describe, expect, it } from 'vitest';
import { authApiOptions } from '@/features/auth/apiOptions';

function clearCookies() {
  for (const pair of document.cookie.split(';')) {
    const name = pair.trim().split('=')[0];
    if (name) {
      document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
    }
  }
}

describe('authApiOptions', () => {
  afterEach(clearCookies);

  it('sends an empty CSRF header when no token cookie is present', () => {
    clearCookies();

    expect(authApiOptions().headers['X-CSRF-TOKEN']).toBe('');
  });

  it('echoes the csrf_token cookie into the X-CSRF-TOKEN header', () => {
    document.cookie = 'csrf_token=abc.123.def';

    expect(authApiOptions().headers['X-CSRF-TOKEN']).toBe('abc.123.def');
  });

  it('keeps the same-origin credentials mode', () => {
    expect(authApiOptions().credentials).toBe('include');
  });
});
