import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { ProgressGauge } from '@/components/ui/progress-gauge/ProgressGauge';
import { formulaText, gapText } from './goalEvaluationText';
import { GoalEvaluationBadge } from './GoalEvaluationBadge';
import { goalFillRatio, goalTone, type GoalRowModel } from './goalPanelModel';
import { goalRecurrence } from './goalRecurrence';
import { GoalRowActions, type GoalRowHandlers } from './GoalRowActions';
import { useGoalScopeLabel } from './useGoalScopeLabel';
import styles from './GoalPanel.module.css';

interface Props {
  /** Absent while the evaluations load: every value becomes a block, the chrome stays. */
  row: GoalRowModel | null;
  handlers: GoalRowHandlers;
}

/** One evaluated goal. The panel renders the server status and figures; it never compares them. */
export function GoalRow({ row, handlers }: Props) {
  const { t, i18n } = useTranslation();
  const scopeLabel = useGoalScopeLabel();
  const evaluation = row?.evaluation ?? null;
  const summary = row?.summary ?? null;
  const target = evaluation ? formatEvaluationFigure(evaluation.target, i18n.language) : null;
  const achieved = evaluation ? formatEvaluationFigure(evaluation.achieved, i18n.language) : null;
  const figure = (value: string | null) =>
    evaluation === null ? (
      <MoneyValue value={null} />
    ) : value === null ? (
      <EmptyValue label={t('goals.noValue')} />
    ) : (
      <MoneyValue value={value} />
    );

  const tone = evaluation ? goalTone(evaluation) : null;
  const fill = evaluation ? goalFillRatio(evaluation) : null;

  return (
    <li
      className={styles.row}
      data-goal-id={evaluation?.goalId}
      data-tone={tone ?? undefined}
      tabIndex={evaluation ? -1 : undefined}
    >
      <div className={styles.rowHead}>
        <h4>{evaluation ? evaluation.label : <SkeletonBlock className={styles.labelBlock} />}</h4>
        <div className={styles.rowEnd}>
          {evaluation ? (
            <GoalEvaluationBadge evaluation={evaluation} />
          ) : (
            <SkeletonBlock className={styles.badgeBlock} />
          )}
          {summary ? <GoalRowActions goal={summary} handlers={handlers} /> : null}
        </div>
      </div>
      <div className={styles.progress}>
        {evaluation === null ? (
          <SkeletonBlock className={styles.barBlock} />
        ) : fill === null ? null : (
          <ProgressGauge
            label={t('goals.panel.progress', {
              label: evaluation.label,
              achieved: achieved ?? '',
              target: target ?? '',
            })}
            ratio={fill}
          />
        )}
        <p className={`money ${styles.figures}`}>
          <span className={styles.achieved}>{figure(achieved)}</span> / {figure(target)}
        </p>
      </div>
      <p className={styles.meta}>
        {evaluation ? (
          [
            scopeLabel(evaluation, summary),
            summary ? goalRecurrence(summary, t, i18n.language) : null,
          ]
            .filter(Boolean)
            .join(' · ')
        ) : (
          <SkeletonBlock className={styles.metaBlock} />
        )}
      </p>
      <p className={styles.meta}>
        {evaluation ? (
          [
            gapText(evaluation, t, i18n.language),
            formulaText(evaluation, t, i18n.language),
            t('goals.panel.version', { versionNo: evaluation.versionNo }),
          ]
            .filter(Boolean)
            .join(' · ')
        ) : (
          <SkeletonBlock className={styles.metaBlock} />
        )}
      </p>
      {evaluation?.pace ? (
        <p className={styles.pace}>{t(`goals.panel.pace.${evaluation.pace.status}`)}</p>
      ) : null}
      {evaluation?.status === 'NOT_CALCULABLE' && evaluation.reason !== null ? (
        <p className={styles.note}>{t(`goals.panel.reasons.${evaluation.reason}`)}</p>
      ) : null}
      {row && row.replaced.length > 0 ? (
        <ul className={styles.replaced}>
          {row.replaced.map((hidden) => (
            <li key={hidden.id}>
              {t(
                row.evaluation.periodicity === 'YEARLY'
                  ? 'goals.panel.replacedYear'
                  : 'goals.panel.replaced',
                { hidden: hidden.label, label: row.evaluation.label },
              )}
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}
