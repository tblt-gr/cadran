import type { CategoryType } from '@cadran/api-client';

export interface CategoryChoice {
  id: string;
  kind: 'category' | 'create';
  label: string;
  color?: string | null;
  icon?: string | null;
  /** Set only when the picker mixes both types, which then groups the choices by type. */
  type?: CategoryType;
}

/** Stable DOM id of one option, shared with the combobox `aria-activedescendant`. */
export function optionId(listId: string, choice: CategoryChoice): string {
  return `${listId}-${choice.id || 'none'}`;
}
