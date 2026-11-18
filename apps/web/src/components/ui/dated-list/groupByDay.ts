export interface DayGroup<T> {
  /** ISO 8601 calendar day, as given by the caller: it is already the workspace's own day. */
  day: string;
  items: T[];
}

/** Groups items into runs of the same day, in the order received: it never sorts or merges. */
export function groupByDay<T>(items: readonly T[], getDay: (item: T) => string): DayGroup<T>[] {
  const groups: DayGroup<T>[] = [];

  for (const item of items) {
    const day = getDay(item);
    const last = groups[groups.length - 1];

    if (last !== undefined && last.day === day) {
      last.items.push(item);
    } else {
      groups.push({ day, items: [item] });
    }
  }

  return groups;
}
