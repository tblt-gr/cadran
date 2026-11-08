import type { GoalEvaluation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './GoalEvaluationBadge.module.css';

const STATUS_KEYS = {
  MET: 'goals.panel.status.MET',
  IN_PROGRESS: 'goals.panel.status.IN_PROGRESS',
  NOT_CALCULABLE: 'goals.panel.status.NOT_CALCULABLE',
} as const;

/** The server status of an evaluation as a discreet pill: the text carries the meaning. */
export function GoalEvaluationBadge({ evaluation }: { evaluation: GoalEvaluation }) {
  const { t } = useTranslation();
  const text =
    evaluation.status === 'MISSED'
      ? t(
          evaluation.direction === 'CEILING'
            ? 'goals.panel.status.MISSED_CEILING'
            : 'goals.panel.status.MISSED_MINIMUM',
        )
      : t(STATUS_KEYS[evaluation.status]);
  return (
    <span className={evaluation.status === 'MET' ? `${styles.pill} ${styles.met}` : styles.pill}>
      {text}
    </span>
  );
}
