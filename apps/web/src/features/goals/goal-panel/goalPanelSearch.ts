import { useCallback, useEffect, useState } from 'react';

const PANEL_PARAM = 'panel';
const PANEL_VALUE = 'goals';
const GOAL_PARAM = 'goal';
const PERIOD_PARAM = 'period';
/** Marks the history entry this module pushed, so closing can step back instead of stacking. */
const PUSHED = 'goalPanelPushed';

export function readGoalPanelOpen(search: string): boolean {
  return new URLSearchParams(search).get(PANEL_PARAM) === PANEL_VALUE;
}

/** The goal a report cell pointed at and the period of that cell (`YYYY-MM` or `YYYY`). */
export interface GoalPanelTarget {
  goalId: string;
  period: string;
}

const PERIOD_PATTERN = /^\d{4}(-\d{2})?$/;

/** The goal to focus and the period to show, read from the URL; null when the panel opens plain. */
export function readGoalPanelTarget(search: string): GoalPanelTarget | null {
  const params = new URLSearchParams(search);
  const goalId = params.get(GOAL_PARAM);
  const period = params.get(PERIOD_PARAM);
  return goalId !== null && period !== null && PERIOD_PATTERN.test(period)
    ? { goalId, period }
    : null;
}

function withPanel(open: boolean, target?: GoalPanelTarget): string {
  const url = new URL(window.location.href);
  if (open) url.searchParams.set(PANEL_PARAM, PANEL_VALUE);
  else url.searchParams.delete(PANEL_PARAM);
  url.searchParams.delete(GOAL_PARAM);
  url.searchParams.delete(PERIOD_PARAM);
  if (open && target) {
    url.searchParams.set(GOAL_PARAM, target.goalId);
    url.searchParams.set(PERIOD_PARAM, target.period);
  }
  return `${url.pathname}${url.search}${url.hash}`;
}

/** Opens the goals panel from a figure of the page: on the period of the figure, on its goal. */
export function pushGoalPanel(target?: GoalPanelTarget) {
  window.history.pushState({ [PUSHED]: true }, '', withPanel(true, target));
  window.dispatchEvent(new PopStateEvent('popstate'));
}

/**
 * Open state of the goals panel, kept in the URL (`?panel=goals`): opening pushes a history entry
 * so Back closes it, and a reload on that URL reopens it.
 */
export function useGoalPanelOpen() {
  const [open, setOpen] = useState(() => readGoalPanelOpen(window.location.search));
  const [target, setTarget] = useState(() => readGoalPanelTarget(window.location.search));

  useEffect(() => {
    const sync = () => {
      setOpen(readGoalPanelOpen(window.location.search));
      setTarget(readGoalPanelTarget(window.location.search));
    };
    window.addEventListener('popstate', sync);
    return () => window.removeEventListener('popstate', sync);
  }, []);

  const openPanel = useCallback(() => {
    window.history.pushState({ [PUSHED]: true }, '', withPanel(true));
    setTarget(null);
    setOpen(true);
  }, []);
  const closePanel = useCallback(() => {
    if ((window.history.state as Record<string, unknown> | null)?.[PUSHED] === true) {
      // popstate then closes the panel.
      window.history.back();
      return;
    }
    window.history.replaceState({}, '', withPanel(false));
    setTarget(null);
    setOpen(false);
  }, []);

  return { open, openPanel, closePanel, target };
}
