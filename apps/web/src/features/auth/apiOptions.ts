const CSRF_COOKIE = 'csrf_token';

/**
 * Reads the double-submit CSRF token the API plants as a readable cookie. The
 * backend rejects any mutation whose `X-CSRF-TOKEN` header does not match it.
 */
function csrfToken(): string {
  for (const pair of document.cookie.split(';')) {
    const [name, ...rest] = pair.trim().split('=');
    if (name === CSRF_COOKIE) {
      return decodeURIComponent(rest.join('='));
    }
  }

  return '';
}

/**
 * Shared request options for the auth endpoints. `credentials: 'include'` keeps
 * the same-origin session cookie attached to every call, including the initial
 * session probe before a cookie exists. The `X-CSRF-TOKEN` header echoes the
 * double-submit cookie; it is harmless on the safe GET probe and required on
 * every mutation.
 */
export function authApiOptions() {
  return {
    baseUrl: window.location.origin,
    credentials: 'include' as const,
    headers: { 'X-CSRF-TOKEN': csrfToken() },
  };
}
