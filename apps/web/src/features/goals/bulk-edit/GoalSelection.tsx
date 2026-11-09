import type { GoalSummary } from '@cadran/api-client';
import { useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { BulkGoalModal } from './BulkGoalModal';
import { GoalSelectionContext } from './goalSelectionContext';
import styles from './BulkGoal.module.css';

/** Selection lives for exactly one panel period; no hidden cross-page selection survives. */
export function GoalSelection({
  children,
  goals,
  ready,
  month,
}: {
  children: ReactNode;
  goals: GoalSummary[] | undefined;
  ready: boolean;
  month: string;
}) {
  const { t } = useTranslation();
  const root = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(false);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
  const [editing, setEditing] = useState(false);
  const clear = () => setSelected(new Set());
  const toggle = (id: string) =>
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else if (next.size < 100) next.add(id);
      return next;
    });
  const selectAll = () => {
    const rows = root.current?.querySelectorAll<HTMLElement>('[data-goal-id]') ?? [];
    const ids = Array.from(rows)
      .filter((row) => !row.closest('details:not([open])'))
      .map((row) => row.dataset.goalId!)
      .filter((id) => goals?.some((goal) => goal.id === id));
    setSelected(new Set(Array.from(new Set(ids)).slice(0, 100)));
  };
  return (
    <GoalSelectionContext.Provider value={{ active, selected, toggle }}>
      <div className={styles.selection} ref={root}>
        <div className={styles.toolbar}>
          <button
            className="secondary-action"
            disabled={!ready}
            onClick={() => {
              setActive(!active);
              clear();
            }}
            type="button"
          >
            {t(active ? 'goals.bulk.endSelection' : 'goals.bulk.select')}
          </button>
          {active ? (
            <>
              <p aria-live="polite" role="status">
                {t('goals.bulk.count', { count: selected.size })}
              </p>
              <button className="secondary-action" onClick={selectAll} type="button">
                {t('goals.bulk.selectAll')}
              </button>
              <button
                className="secondary-action"
                disabled={selected.size === 0}
                onClick={clear}
                type="button"
              >
                {t('goals.bulk.clear')}
              </button>
              <button
                className="secondary-action"
                disabled={selected.size === 0}
                onClick={() => setEditing(true)}
                type="button"
              >
                {t('goals.bulk.edit')}
              </button>
            </>
          ) : null}
        </div>
        {children}
      </div>
      {editing ? (
        <BulkGoalModal
          close={() => setEditing(false)}
          goals={(goals ?? []).filter((goal) => selected.has(goal.id))}
          month={month}
          onSaved={() => {
            setEditing(false);
            clear();
            setActive(false);
          }}
        />
      ) : null}
    </GoalSelectionContext.Provider>
  );
}
