import { useEffect, useRef } from 'react';
import type { ArrivalAction } from './accountMenuItems';

/**
 * Runs `run` once when the page is opened with `?action=<action>` (the accounts list opens the
 * transaction, transfer, operation and valuation modals this way), then removes the parameter so a refresh or a
 * back navigation does not reopen it. `ready` waits for the data the action depends on.
 */
export function useArrivalAction(action: ArrivalAction, ready: boolean, run: () => void) {
  const latest = useRef(run);
  useEffect(() => {
    latest.current = run;
  });

  useEffect(() => {
    if (!ready) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('action') !== action) return;
    url.searchParams.delete('action');
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
    latest.current();
  }, [action, ready]);
}
