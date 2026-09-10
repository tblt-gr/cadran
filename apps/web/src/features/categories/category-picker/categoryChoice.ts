export interface CategoryChoice {
  id: string;
  kind: 'category' | 'create';
  label: string;
}

/** Stable DOM id of one option, shared with the combobox `aria-activedescendant`. */
export function optionId(listId: string, choice: CategoryChoice): string {
  return `${listId}-${choice.id || 'none'}`;
}
