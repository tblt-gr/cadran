import type { AnnualColumn, AnnualReport, MultiYearReport } from '@cadran/api-client';
import type { GoalEvaluation } from '@cadran/api-client';

/** The goals of a year or of a month that carry one column's figure. */
export type MarksOf = (
  scope: 'years' | 'months',
  period: string,
  columnId: string,
) => readonly GoalEvaluation[];

/** What the table and the cards of the continuous report both draw from. */
export interface DetailedReportViewProps {
  annualReports: AnnualReport[];
  columns: AnnualColumn[];
  marksOf: MarksOf;
  opened: ReadonlySet<number>;
  report: MultiYearReport;
  showChanges: boolean;
  onExplain: (columnId: string) => void;
  onToggleYear: (year: number) => void;
}
