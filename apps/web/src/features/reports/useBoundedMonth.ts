import { useEffect, useRef, useState } from 'react';
import { useMonthlyBounds } from '@/features/reports/useMonthlyBounds';
import { isBeforeFirstDataMonth } from '@/lib/monthBounds';

interface BoundedMonthOptions {
  /** Syntactically valid, non-future month being shown; null when the route is not one. */
  month: string | null;
  /** Internal route for the earliest valid month; only generated hrefs reach history. */
  hrefFor: (firstDataMonth: string) => string;
  /** Lets the owner of local month state follow the correction. */
  onCorrect?: (firstDataMonth: string) => void;
}

/**
 * Corrects a month before the workspace history: replaces the history entry (Back never loops),
 * lets the navigation layer re-read the URL and reports it once for the live region. Nothing
 * moves while the bounds load, failed or are empty.
 */
export function useBoundedMonth({ month, hrefFor, onCorrect }: BoundedMonthOptions) {
  const bounds = useMonthlyBounds();
  const [correction, setCorrection] = useState<{ to: string; count: number } | null>(null);
  const latest = useRef({ hrefFor, onCorrect });
  latest.current = { hrefFor, onCorrect };
  const firstDataMonth = bounds.data?.firstDataMonth ?? null;

  useEffect(() => {
    if (month === null || !isBeforeFirstDataMonth(month, firstDataMonth) || firstDataMonth === null)
      return;
    window.history.replaceState({}, '', latest.current.hrefFor(firstDataMonth));
    latest.current.onCorrect?.(firstDataMonth);
    setCorrection((previous) => ({ to: firstDataMonth, count: (previous?.count ?? 0) + 1 }));
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, [month, firstDataMonth]);

  // The notice belongs to the corrected route only: leaving it for another valid month clears it,
  // and a later correction mounts a fresh live region (new key) so it is announced again.
  useEffect(() => {
    if (
      correction !== null &&
      month !== null &&
      month !== correction.to &&
      !isBeforeFirstDataMonth(month, firstDataMonth)
    ) {
      setCorrection(null);
    }
  }, [month, firstDataMonth, correction]);

  return {
    bounds,
    firstDataMonth,
    status: bounds.data
      ? ('ready' as const)
      : bounds.isError
        ? ('error' as const)
        : ('loading' as const),
    correctedTo: correction?.to ?? null,
    correctionKey: correction?.count ?? 0,
    pendingCorrection:
      month !== null && bounds.data !== undefined && isBeforeFirstDataMonth(month, firstDataMonth),
  };
}
