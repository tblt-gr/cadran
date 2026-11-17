import type { AnnualColumn, AnnualReport, MultiYearReport } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalEvaluations } from '@/features/goals/goal-panel/useGoalPanelData';
import { useAnnualReportPreferences } from '@/features/reports/annual/useAnnualReport';
import { withoutLeadingEmptyMonths } from '@/features/reports/annual/annualColumns';
import { useAnnualColumnLabel } from '@/features/reports/annual/columnLabel';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import mixedPolicyStyles from '@/features/reports/annual/MixedPolicyBanner.module.css';
import { DetailedAggregateModal } from './DetailedAggregateModal';
import { DetailedCards } from './DetailedCards';
import { DetailedTable } from './DetailedTable';
import type { MarksOf } from './detailedReportProps';
import styles from './ContinuousDetailedReport.module.css';

interface ContinuousDetailedReportProps {
  /** Every year starts open when set; the parent re-keys the report when its toolbar button changes. */
  initiallyExpanded?: boolean;
  showChanges?: boolean;
  annualReports: AnnualReport[];
  report: MultiYearReport;
}

function selectedColumns(reports: AnnualReport[]): AnnualColumn[] {
  const columns = new Map<string, AnnualColumn>();
  for (const report of reports) {
    for (const column of report.columns) {
      columns.set(column.id, column);
    }
  }
  return [...columns.values()];
}

/**
 * One continuous presentation of monthly, server-supplied annual report rows. It deliberately
 * flattens display lists only: exact financial values remain owned by the annual responses.
 */
export function ContinuousDetailedReport({
  annualReports,
  initiallyExpanded = false,
  report,
  showChanges = true,
}: ContinuousDetailedReportProps) {
  const { t } = useTranslation();
  const labelOf = useAnnualColumnLabel();
  const [explained, setExplained] = useState<string | null>(null);
  // Every year starts folded: its row already carries the figures of the year.
  const [opened, setOpened] = useState<ReadonlySet<number>>(
    () => new Set(initiallyExpanded ? report.years.map((item) => item.year) : []),
  );
  const preferences = useAnnualReportPreferences();
  const goalsEnabled = !preferences.isPending && preferences.data?.goalMarks === true;
  const goals = useGoalEvaluations(
    { kind: 'years', years: report.years.map((year) => year.year) },
    goalsEnabled,
  );
  const columns = selectedColumns(annualReports);
  // The first year may start mid-year: its empty leading months are not rows of the chronology.
  const rows = annualReports.flatMap((annualReport, index) =>
    (index === 0 ? withoutLeadingEmptyMonths(annualReport.rows) : annualReport.rows).map((row) => ({
      annualReport,
      row,
    })),
  );
  const toggleYear = (year: number) =>
    setOpened((current) => {
      const next = new Set(current);
      if (!next.delete(year)) next.add(year);
      return next;
    });
  const marksOf: MarksOf = (scope, period, columnId) =>
    marksFor(goalsEnabled ? goals.evaluations?.[scope] : undefined, period, columnId);
  const versions = new Set(
    report.years.flatMap((year) =>
      year.metricPolicy.state === 'SINGLE' && year.metricPolicy.version !== null
        ? [year.metricPolicy.version]
        : [],
    ),
  );
  const hasMixedPolicy =
    report.years.some((year) => year.metricPolicy.state === 'MIXED') || versions.size > 1;

  return (
    <div className={styles.report}>
      <DetailedTable
        annualReports={annualReports}
        columns={columns}
        footer={
          goalsEnabled && goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null
        }
        marksOf={marksOf}
        onExplain={setExplained}
        onToggleYear={toggleYear}
        opened={opened}
        report={report}
        showChanges={showChanges}
      />
      {hasMixedPolicy ? (
        <aside className={mixedPolicyStyles.banner} role="status">
          <p>{t('reports.multiYear.detailedMixedPolicy')}</p>
          <a
            href="/parameters/metric-policy"
            onClick={(event) => handleClientNavigation(event, '/parameters/metric-policy')}
          >
            {t('reports.annual.mixedPolicyLink')}
          </a>
        </aside>
      ) : null}

      <DetailedCards
        annualReports={annualReports}
        columns={columns}
        marksOf={marksOf}
        onExplain={setExplained}
        onToggleYear={toggleYear}
        opened={opened}
        report={report}
        rows={rows}
        showChanges={showChanges}
      />
      {columns.find((column) => column.id === explained) ? (
        <DetailedAggregateModal
          annualReports={annualReports}
          close={() => setExplained(null)}
          column={columns.find((column) => column.id === explained)!}
          label={labelOf(columns.find((column) => column.id === explained)!)}
          quality={report.detailedPeriod[explained!]?.quality ?? report.quality}
        />
      ) : null}
    </div>
  );
}
