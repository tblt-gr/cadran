import { useCallback, useEffect, useState } from 'react';

const PANEL_PARAM = 'panel';
const PANEL_VALUE = 'goals';
/** Marks the history entry this module pushed, so closing can step back instead of stacking. */
const PUSHED = 'goalPanelPushed';

export function readGoalPanelOpen(search: string): boolean {
  return new URLSearchParams(search).get(PANEL_PARAM) === PANEL_VALUE;
}

function withPanel(open: boolean): string {
  const url = new URL(window.location.href);
  if (open) url.searchParams.set(PANEL_PARAM, PANEL_VALUE);
  else url.searchParams.delete(PANEL_PARAM);
  return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * Open state of the goals panel, kept in the URL (`?panel=goals`): opening pushes a history entry
 * so Back closes it, and a reload on that URL reopens it.
 */
export function useGoalPanelOpen() {
  const [open, setOpen] = useState(() => readGoalPanelOpen(window.location.search));

  useEffect(() => {
    const sync = () => setOpen(readGoalPanelOpen(window.location.search));
    window.addEventListener('popstate', sync);
    return () => window.removeEventListener('popstate', sync);
  }, []);

  const openPanel = useCallback(() => {
    window.history.pushState({ [PUSHED]: true }, '', withPanel(true));
    setOpen(true);
  }, []);
  const closePanel = useCallback(() => {
    if ((window.history.state as Record<string, unknown> | null)?.[PUSHED] === true) {
      // popstate then closes the panel.
      window.history.back();
      return;
    }
    window.history.replaceState({}, '', withPanel(false));
    setOpen(false);
  }, []);

  return { open, openPanel, closePanel };
}
