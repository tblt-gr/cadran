import { createContext, useContext } from 'react';

interface Selection {
  active: boolean;
  selected: ReadonlySet<string>;
  toggle: (id: string) => void;
}
export const GoalSelectionContext = createContext<Selection | null>(null);
export const useGoalSelection = () => useContext(GoalSelectionContext);
