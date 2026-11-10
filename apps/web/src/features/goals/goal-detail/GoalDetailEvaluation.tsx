import type { GoalEvaluation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { GoalEvaluationBadge } from '@/features/goals/goal-panel/GoalEvaluationBadge';
import { GoalProgressBar } from '@/features/goals/goal-panel/GoalProgressBar';
import { goalTone } from '@/features/goals/goal-panel/goalPanelModel';
import { longMonthYear } from '@/features/goals/goal-panel/goalRecurrence';
import { gapText } from '@/features/goals/goal-panel/goalEvaluationText';
import { GoalDetailCalculation } from './GoalDetailCalculation';
import styles from './GoalDetail.module.css';

export function GoalDetailEvaluation({
  evaluation,
  period,
}: {
  evaluation: GoalEvaluation;
  period: string;
}) {
  const { t, i18n } = useTranslation();
  const figure = (value: Parameters<typeof formatEvaluationFigure>[0]) =>
    formatEvaluationFigure(value, i18n.language) ?? t('goals.noValue');
  return (
    <section className={`${styles.section} ${styles.evaluation}`} data-tone={goalTone(evaluation)}>
      <div className={styles.sectionHeading}>
        <h3>
          {t('goals.detail.evaluation', {
            period: /^\d{4}-\d{2}$/.test(period) ? longMonthYear(period, i18n.language) : period,
          })}
        </h3>
        <GoalEvaluationBadge evaluation={evaluation} />
      </div>
      <dl className={`${styles.values} ${styles.summary}`}>
        <div>
          <dt>{t('goals.detail.achieved')}</dt>
          <dd className={styles.achieved}>{figure(evaluation.achieved)}</dd>
        </div>
        <div>
          <dt>{t('goals.detail.target')}</dt>
          <dd>{figure(evaluation.target)}</dd>
        </div>
      </dl>
      <GoalProgressBar evaluation={evaluation} />
      <p className={styles.gap}>
        {gapText(evaluation, t, i18n.language) ?? t('goals.detail.noGap')}
      </p>
      {evaluation.status === 'MET' && evaluation.withinTolerance === true ? (
        <p>{t('goals.panel.metWithinTolerance')}</p>
      ) : null}
      {evaluation.reason ? <p>{t(`goals.panel.reasons.${evaluation.reason}`)}</p> : null}
      <GoalDetailCalculation evaluation={evaluation} />
    </section>
  );
}
