import { usePersistedChoice } from '@/hooks/use-persisted-choice';
import { GROUPING_MODES, type GroupingMode } from './groupingMode';

/** Grouping choice remembered per browser under `storageKey`. */
export function useGroupingMode(
  storageKey: string,
  fallback: GroupingMode,
): [GroupingMode, (mode: GroupingMode) => void] {
  return usePersistedChoice(storageKey, GROUPING_MODES, fallback);
}
