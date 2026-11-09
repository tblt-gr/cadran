import type { GoalSummary } from '@cadran/api-client';
import { useEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { BulkGoalLifecycleDialog } from './BulkGoalLifecycleDialog';
import type { GoalBulkAction } from './BulkGoalLifecycleForm';
import { GoalSelectionContext } from './goalSelectionContext';
import { GoalSelectionToggle, GoalSelectionToolbar } from './GoalSelectionControls';
import styles from './GoalSelection.module.css';

/** Selection lives for exactly one panel period; no hidden cross-page selection survives. */
export function GoalSelection({
  children,
  goals,
  ready,
  month,
  year,
  standalone = false,
  fallbackFocus,
}: {
  children: ReactNode;
  goals: GoalSummary[] | undefined;
  ready: boolean;
  month: string;
  year: number;
  standalone?: boolean;
  fallbackFocus: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  const root = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(false);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
  const [action, setAction] = useState<GoalBulkAction | null>(null);
  const focusAfterSave = useRef(false);
  const [ids, setIds] = useState<string[]>([]);
  useEffect(() => {
    if (action !== null || !focusAfterSave.current) return;
    focusAfterSave.current = false;
    // The nested modal must have committed its cleanup before the drawer can receive focus.
    const frame = window.requestAnimationFrame(() => {
      const pen = root.current?.querySelector<HTMLButtonElement>('button[aria-pressed]');
      const target = pen ?? fallbackFocus.current;
      if (target?.isConnected) target.focus();
    });
    return () => window.cancelAnimationFrame(frame);
  }, [action, fallbackFocus]);
  const clear = () => setSelected(new Set());
  const toggle = (id: string) =>
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else if (next.size < 100) next.add(id);
      return next;
    });
  useEffect(() => {
    const container = root.current;
    if (!container) return;
    const update = () => {
      const rows = container.querySelectorAll<HTMLElement>('[data-goal-id]');
      const visible = Array.from(
        new Set(
          Array.from(rows)
            .filter((row) => !row.closest('details:not([open])'))
            .map((row) => row.dataset.goalId!)
            .filter((id) => goals?.some((goal) => goal.id === id)),
        ),
      ).slice(0, 100);
      setIds((current) => (current.join() === visible.join() ? current : visible));
    };
    // Disclosure contents remain mounted; the selection scope follows actual displayed rows.
    const observer = new MutationObserver(update);
    observer.observe(container, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['open'],
    });
    queueMicrotask(update);
    return () => observer.disconnect();
  }, [goals]);
  const allSelected = ids.length > 0 && ids.every((id) => selected.has(id));
  return (
    <GoalSelectionContext.Provider
      value={{
        active,
        selected,
        toggle,
        ready,
        allSelected,
        someSelected: ids.some((id) => selected.has(id)),
        toggleActive: () => {
          setActive(!active);
          clear();
        },
        toggleAll: () => {
          setSelected(
            ids.length > 0 && ids.every((id) => selected.has(id)) ? new Set() : new Set(ids),
          );
        },
        stop: () => setAction('stop'),
        remove: () => setAction('delete'),
      }}
    >
      <div className={styles.selection} ref={root}>
        {standalone ? (
          <>
            <div className={styles.sectionHead}>
              <h3>{t('goals.panel.yearlySection')}</h3>
              <GoalSelectionToggle />
            </div>
            <GoalSelectionToolbar />
          </>
        ) : null}
        {children}
      </div>
      {action ? (
        <BulkGoalLifecycleDialog
          action={action}
          close={() => setAction(null)}
          goals={(goals ?? []).filter((goal) => selected.has(goal.id))}
          month={month}
          year={year}
          onSaved={() => {
            focusAfterSave.current = true;
            setAction(null);
            clear();
            setActive(false);
          }}
        />
      ) : null}
    </GoalSelectionContext.Provider>
  );
}
