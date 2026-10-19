import type { NetWorthHistory } from '@cadran/api-client';
import { useCallback, useEffect, useState } from 'react';

export type NetWorthPeriod = NonNullable<NetWorthHistory['period']>;
const urlValues: Record<NetWorthPeriod, string> = {
  ALL: 'tout',
  LAST_12_MONTHS: '12m',
  YTD: 'ytd',
};

function readPeriod(): NetWorthPeriod {
  const value = new URLSearchParams(window.location.search).get('periode');
  return value === 'tout' ? 'ALL' : value === 'ytd' ? 'YTD' : 'LAST_12_MONTHS';
}

/** The URL persists the chart selection while the server resolves its calendar. */
export function useNetWorthPeriod() {
  const [period, setPeriod] = useState(readPeriod);
  useEffect(() => {
    const onPopState = () => setPeriod(readPeriod());
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);
  const changePeriod = useCallback((next: NetWorthPeriod) => {
    const url = new URL(window.location.href);
    url.searchParams.set('periode', urlValues[next]);
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
    setPeriod(next);
  }, []);
  return { period, changePeriod };
}
