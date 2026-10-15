export const loginPath = '/connexion';

export function moveToLoginPath() {
  if (window.location.pathname === loginPath) return;

  const returnTo = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  window.history.replaceState({ returnTo }, '', loginPath);
}

export function leaveLoginPath() {
  if (window.location.pathname !== loginPath) return;

  const returnTo: unknown = window.history.state?.returnTo;
  let destination = '/';
  if (typeof returnTo === 'string' && returnTo.startsWith('/')) {
    try {
      const candidate = new URL(returnTo, window.location.origin);
      if (candidate.origin === window.location.origin) {
        destination = `${candidate.pathname}${candidate.search}${candidate.hash}`;
      }
    } catch {
      // An invalid browser history entry cannot block a successful sign-in.
    }
  }
  window.history.replaceState(null, '', destination);
}
