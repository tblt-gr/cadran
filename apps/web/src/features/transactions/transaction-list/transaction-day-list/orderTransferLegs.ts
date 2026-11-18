import type { Transaction } from '@cadran/api-client';

/** Withdrawal first, then the fee, then the credit: the order money moves in. */
function legRank(transaction: Transaction): number {
  if (transaction.nature === 'FEE') return 1;
  return transaction.amount.value.startsWith('-') ? 0 : 2;
}

/**
 * The legs of a transfer are created withdrawal first, so the newest-first sort lists the credit
 * above it. Within a booking day they are gathered, at the place of the first leg met, in money
 * order; every other row keeps its place. Display only: applied to all the rows loaded so far, so
 * the order stays the same whichever page or virtual block a leg lands in.
 */
export function orderTransferLegs(rows: Transaction[]): Transaction[] {
  const keyOf = (row: Transaction) => `${row.bookedOn}|${row.transferId}`;
  const legs = new Map<string, Transaction[]>();
  for (const row of rows) {
    if (row.transferId == null) continue;
    const key = keyOf(row);
    legs.set(key, [...(legs.get(key) ?? []), row]);
  }
  const placed = new Set<string>();
  return rows.flatMap((row) => {
    if (row.transferId == null) return [row];
    const key = keyOf(row);
    if (placed.has(key)) return [];
    placed.add(key);
    return legs.get(key)!.toSorted((a, b) => legRank(a) - legRank(b));
  });
}
