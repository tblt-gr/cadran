import type { GoalEvaluations, GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { GoalPanelSection } from './GoalPanelSection';
import { buildRows, indexSummaries } from './goalPanelModel';
import { GoalRow } from './GoalRow';
import type { GoalRowHandlers } from './GoalRowActions';
import styles from './GoalPanel.module.css';

const skeletonRows = [0, 1, 2];

interface Props {
  year: number;
  /** Absent while loading: the heading stays, rows become skeletons. */
  evaluations: GoalEvaluations | undefined;
  goals: GoalSummary[] | undefined;
  handlers: GoalRowHandlers;
}

/** The yearly goals of one year. */
export function GoalPanelYearly({ year, evaluations, goals, handlers }: Props) {
  const { t } = useTranslation();
  const title = t('goals.panel.yearlySection');

  if (evaluations === undefined || goals === undefined) {
    return (
      <GoalPanelSection title={title}>
        {skeletonRows.map((index) => (
          <GoalRow handlers={handlers} key={index} row={null} />
        ))}
      </GoalPanelSection>
    );
  }
  const rows = buildRows(
    evaluations.years.find((period) => period.period === String(year)),
    indexSummaries(goals),
  );
  if (rows.length === 0) return <p className={styles.empty}>{t('goals.panel.emptyYear')}</p>;
  return (
    <GoalPanelSection title={title}>
      {rows.map((row) => (
        <GoalRow handlers={handlers} key={row.evaluation.goalId} row={row} />
      ))}
    </GoalPanelSection>
  );
}
