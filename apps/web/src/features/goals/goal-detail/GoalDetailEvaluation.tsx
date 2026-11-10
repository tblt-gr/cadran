import type { GoalEvaluation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { GoalEvaluationBadge } from '@/features/goals/goal-panel/GoalEvaluationBadge';
import {
  formulaText,
  gapText,
  toleranceText,
} from '@/features/goals/goal-panel/goalEvaluationText';
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
    <section className={styles.section}>
      <h3>{t('goals.detail.evaluation', { period })}</h3>
      <GoalEvaluationBadge evaluation={evaluation} />
      <dl className={styles.values}>
        <div>
          <dt>{t('goals.detail.target')}</dt>
          <dd>{figure(evaluation.target)}</dd>
        </div>
        <div>
          <dt>{t('goals.detail.achieved')}</dt>
          <dd>{figure(evaluation.achieved)}</dd>
        </div>
      </dl>
      <p>{gapText(evaluation, t, i18n.language) ?? t('goals.detail.noGap')}</p>
      {toleranceText(evaluation, t, i18n.language) ? (
        <p>{toleranceText(evaluation, t, i18n.language)}</p>
      ) : null}
      {evaluation.status === 'MET' && evaluation.withinTolerance === true ? (
        <p>{t('goals.panel.metWithinTolerance')}</p>
      ) : null}
      {evaluation.reason ? <p>{t(`goals.panel.reasons.${evaluation.reason}`)}</p> : null}
      {evaluation.start ? (
        <p>{t('goals.panel.start', { start: figure(evaluation.start) })}</p>
      ) : null}
      {evaluation.pace ? <p>{t(`goals.panel.pace.${evaluation.pace.status}`)}</p> : null}
      <p>{formulaText(evaluation, t, i18n.language)}</p>
      {evaluation.explanationUrl ? (
        <a href={evaluation.explanationUrl}>{t('goals.stockExplanation')}</a>
      ) : null}
    </section>
  );
}
