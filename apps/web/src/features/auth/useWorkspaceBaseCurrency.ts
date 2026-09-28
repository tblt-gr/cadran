import { useSession } from './useSession';

/**
 * The workspace's own base currency, read from the session payload the SPA
 * already loads (`session.data.workspace.baseCurrency`). Goals are always
 * denominated in this currency; the form sends it so the API can reject a
 * mismatch instead of assuming EUR.
 *
 * `EUR` is a defensive fallback only, for the instant before the session
 * query has resolved.
 */
export function useWorkspaceBaseCurrency(): string {
  const session = useSession();

  return session.data?.workspace?.baseCurrency ?? 'EUR';
}
