import type { GoalEvaluation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { formulaText, toleranceText } from '@/features/goals/goal-panel/goalEvaluationText';
import styles from './GoalDetail.module.css';

export function GoalDetailCalculation({ evaluation }: { evaluation: GoalEvaluation }) {
  const { t, i18n } = useTranslation();
  const tolerance = toleranceText(evaluation, t, i18n.language);
  return (
    <div className={styles.calculation}>
      <h4>{t('goals.detail.calculation')}</h4>
      <p>{formulaText(evaluation, t, i18n.language)}</p>
      {tolerance ? <p>{tolerance}</p> : null}
      {evaluation.start ? (
        <p>
          {t('goals.panel.start', {
            start: formatEvaluationFigure(evaluation.start, i18n.language) ?? t('goals.noValue'),
          })}
        </p>
      ) : null}
      {evaluation.pace ? <p>{t(`goals.panel.pace.${evaluation.pace.status}`)}</p> : null}
      {evaluation.explanationUrl ? (
        <a href={evaluation.explanationUrl}>{t('goals.stockExplanation')}</a>
      ) : null}
    </div>
  );
}
