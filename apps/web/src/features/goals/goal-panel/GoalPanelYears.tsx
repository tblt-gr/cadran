import type { GoalEvaluations, GoalSummary } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { buildRows, indexSummaries } from './goalPanelModel';
import { GoalRow } from './GoalRow';
import type { GoalRowHandlers } from './GoalRowActions';
import { countGoals } from './GoalsPanelButton';
import styles from './GoalPanel.module.css';

const skeletonRows = [0, 1, 2];

interface Props {
  years: number[];
  /** Absent while loading: every year keeps its summary over skeleton values and rows. */
  evaluations: GoalEvaluations | undefined;
  goals: GoalSummary[] | undefined;
  handlers: GoalRowHandlers;
  /** A stop opened from a year's list defaults to the year before that year. */
  onStop: (goal: GoalSummary, year: number) => void;
}

/**
 * The yearly goals of every selected year, one accordion item per year: the year and its goals on
 * target at the summary, the goal rows inside. The last selected year starts open.
 */
export function GoalPanelYears({ years, evaluations, goals, handlers, onStop }: Props) {
  const { t } = useTranslation();
  const [open, setOpen] = useState<ReadonlySet<number>>(() => new Set(years.slice(-1)));
  const summaries = goals ? indexSummaries(goals) : null;
  const toggle = (year: number, isOpen: boolean) =>
    setOpen((current) => {
      const next = new Set(current);
      if (isOpen) next.add(year);
      else next.delete(year);
      return next;
    });

  return (
    <div className={styles.accordion}>
      {years.map((year) => {
        const loaded = evaluations !== undefined && summaries !== null;
        const counts = loaded ? countGoals(evaluations, { kind: 'year', year }) : null;
        const rows = loaded
          ? buildRows(
              evaluations.years.find((period) => period.period === String(year)),
              summaries,
            )
          : [];
        const yearHandlers = { ...handlers, onStop: (goal: GoalSummary) => onStop(goal, year) };
        return (
          <Disclosure
            key={year}
            meta={
              !loaded ? (
                <SkeletonBlock className={styles.countBlock} />
              ) : counts && counts.total > 0 ? (
                `${counts.met} / ${counts.total}`
              ) : null
            }
            onToggle={(isOpen) => toggle(year, isOpen)}
            open={open.has(year)}
            spread
            title={String(year)}
          >
            {!loaded ? (
              <ul className={styles.rows}>
                {skeletonRows.map((index) => (
                  <GoalRow handlers={yearHandlers} key={index} row={null} />
                ))}
              </ul>
            ) : rows.length === 0 ? (
              <p className={styles.empty}>{t('goals.panel.emptyYearlyGoal')}</p>
            ) : (
              <ul className={styles.rows}>
                {rows.map((row) => (
                  <GoalRow handlers={yearHandlers} key={row.evaluation.goalId} row={row} />
                ))}
              </ul>
            )}
          </Disclosure>
        );
      })}
    </div>
  );
}
