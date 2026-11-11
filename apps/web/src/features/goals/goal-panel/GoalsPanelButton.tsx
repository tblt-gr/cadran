import type { GoalEvaluations } from '@cadran/api-client';
import type { Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { decimalRatio } from '@/lib/decimal';
import { ratioToPercent } from '@/lib/formatRatioPercentage';
import styles from './GoalsPanelButton.module.css';
import { isOnTarget } from './goalPanelModel';
import { useGoalEvaluations, type GoalPanelScope } from './useGoalPanelData';

interface Props {
  scope: GoalPanelScope;
  onOpen: () => void;
  buttonRef: Ref<HTMLButtonElement>;
  /** The page's own data has settled: only then does the counter ask for the evaluations. */
  ready: boolean;
}

/** Goals of the scope on target now, counted from server data: the hidden ones are not in the items. */
export function countGoals(
  evaluations: GoalEvaluations,
  scope: GoalPanelScope,
): { met: number; total: number } {
  const wanted = scope.kind === 'month' ? 'MONTHLY' : 'YEARLY';
  const periods =
    scope.kind === 'month'
      ? evaluations.months.filter((candidate) => candidate.period === scope.month)
      : evaluations.years.filter((candidate) =>
          (scope.kind === 'year' ? [scope.year] : scope.years).some(
            (year) => String(year) === candidate.period,
          ),
        );
  const items = periods
    .flatMap((period) => period.items)
    .filter((item) => item.periodicity === wanted);
  return { met: items.filter(isOnTarget).length, total: items.length };
}

/**
 * Header button of the budget pages: shows how many goals of the displayed period are met and
 * opens the goals panel. It shares the panel's query, so opening the panel is instant.
 */
export function GoalsPanelButton({ scope, onOpen, buttonRef, ready }: Props) {
  const { t } = useTranslation();
  const data = useGoalEvaluations(scope, ready);
  const label = t('budget.monthly.goals');

  if (data.pending && data.evaluations === undefined) {
    return (
      <button
        aria-label={label}
        className={`secondary-action ${styles.button}`}
        disabled
        ref={buttonRef}
        title={label}
        type="button"
      >
        <Icon name="goals" size={18} />
        <SkeletonBlock className={styles.barBlock} />
        <SkeletonBlock className={styles.countBlock} />
      </button>
    );
  }
  const counts = data.evaluations && !data.failed ? countGoals(data.evaluations, scope) : null;
  if (counts === null || counts.total === 0) {
    return (
      <button
        aria-label={label}
        className={`icon-button ${styles.bare}`}
        onClick={onOpen}
        ref={buttonRef}
        title={label}
        type="button"
      >
        <Icon name="goals" size={18} />
      </button>
    );
  }
  const name = t('goals.panel.progressButton', { met: counts.met, total: counts.total });
  const percent = ratioToPercent(decimalRatio(String(counts.met), String(counts.total), 4));

  return (
    <button
      aria-label={name}
      className={`secondary-action ${styles.button}`}
      onClick={onOpen}
      ref={buttonRef}
      title={name}
      type="button"
    >
      <Icon name="goals" size={18} />
      <span aria-hidden="true" className={styles.bar}>
        <span className={styles.fill} style={{ width: `${percent}%` }} />
      </span>
      <span aria-hidden="true" className={styles.count}>
        {counts.met} / {counts.total}
      </span>
    </button>
  );
}
