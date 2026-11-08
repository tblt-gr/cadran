import type { GoalEvaluation } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { pushGoalPanel } from '@/features/goals/goal-panel/goalPanelSearch';
import { marksTone, statusText, targetValue } from './goalMarks';
import styles from './GoalMark.module.css';

/** The value of a figure that carries goals, in the tone of its first goal; plain without goal. */
export function GoalMarkedValue({
  children,
  items,
}: {
  children: ReactNode;
  items: readonly GoalEvaluation[];
}) {
  const tone = marksTone(items);
  if (tone === null) return children;
  return (
    <span className={styles.value} data-tone={tone}>
      {children}
    </span>
  );
}

interface GoalMarkProps {
  items: readonly GoalEvaluation[];
  /** The period of the figure, `YYYY-MM` or `YYYY`: the panel opens on it. */
  period: string;
}

/**
 * The small `i` button after a marked figure; its name carries the goal and its status. It opens
 * the goals panel on the period of the figure, with the first (worst) goal focused.
 */
export function GoalMark({ items, period }: GoalMarkProps) {
  const { t } = useTranslation();
  const first = items[0];
  if (first === undefined) return null;
  return (
    <InfoButton
      aria-haspopup="dialog"
      className={styles.info}
      label={t('goals.mark.explain', { label: first.label, status: statusText(first, t) })}
      onClick={() => pushGoalPanel({ goalId: first.goalId, period })}
    />
  );
}

/** The cell of the `Objectif` column of the flows summary: the value to reach, then the `i` button. */
export function GoalTarget({ items, period }: GoalMarkProps) {
  const { i18n } = useTranslation();
  const first = items[0];
  if (first === undefined) return null;
  const value = targetValue(first, i18n.language);
  return (
    <>
      {value === null ? null : <span className={styles.target}>{value}</span>}
      <GoalMark items={items} period={period} />
    </>
  );
}

/** Header of the `Objectif` column of the flows summary. */
export function GoalColumnHead() {
  const { t } = useTranslation();
  return <span className={styles.columnHead}>{t('goals.mark.column')}</span>;
}
