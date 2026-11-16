import type { MonthlyRecapTotals } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { RecapSettingsButton } from './RecapSettingsButton';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { GoalEditorModals } from '@/features/goals/goal-panel/GoalEditorModals';
import { useGoalEditor, type GoalCreateDefaults } from '@/features/goals/goal-panel/useGoalEditor';
import { TotalsSection } from './TotalsSection';
import { goalDefaultsFor, marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalMarks } from '@/features/reports/useGoalMarks';
import { UpcomingNotice } from '@/features/settings/interface/UpcomingNotice';
import { useMonthlyReport } from '@/features/reports/useMonthlyReport';
import { CategoryRow, MetricRow } from './MetricRow';
import { asRecapMetric, UNAVAILABLE, type RecapMetric } from './recapMetric';
import { RATE_KPIS, RECAP_KPIS } from './recapKpis';
import styles from './TotalsCard.module.css';

interface TotalsCardProps {
  info?: ReactNode;
  month: string;
  onConfigure: () => void;
  owner: boolean;
  totals: MonthlyRecapTotals;
  visibleCategoryIds: string[];
  visibleAxes: string[];
}

export function TotalsCard({
  info,
  month,
  onConfigure,
  owner,
  totals,
  visibleAxes,
  visibleCategoryIds,
}: TotalsCardProps) {
  const { t } = useTranslation();
  // The recap carries six of the indicators; the month's other flows come from the monthly projection.
  const projection = useMonthlyReport(month);
  // A month's goals mark the rows that carry their figure; the rows stay as they are while the
  // evaluations load or fail.
  const goals = useGoalMarks({ from: month, to: month, granularity: 'MONTH' });
  const marksOf = (columnKey: string) => marksFor(goals.evaluations?.months, month, columnKey);
  const editor = useGoalEditor();
  // Only the owner writes goals; a row no goal can target offers nothing.
  const creatorFor = (columnKey: string) => {
    const defaults: GoalCreateDefaults | null = owner ? goalDefaultsFor(columnKey, month) : null;
    return defaults ? () => editor.openCreate(defaults) : undefined;
  };
  const axes = totals.expensesByAxis.filter((axis) => visibleAxes.includes(axis.axis));
  const categories = totals.categories.filter((category) =>
    visibleCategoryIds.includes(category.id),
  );
  return (
    <section aria-labelledby="monthly-recap-totals-title" className={`card ${styles.panel}`}>
      <header className={styles.heading}>
        <div className={styles.headingTitle}>
          <div className={styles.titleGroup}>
            <h3 id="monthly-recap-totals-title">{t('budget.monthly.recap.totals.title')}</h3>
            {info}
          </div>
          {owner ? <RecapSettingsButton onClick={onConfigure} /> : null}
        </div>
      </header>
      <UpcomingNotice
        includesUpcoming={projection.data?.includesUpcoming ?? false}
        upcomingCount={projection.data?.upcomingCount ?? 0}
      />
      <TotalsSection goalHead={goals.shown} title={t('budget.monthly.recap.totals.flows')}>
        {RECAP_KPIS.map((kpi) => {
          const own =
            kpi in totals ? (totals[kpi as keyof MonthlyRecapTotals] as RecapMetric) : null;
          const extra = projection.data?.[kpi];
          const metric =
            own ??
            (extra
              ? asRecapMetric(kpi, extra)
              : projection.isError
                ? asRecapMetric(kpi, UNAVAILABLE)
                : null);
          return (
            <MetricRow
              key={kpi}
              label={t(`reports.metrics.${kpi}`)}
              goalColumn={goals.shown}
              marks={marksOf(kpi)}
              metric={metric}
              onCreateGoal={creatorFor(kpi)}
              goalPeriod={month}
              month={month}
              rate={RATE_KPIS.includes(kpi)}
            />
          );
        })}
      </TotalsSection>
      {axes.length ? (
        <TotalsSection goalHead={goals.shown} title={t('budget.monthly.recap.totals.axes')}>
          {axes.map((axis) => (
            <MetricRow
              axis={axis.axis}
              key={axis.axis}
              goalColumn={goals.shown}
              marks={marksOf(`axis:${axis.axis}`)}
              onCreateGoal={creatorFor(`axis:${axis.axis}`)}
              month={month}
              metric={axis}
              goalPeriod={month}
            />
          ))}
        </TotalsSection>
      ) : null}
      {categories.length ? (
        <TotalsSection goalHead={goals.shown} title={t('budget.monthly.recap.totals.categories')}>
          {categories.map((category) => (
            <CategoryRow
              category={category}
              key={category.id}
              goalColumn={goals.shown}
              marks={marksOf(`category:${category.id}`)}
              onCreateGoal={creatorFor(`category:${category.id}`)}
              month={month}
              goalPeriod={month}
            />
          ))}
        </TotalsSection>
      ) : null}
      {goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
      <GoalEditorModals editor={editor} />
    </section>
  );
}
