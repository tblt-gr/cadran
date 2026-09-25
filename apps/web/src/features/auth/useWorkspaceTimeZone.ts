import { useSession } from './useSession';

/**
 * The workspace's own IANA timezone, read from the session payload the SPA
 * already loads (`session.data.workspace.timeZone`). Every feature that needs
 * to resolve "today" — the budget route, a transaction's or transfer's
 * default booking date — reads it through this hook instead of assuming a
 * fixed zone or duplicating the fallback.
 *
 * `UTC` is a defensive fallback only, for the instant before the session
 * query has resolved; a page mounted after authentication (every page in
 * this app) reads a cache hit here, not a real pending state.
 */
export function useWorkspaceTimeZone(): string {
  const session = useSession();

  return session.data?.workspace?.timeZone ?? 'UTC';
}
