import type { Account, Transaction } from '@cadran/api-client';

/** Account label of the other leg of each transfer transaction, by transaction id. */
export function transferCounterparts(
  transactions: Transaction[],
  accounts?: Account[],
): Map<string, string | undefined> {
  const legsByTransfer = new Map<string, Array<{ id: string; account: string | undefined }>>();
  for (const transaction of transactions) {
    if (!transaction.transferId || transaction.nature !== 'TRANSFER') continue;
    const legs = legsByTransfer.get(transaction.transferId) ?? [];
    legs.push({
      id: transaction.id,
      account: accounts?.find((account) => account.id === transaction.accountId)?.label,
    });
    legsByTransfer.set(transaction.transferId, legs);
  }
  const counterparts = new Map<string, string | undefined>();
  for (const legs of legsByTransfer.values()) {
    for (const leg of legs) {
      counterparts.set(leg.id, legs.find((other) => other.id !== leg.id)?.account);
    }
  }
  return counterparts;
}
