import { TransactionRegisterHeading } from './TransactionRegisterHeading';
import type { Account, Transaction } from '@cadran/api-client';
import { defaultRangeExtractor, useWindowVirtualizer, windowScroll } from '@tanstack/react-virtual';
import { Fragment, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { RowSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { formatCalendarWeekdayDay } from '@/lib/decimal';
import { groupTransactionsByDay } from './groupTransactionsByDay';
import { TransactionDayRow } from './TransactionDayRow';
import styles from './TransactionDayList.module.css';

/** Small measured blocks also bound the DOM when a single day contains thousands of rows. */
function transactionBlocks(transactions: Transaction[]) {
  return groupTransactionsByDay(transactions).flatMap((group, dayIndex) => {
    const blocks = [];
    for (let start = 0; start < group.transactions.length; start += 25) {
      blocks.push({
        day: group.day,
        dayIndex,
        heading: start === 0,
        transactions: group.transactions.slice(start, start + 25),
      });
    }
    return blocks;
  });
}

export function VirtualTransactionDays({
  accounts,
  headingLevel,
  label,
  selection,
  transactions,
}: {
  accounts?: Account[];
  headingLevel: 3 | 4;
  label: string;
  selection?: RowSelection;
  transactions: Transaction[];
}) {
  const { i18n, t } = useTranslation();
  const Heading = `h${headingLevel}` as const;
  const list = useRef<HTMLOListElement>(null);
  const pendingFocus = useRef<{ index: number; last: boolean } | null>(null);
  const [focused, setFocused] = useState<number | null>(null);
  const [margin, setMargin] = useState(0);
  const [headingHeight, setHeadingHeight] = useState(34);
  const blocks = transactionBlocks(transactions);
  const transferAccounts = new Map<string, Array<{ id: string; account: string | undefined }>>();
  for (const transaction of transactions) {
    if (!transaction.transferId || transaction.nature !== 'TRANSFER') continue;
    const legs = transferAccounts.get(transaction.transferId) ?? [];
    legs.push({
      id: transaction.id,
      account: accounts?.find((account) => account.id === transaction.accountId)?.label,
    });
    transferAccounts.set(transaction.transferId, legs);
  }
  const estimateSize = (index: number) =>
    blocks[index]!.transactions.length * 76 + (blocks[index]!.heading ? headingHeight : 0);
  const virtualizer = useWindowVirtualizer<HTMLUListElement>({
    count: blocks.length,
    getItemKey: (index) => blocks[index]!.transactions[0]!.id,
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
      if (height) setHeadingHeight(height);
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
      className={`card ${styles.panel}`}
      onFocusCapture={(event) => {
        const block = (event.target as HTMLElement).closest<HTMLElement>('[data-index]');
        setFocused(block ? Number(block.dataset.index) : null);
      }}
      onBlurCapture={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setFocused(null);
      }}
    >
      <TransactionRegisterHeading
        accounts={accounts !== undefined}
        selection={selection !== undefined}
      />
      {first > 0 ? (
        <button
          className={`secondary-action ${styles.windowControl}`}
          onClick={() => reveal(first - 1, true)}
          type="button"
        >
          {t('transactions.pagination.previousLoaded')}
        </button>
      ) : null}
      <ol aria-label={label} className={styles.days} ref={list}>
        {runs.map((run, runIndex) => {
          const firstItem = run[0]!;
          const block = blocks[firstItem.index]!;
          const previousEnd = runs[runIndex - 1]?.at(-1)?.end ?? margin;
          // A continuation still needs its sticky date. Take its height out of the omitted
          // rows above it so mounting that context does not move the visible transactions.
          const gap = firstItem.start - previousEnd - (block.heading ? 0 : headingHeight);
          return (
            <Fragment key={block.day}>
              {gap > 0 ? <li aria-hidden="true" style={{ height: gap }} /> : null}
              <li aria-posinset={block.dayIndex + 1} aria-setsize={dayCount} className={styles.day}>
                <Heading className={styles.dayHeader} data-day-heading>
                  <time className={styles.date} dateTime={block.day}>
                    {formatCalendarWeekdayDay(block.day, i18n.language)}
                  </time>
                </Heading>
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
                      aria-label={
                        blocks[item.index]!.heading
                          ? undefined
                          : formatCalendarWeekdayDay(block.day, i18n.language)
                      }
                      className={styles.rows}
                      data-index={item.index}
                      ref={virtualizer.measureElement}
                    >
                      {blocks[item.index]!.transactions.map((transaction) => (
                        <TransactionDayRow
                          accounts={accounts}
                          counterpartAccount={
                            transaction.nature === 'TRANSFER' && transaction.transferId
                              ? transferAccounts
                                  .get(transaction.transferId)
                                  ?.find((leg) => leg.id !== transaction.id)?.account
                              : undefined
                          }
                          key={transaction.id}
                          selection={selection}
                          transaction={transaction}
                        />
                      ))}
                    </ul>
                  </Fragment>
                ))}
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
          {t('transactions.pagination.nextLoaded')}
        </button>
      ) : null}
    </div>
  );
}
