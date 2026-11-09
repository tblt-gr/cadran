import type { GoalSummary } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useEffect, useRef, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Drawer } from '@/components/ui/drawer/Drawer';
import { Toast } from '@/components/ui/toast/Toast';
import { GoalSelection } from '@/features/goals/bulk-selection/GoalSelection';
import { currentMonthIn } from '@/features/goals/goalFormFields';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { DeleteGoalDialog } from './DeleteGoalDialog';
import { GoalEditorModals } from './GoalEditorModals';
import { GoalPanelMonthly } from './GoalPanelMonthly';
import { GoalPanelYearly } from './GoalPanelYearly';
import { GoalPanelYears } from './GoalPanelYears';
import { longMonthYear } from './goalRecurrence';
import { StopGoalDialog } from './StopGoalDialog';
import { useGoalEditor } from './useGoalEditor';
import { useGoalPanelData, type GoalPanelScope } from './useGoalPanelData';
import styles from './GoalPanel.module.css';

interface Props {
  scope: GoalPanelScope;
  close: () => void;
  returnFocus: RefObject<HTMLElement | null>;
  /** The goal a report cell pointed at: its row takes the focus once the evaluations are in. */
  focusGoalId?: string | null;
}

/** `Novembre 2026`, `2026`, `2024 à 2026` for a run of years, `2024, 2026` for scattered ones. */
function scopePeriod(scope: GoalPanelScope, t: TFunction, locale: string): string {
  if (scope.kind === 'month') return longMonthYear(scope.month, locale);
  if (scope.kind === 'year') return String(scope.year);
  const years = scope.years;
  const first = years[0];
  const last = years[years.length - 1];
  if (first === undefined || last === undefined) return '';
  if (years.length === 1) return String(first);
  const contiguous = years.every((year, index) => year === first + index);
  return contiguous ? t('goals.panel.yearsRange', { from: first, to: last }) : years.join(', ');
}

function previousMonth(month: string): string {
  const [year, number] = month.split('-').map(Number) as [number, number];
  return number === 1 ? `${year - 1}-12` : `${year}-${String(number - 1).padStart(2, '0')}`;
}

/**
 * The goals of a month or a year, opened beside the budget: evaluations from the server, the
 * create, edit, history, stop and delete actions, and the emergency fund coverage.
 */
export function GoalPanel({ scope, close, returnFocus, focusGoalId = null }: Props) {
  const { t, i18n } = useTranslation();
  const data = useGoalPanelData(scope);
  const editor = useGoalEditor();
  const focused = useRef<string | null>(null);
  const createButton = useRef<HTMLButtonElement>(null);
  const ready = !data.pending && data.evaluations !== undefined;
  useEffect(() => {
    if (!ready || focusGoalId === null || focused.current === focusGoalId) return;
    const row = Array.from(document.querySelectorAll<HTMLElement>('[data-goal-id]')).find(
      (candidate) => candidate.dataset.goalId === focusGoalId,
    );
    if (row === undefined) return;
    focused.current = focusGoalId;
    // After the drawer's own first-focus frame, which would otherwise take the focus back.
    const frame = window.requestAnimationFrame(() => {
      row.scrollIntoView?.({ block: 'center' });
      row.focus();
    });
    return () => window.cancelAnimationFrame(frame);
  }, [ready, focusGoalId]);
  const workspaceMonth = currentMonthIn(useWorkspaceTimeZone());
  // `year` is the section a stop was opened from on the multi-year panel.
  const [stopTarget, setStopTarget] = useState<{ goal: GoalSummary; year: number | null } | null>(
    null,
  );
  const [deleteTarget, setDeleteTarget] = useState<GoalSummary | null>(null);
  // The dialogs act on the latest version of the goal: a refetch after a conflict replaces it.
  const latest = (target: GoalSummary) =>
    data.goals?.find((goal) => goal.id === target.id) ?? target;
  const monthly = scope.kind === 'month';
  const title = t('goals.panel.title', { period: scopePeriod(scope, t, i18n.language) });
  const displayedMonth = monthly ? scope.month : workspaceMonth;
  const displayedYear =
    scope.kind === 'month'
      ? Number(scope.month.slice(0, 4))
      : scope.kind === 'year'
        ? scope.year
        : (scope.years[scope.years.length - 1] ?? Number(workspaceMonth.slice(0, 4)));
  const handlers = {
    onEdit: editor.openEdit,
    onHistory: editor.openHistory,
    onStop: (goal: GoalSummary) => setStopTarget({ goal, year: null }),
    onDelete: setDeleteTarget,
  };
  // The default last period is the one before what the panel shows.
  const defaultLastPeriod = (target: { goal: GoalSummary; year: number | null }) =>
    target.goal.periodicity === 'MONTHLY'
      ? previousMonth(displayedMonth)
      : String((target.year ?? displayedYear) - 1);

  return (
    <>
      <Drawer
        close={close}
        footer={
          <button
            className="primary-action"
            ref={createButton}
            onClick={() =>
              editor.openCreate(
                monthly
                  ? { periodicity: 'MONTHLY', start: scope.month }
                  : { periodicity: 'YEARLY', start: String(displayedYear) },
              )
            }
            type="button"
          >
            {t('goals.add')}
          </button>
        }
        returnFocus={returnFocus}
        title={title}
      >
        <div className={styles.content}>
          <GoalSelection
            fallbackFocus={createButton}
            goals={data.goals}
            key={JSON.stringify(scope)}
            month={displayedMonth}
            year={displayedYear}
            ready={ready && !data.failed}
            standalone={scope.kind === 'years'}
          >
            {data.pending ? (
              <p className="sr-only" role="status">
                {t('goals.panel.loading')}
              </p>
            ) : null}
            {data.failed ? (
              <div className={styles.error} role="alert">
                <p>{t('goals.panel.unavailable')}</p>
                <button className="secondary-action" onClick={data.retry} type="button">
                  {t('foundation.retry')}
                </button>
              </div>
            ) : monthly ? (
              <GoalPanelMonthly
                evaluations={data.evaluations}
                goals={data.goals}
                handlers={handlers}
                month={scope.month}
              />
            ) : scope.kind === 'years' ? (
              <GoalPanelYears
                evaluations={data.evaluations}
                goals={data.goals}
                handlers={handlers}
                onStop={(goal, year) => setStopTarget({ goal, year })}
                years={scope.years}
              />
            ) : (
              <GoalPanelYearly
                evaluations={data.evaluations}
                goals={data.goals}
                handlers={handlers}
                year={scope.year}
              />
            )}
          </GoalSelection>
        </div>
      </Drawer>
      {editor.saved ? <Toast onDismiss={editor.dismissSaved}>{t('goals.saved')}</Toast> : null}
      <GoalEditorModals editor={editor} />
      {stopTarget ? (
        <StopGoalDialog
          close={() => setStopTarget(null)}
          defaultLastPeriod={defaultLastPeriod(stopTarget)}
          goal={latest(stopTarget.goal)}
          onStopped={() => setStopTarget(null)}
        />
      ) : null}
      {deleteTarget ? (
        <DeleteGoalDialog
          close={() => setDeleteTarget(null)}
          goal={latest(deleteTarget)}
          onDeleted={() => setDeleteTarget(null)}
          onStopInstead={(goal) => {
            setDeleteTarget(null);
            setStopTarget({ goal, year: null });
          }}
        />
      ) : null}
    </>
  );
}
