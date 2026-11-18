import type { AnnualReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { useGoalMarks } from '@/features/reports/useGoalMarks';
import { withoutLeadingEmptyMonths } from './annualColumns';
import { AnnualMonthCard } from './AnnualMonthCard';
import { AnnualSummaryCard } from './AnnualSummaryCard';
import styles from './AnnualMonthCards.module.css';

interface AnnualMonthCardsProps {
  onExplain: (columnId: string) => void;
  report: AnnualReport;
}

/** Below 640 px the table becomes one card per month, then one summary card, with the same values. */
export function AnnualMonthCards({ onExplain, report }: AnnualMonthCardsProps) {
  const { t } = useTranslation();
  // The same query as the table: one request, one cache entry.
  const goals = useGoalMarks({
    from: `${report.year}-01`,
    to: `${report.year}-12`,
    granularity: 'BOTH',
  });

  return (
    <>
      <ul
        aria-label={t('reports.annual.cardsLabel', { year: report.year })}
        className={styles.list}
      >
        {withoutLeadingEmptyMonths(report.rows).map((row) => (
          <AnnualMonthCard
            columns={report.columns}
            key={row.month}
            months={goals.evaluations?.months}
            row={row}
          />
        ))}
      </ul>
      <ul aria-label={t('reports.annual.summaryTitle')} className={styles.list}>
        <li className={`card ${styles.card}`}>
          <h3>{t('reports.annual.summaryTitle')}</h3>
        </li>
        {report.columns.map((column) => (
          <AnnualSummaryCard
            column={column}
            key={column.id}
            onExplain={onExplain}
            report={report}
            years={goals.evaluations?.years}
          />
        ))}
      </ul>
      {goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
    </>
  );
}
