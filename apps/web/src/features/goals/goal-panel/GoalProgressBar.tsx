import type { GoalEvaluation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ProgressGauge } from '@/components/ui/progress-gauge/ProgressGauge';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import { goalFillRatio } from './goalPanelModel';
import styles from './GoalProgressBar.module.css';

/** Shared geometry of a goal card and its details; the server remains the source of its status. */
export function GoalProgressBar({ evaluation }: { evaluation: GoalEvaluation | null }) {
  const { t, i18n } = useTranslation();
  if (evaluation === null) return <SkeletonBlock className={styles.pending} />;
  const ratio = goalFillRatio(evaluation);
  if (ratio === null) return null;
  return (
    <ProgressGauge
      label={t('goals.panel.progress', {
        label: evaluation.label,
        achieved: formatEvaluationFigure(evaluation.achieved, i18n.language) ?? '',
        target: formatEvaluationFigure(evaluation.target, i18n.language) ?? '',
      })}
      ratio={ratio}
    />
  );
}
