import { afterEach, describe, expect, it, vi } from 'vitest';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';

const getSession = vi.hoisted(() => vi.fn());
vi.mock('@cadran/api-client', () => ({ getSession }));

function csrf403() {
  return { response: new Response(null, { status: 403 }), error: { type: '/problems/csrf-token' } };
}

describe('withCsrfRetry', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('returns a successful result without probing the session', async () => {
    const ok = { response: new Response(null, { status: 204 }) };
    const call = vi.fn().mockResolvedValue(ok);

    expect(await withCsrfRetry(call)).toBe(ok);
    expect(call).toHaveBeenCalledTimes(1);
    expect(getSession).not.toHaveBeenCalled();
  });

  it('replants the token and retries once on a CSRF 403', async () => {
    const ok = { response: new Response(null, { status: 204 }) };
    const call = vi.fn().mockResolvedValueOnce(csrf403()).mockResolvedValueOnce(ok);

    expect(await withCsrfRetry(call)).toBe(ok);
    expect(getSession).toHaveBeenCalledTimes(1);
    expect(call).toHaveBeenCalledTimes(2);
  });

  it('does not retry a 403 that is not a CSRF problem', async () => {
    const disabled = {
      response: new Response(null, { status: 403 }),
      error: { type: 'about:blank' },
    };
    const call = vi.fn().mockResolvedValue(disabled);

    expect(await withCsrfRetry(call)).toBe(disabled);
    expect(call).toHaveBeenCalledTimes(1);
    expect(getSession).not.toHaveBeenCalled();
  });
});
