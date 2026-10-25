import type { Transaction } from '@cadran/api-client';

export interface DayGroup {
  /** ISO 8601 calendar day, as stored: it is already the workspace's own day. */
  day: string;
  transactions: Transaction[];
}

/** Groups rows into runs of the same booking day, in the order received. */
export function groupTransactionsByDay(transactions: Transaction[]): DayGroup[] {
  const groups: DayGroup[] = [];

  for (const transaction of transactions) {
    const last = groups[groups.length - 1];

    if (last !== undefined && last.day === transaction.bookedOn) {
      last.transactions.push(transaction);
    } else {
      groups.push({ day: transaction.bookedOn, transactions: [transaction] });
    }
  }

  return groups;
}
