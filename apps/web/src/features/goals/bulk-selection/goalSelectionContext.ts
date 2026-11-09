import { createContext, useContext } from 'react';

interface Selection {
  active: boolean;
  selected: ReadonlySet<string>;
  toggle: (id: string) => void;
  ready: boolean;
  toggleActive: () => void;
  allSelected: boolean;
  someSelected: boolean;
  toggleAll: () => void;
  stop: () => void;
  remove: () => void;
}
export const GoalSelectionContext = createContext<Selection | null>(null);
export const useGoalSelection = () => useContext(GoalSelectionContext);
