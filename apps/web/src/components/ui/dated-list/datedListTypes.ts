import type { ReactNode } from 'react';

/** Checkbox wiring a list can offer: which row ids are selected and how to toggle one. */
export interface DatedListSelection {
  onToggle: (id: string) => void;
  selectedIds: ReadonlySet<string>;
}

/** What `renderRow` receives for one row when the list has a selection. */
export interface DatedRowSelection {
  /** True for a placeholder row: the checkbox exists but cannot be used yet. */
  disabled: boolean;
  selected: boolean;
  toggle: () => void;
}

/** Renders one row; `item` is null for a loading placeholder, which must keep the final markup. */
export type RenderDatedRow<T> = (
  item: T | null,
  context: { selection: DatedRowSelection | undefined },
) => ReactNode;

/** Props shared by the plain and the windowed rendering of a list. */
export interface DatedListLayout<T> {
  /** ISO 8601 calendar day of an item. Items must already be ordered by day. */
  getDay: (item: T) => string;
  getKey: (item: T) => string;
  /** Text of a day heading, for instance "Today" or a weekday and date. */
  dayLabel: (day: string) => string;
  headingLevel: 2 | 3 | 4;
  /** Accessible name of the list. */
  label: string;
  renderRow: RenderDatedRow<T>;
  selection?: DatedListSelection;
}
