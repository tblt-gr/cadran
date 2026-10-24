import type { DecimalAmount, Transaction } from '@cadran/api-client';
import { addDecimals, isCanonicalDecimal } from '@/lib/decimal';

export type DayTotal =
  /** Exact net of the day's live rows. */
  | { kind: 'amount'; amount: DecimalAmount }
  /** The day may continue on a page not loaded yet: a wrong total is worse than none. */
  | { kind: 'partial' }
  /** Mixed asset codes or an unreadable amount: never shown as zero. */
  | { kind: 'notComputable' }
  /** Nothing in the day counts toward a net (only voided or rejected rows). */
  | { kind: 'none' };

export interface DayGroup {
  /** ISO 8601 calendar day, as stored: it is already the workspace's own day. */
  day: string;
  transactions: Transaction[];
  total: DayTotal;
}

/** A voided or rejected movement never reached the ledger, so it stays visible but uncounted. */
function counts(transaction: Transaction): boolean {
  return transaction.state !== 'VOIDED' && transaction.state !== 'REJECTED';
}

function netOf(transactions: Transaction[]): DayTotal {
  const live = transactions.filter(counts);
  const [first] = live;

  if (first === undefined) return { kind: 'none' };

  const assetCode = first.amount.assetCode;
  let sum = '0';

  for (const transaction of live) {
    if (
      transaction.amount.assetCode !== assetCode ||
      !isCanonicalDecimal(transaction.amount.value)
    ) {
      return { kind: 'notComputable' };
    }
    sum = addDecimals(sum, transaction.amount.value);
  }

  return { kind: 'amount', amount: { value: sum, assetCode } };
}

/**
 * Groups loaded rows into runs of the same booking day, in the order received. A page shows its
 * own rows only: the last group of a non-final page may continue on the next page, and the first
 * group of a page reached by cursor may have begun on the previous one. Their totals are withheld
 * (`partial`).
 */
export function groupTransactionsByDay(
  transactions: Transaction[],
  hasMore: boolean,
  hasPrevious = false,
): DayGroup[] {
  const groups: DayGroup[] = [];

  for (const transaction of transactions) {
    const last = groups[groups.length - 1];

    if (last !== undefined && last.day === transaction.bookedOn) {
      last.transactions.push(transaction);
    } else {
      groups.push({
        day: transaction.bookedOn,
        transactions: [transaction],
        total: { kind: 'none' },
      });
    }
  }

  return groups.map((group, index) => ({
    ...group,
    total:
      (hasMore && index === groups.length - 1) || (hasPrevious && index === 0)
        ? { kind: 'partial' }
        : netOf(group.transactions),
  }));
}
