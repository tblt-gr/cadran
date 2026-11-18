import { defaultRangeExtractor, useWindowVirtualizer, windowScroll } from '@tanstack/react-virtual';
import { Fragment, useLayoutEffect, useRef, useState } from 'react';
import { todayInBrowser } from '@/lib/businessDay';
import type { DatedListLayout, DatedRowSelection } from './datedListTypes';
import { groupByDay } from './groupByDay';
import styles from './DatedList.module.css';

/** Rows of an average card: the estimate before a block is measured. */
const ESTIMATED_ROW_HEIGHT = 76;

/** Small measured blocks also bound the DOM when a single day contains thousands of rows. */
function itemBlocks<T>(items: readonly T[], getDay: (item: T) => string) {
  return groupByDay(items, getDay).flatMap((group, dayIndex) => {
    const blocks = [];
    for (let start = 0; start < group.items.length; start += 25) {
      blocks.push({
        day: group.day,
        dayIndex,
        heading: start === 0,
        items: group.items.slice(start, start + 25),
      });
    }
    return blocks;
  });
}

interface VirtualDatedDaysProps<T> extends DatedListLayout<T> {
  items: readonly T[];
  nextLoadedLabel: string;
  previousLoadedLabel: string;
}

/** The loaded list: only the blocks near the viewport (and the focused one) are mounted. */
export function VirtualDatedDays<T>({
  dayLabel,
  getDay,
  getKey,
  headingLevel,
  items,
  label,
  nextLoadedLabel,
  previousLoadedLabel,
  renderRow,
  selection,
}: VirtualDatedDaysProps<T>) {
  const today = todayInBrowser();
  const Heading = `h${headingLevel}` as const;
  const list = useRef<HTMLOListElement>(null);
  const pendingFocus = useRef<{ index: number; last: boolean } | null>(null);
  const [focused, setFocused] = useState<number | null>(null);
  const [margin, setMargin] = useState(0);
  const [headingHeight, setHeadingHeight] = useState(34);
  const blocks = itemBlocks(items, getDay);
  const estimateSize = (index: number) =>
    blocks[index]!.items.length * ESTIMATED_ROW_HEIGHT +
    (blocks[index]!.heading ? headingHeight : 0);
  const virtualizer = useWindowVirtualizer<HTMLUListElement>({
    count: blocks.length,
    getItemKey: (index) => getKey(blocks[index]!.items[0]!),
    estimateSize,
    // A hidden/frozen surface has no layout yet: keep its estimate until it can be measured.
    measureElement: (element) => {
      const index = Number(element.dataset.index);
      const height = element.getBoundingClientRect().height;
      return height ? height + (blocks[index]!.heading ? headingHeight : 0) : estimateSize(index);
    },
    initialRect: { width: 1024, height: 768 },
    scrollToFn: (offset, options, instance) => {
      if (Math.abs(offset + (options.adjustments ?? 0) - window.scrollY) > 0.5) {
        windowScroll(offset, options, instance);
      }
    },
    overscan: 1,
    scrollMargin: margin,
    rangeExtractor: (range) => {
      const indexes =
        blocks.length <= 8 ? blocks.map((_, index) => index) : defaultRangeExtractor(range);
      if (focused !== null && focused < blocks.length && !indexes.includes(focused))
        indexes.push(focused);
      return indexes.sort((a, b) => a - b);
    },
  });
  const virtualItems = virtualizer.getVirtualItems();
  useLayoutEffect(() => {
    const update = () => {
      if (list.current) setMargin(list.current.getBoundingClientRect().top + window.scrollY);
      const height = list.current
        ?.querySelector('[data-day-heading]')
        ?.getBoundingClientRect().height;
      // The day card's own two borders belong to the first block of the day, with its title.
      if (height) setHeadingHeight(height + 2);
    };
    update();
    window.addEventListener('resize', update);
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
    if (list.current?.parentElement) observer?.observe(list.current.parentElement);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', update);
    };
  }, []);
  useLayoutEffect(() => {
    const pending = pendingFocus.current;
    if (!pending) return;
    const controls = list.current
      ?.querySelector(`[data-index="${pending.index}"]`)
      ?.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled])');
    const control = pending.last ? controls?.[controls.length - 1] : controls?.[0];
    if (control) {
      pendingFocus.current = null;
      control.focus({ preventScroll: true });
      control.scrollIntoView?.({ block: 'center', inline: 'nearest' });
    }
  });

  const visible = virtualItems.filter(
    (item) =>
      item.index !== focused ||
      (virtualizer.range &&
        item.index >= virtualizer.range.startIndex - 1 &&
        item.index <= virtualizer.range.endIndex + 1),
  );
  const first = visible[0]?.index ?? 0;
  const last = visible.at(-1)?.index ?? 0;
  function reveal(index: number, lastControl: boolean) {
    pendingFocus.current = { index, last: lastControl };
    setFocused(index);
    virtualizer.scrollToIndex(index, { align: lastControl ? 'end' : 'start' });
  }
  const lastEnd = virtualItems.at(-1)?.end ?? margin;
  const runs: Array<typeof virtualItems> = [];
  for (const item of virtualItems) {
    const lastRun = runs.at(-1);
    const previous = lastRun?.at(-1);
    if (previous && blocks[previous.index]!.day === blocks[item.index]!.day) {
      lastRun!.push(item);
    } else {
      runs.push([item]);
    }
  }
  const dayCount = (blocks.at(-1)?.dayIndex ?? -1) + 1;

  return (
    <div
      className={styles.panel}
      onFocusCapture={(event) => {
        const block = (event.target as HTMLElement).closest<HTMLElement>('[data-index]');
        setFocused(block ? Number(block.dataset.index) : null);
      }}
      onBlurCapture={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocused(null);
      }}
    >
      {first > 0 ? (
        <button
          className={`secondary-action ${styles.windowControl}`}
          onClick={() => reveal(first - 1, true)}
          type="button"
        >
          {previousLoadedLabel}
        </button>
      ) : null}
      <ol aria-label={label} className={styles.days} ref={list}>
        {runs.map((run, runIndex) => {
          const firstItem = run[0]!;
          const block = blocks[firstItem.index]!;
          const previousEnd = runs[runIndex - 1]?.at(-1)?.end ?? margin;
          // A continuation still needs its sticky date. Take its height out of the omitted
          // rows above it so mounting that context does not move the visible rows.
          const gap = firstItem.start - previousEnd - (block.heading ? 0 : headingHeight);
          return (
            <Fragment key={block.day}>
              {gap > 0 ? <li aria-hidden="true" style={{ height: gap }} /> : null}
              <li aria-posinset={block.dayIndex + 1} aria-setsize={dayCount} className={styles.day}>
                <Heading
                  className={styles.dayTitle}
                  data-future={block.day > today}
                  data-day-heading
                >
                  <time className={styles.date} dateTime={block.day}>
                    {dayLabel(block.day)}
                  </time>
                </Heading>
                <div className={`card ${styles.dayCard}`}>
                  {run.map((item, index) => (
                    <Fragment key={item.key}>
                      {/* A focused block can stay mounted far from the viewport. Keeping it
                      under the same day parent preserves both rows when focus moves. */}
                      {index > 0 && item.start > run[index - 1]!.end ? (
                        <div
                          aria-hidden="true"
                          style={{ height: item.start - run[index - 1]!.end }}
                        />
                      ) : null}
                      <ul
                        aria-label={blocks[item.index]!.heading ? undefined : dayLabel(block.day)}
                        className={styles.rows}
                        data-index={item.index}
                        ref={virtualizer.measureElement}
                      >
                        {blocks[item.index]!.items.map((entry) => {
                          const id = getKey(entry);
                          const rowSelection: DatedRowSelection | undefined = selection && {
                            disabled: false,
                            selected: selection.selectedIds.has(id),
                            toggle: () => selection.onToggle(id),
                          };
                          return (
                            <Fragment key={id}>
                              {renderRow(entry, { selection: rowSelection })}
                            </Fragment>
                          );
                        })}
                      </ul>
                    </Fragment>
                  ))}
                </div>
              </li>
            </Fragment>
          );
        })}
        {virtualizer.getTotalSize() + margin > lastEnd ? (
          <li
            aria-hidden="true"
            style={{ height: virtualizer.getTotalSize() + margin - lastEnd }}
          />
        ) : null}
      </ol>
      {last < blocks.length - 1 ? (
        <button
          className={`secondary-action ${styles.windowControl}`}
          onClick={() => reveal(last + 1, false)}
          type="button"
        >
          {nextLoadedLabel}
        </button>
      ) : null}
    </div>
  );
}
