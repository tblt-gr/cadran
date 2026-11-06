import { useEffect, useRef } from 'react';
import type { ArrivalAction } from './accountMenuItems';

/** Parameters read from the arrival URL before they are removed. */
export interface ArrivalParams {
  /** The `asOf` day when it is a real `YYYY-MM-DD` date, otherwise null. */
  asOf: string | null;
}

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

function validDay(value: string | null): string | null {
  if (value === null || !ISO_DAY.test(value)) return null;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value ? null : value;
}

/**
 * Runs `run` once when the page is opened with `?action=<action>` (the accounts list opens the
 * transaction, transfer, operation and valuation modals this way, the closure modal the balance,
 * reconciliation and valuation ones), then removes the parameter so a refresh or a back
 * navigation does not reopen it. `consumesAsOf` also removes `asOf`, which such a link carries to
 * prefill the date. `ready` waits for the data the action depends on.
 */
export function useArrivalAction(
  action: ArrivalAction,
  ready: boolean,
  run: (params: ArrivalParams) => void,
  options: { consumesAsOf?: boolean } = {},
) {
  const latest = useRef(run);
  useEffect(() => {
    latest.current = run;
  });
  const consumesAsOf = options.consumesAsOf ?? false;

  useEffect(() => {
    if (!ready) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('action') !== action) return;
    const params = { asOf: consumesAsOf ? validDay(url.searchParams.get('asOf')) : null };
    url.searchParams.delete('action');
    if (consumesAsOf) url.searchParams.delete('asOf');
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
    latest.current(params);
  }, [action, consumesAsOf, ready]);
}
