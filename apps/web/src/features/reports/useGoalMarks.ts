import {
  useGoalEvaluationRange,
  type EvaluationRange,
} from '@/features/goals/goal-panel/useGoalPanelData';
import { useAnnualReportPreferences } from '@/features/reports/annual/useAnnualReport';

/**
 * The goal evaluations a report marks its figures with, subject to the `goalMarks` preference of
 * the workspace (shared with the column selection, off until explicitly enabled). Nothing is requested while the
 * preference loads, nor when it is off: the report then reads as it did without goals.
 */
export function useGoalMarks(range: EvaluationRange) {
  const preferences = useAnnualReportPreferences();
  const shown = preferences.data?.goalMarks === true;
  const enabled = shown && !preferences.isPending;
  const goals = useGoalEvaluationRange(range, enabled);
  return {
    /** The `Objectif` column of the flows summary follows the saved preference. */
    shown,
    evaluations: enabled ? goals.evaluations : undefined,
    failed: enabled && goals.failed,
    retry: goals.retry,
  };
}
