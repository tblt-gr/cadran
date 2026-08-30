/**
 * Shared request options for the auth endpoints. `credentials: 'include'` keeps
 * the same-origin session cookie attached to every call, including the initial
 * session probe before a cookie exists.
 */
export function authApiOptions() {
  return { baseUrl: window.location.origin, credentials: 'include' as const };
}
