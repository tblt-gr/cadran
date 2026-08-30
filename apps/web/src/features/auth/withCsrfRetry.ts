import { getSession } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';

const CSRF_PROBLEM_TYPE = '/problems/csrf-token';

type MutationResult = { response?: Response; error?: unknown };

function isStaleCsrf(result: MutationResult): boolean {
  if (result.response?.status !== 403) {
    return false;
  }

  const problem = result.error;

  return (
    typeof problem === 'object' &&
    problem !== null &&
    (problem as { type?: unknown }).type === CSRF_PROBLEM_TYPE
  );
}

/**
 * Runs an auth mutation. If the server refuses it for a stale or missing CSRF
 * token, a session probe replants the `csrf_token` cookie and the mutation is
 * retried once — so a tab left open past the token's lifetime still logs out,
 * signs in, or sets its password instead of failing silently.
 */
export async function withCsrfRetry<T extends MutationResult>(call: () => Promise<T>): Promise<T> {
  const first = await call();
  if (!isStaleCsrf(first)) {
    return first;
  }

  await getSession({ ...authApiOptions(), throwOnError: false });

  return call();
}
