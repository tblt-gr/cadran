import {
  useGoalEvaluationRange,
  type EvaluationRange,
} from '@/features/goals/goal-panel/useGoalPanelData';
import { useAnnualReportPreferences } from '@/features/reports/annual/useAnnualReport';

/**
 * The goal evaluations a report marks its figures with, subject to the `goalMarks` preference of
 * the workspace (shared with the column selection, on by default). Nothing is requested while the
 * preference loads, nor when it is off: the report then reads as it did without goals.
 */
export function useGoalMarks(range: EvaluationRange) {
  const preferences = useAnnualReportPreferences();
  // An unreadable preference keeps the default: the figures are shown with their goals.
  const shown = preferences.data?.goalMarks !== false;
  const enabled = shown && !preferences.isPending;
  const goals = useGoalEvaluationRange(range, enabled);
  return {
    /** The `Objectif` column of the flows summary exists unless the preference turns it off. */
    shown,
    evaluations: enabled ? goals.evaluations : undefined,
    failed: enabled && goals.failed,
    retry: goals.retry,
  };
}
