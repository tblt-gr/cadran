import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useId, useState } from 'react';
import { accountErrorKind, type AccountErrorKind } from '@/features/accounts/accountError';
import { useWithdrawBalance } from './useBalanceSnapshots';

export interface BalanceWithdrawal {
  /** The snapshot awaiting its inline confirmation, if it is still active. */
  confirmingId: string | null;
  hintId: string;
  pending: boolean;
  error: AccountErrorKind | null;
  ask: (snapshot: AccountBalanceSnapshot) => void;
  cancel: () => void;
  confirm: (snapshot: AccountBalanceSnapshot) => void;
}

/**
 * The withdrawal flow shared by every list of balance snapshots: ask, confirm in the row, report a
 * stale or refused withdrawal. A row withdrawn elsewhere no longer carries a confirmation.
 */
export function useBalanceWithdrawal(
  accountId: string,
  items: readonly AccountBalanceSnapshot[] | null,
): BalanceWithdrawal {
  const withdraw = useWithdrawBalance(accountId);
  const [askedId, setAskedId] = useState<string | null>(null);
  const hintId = useId();
  const confirmingId = items?.find((item) => item.id === askedId && item.active)?.id ?? null;

  return {
    confirmingId,
    hintId,
    pending: withdraw.isPending,
    error: accountErrorKind(withdraw.error, withdraw.isError),
    ask: (snapshot) => {
      withdraw.reset();
      setAskedId(snapshot.id);
    },
    cancel: () => {
      withdraw.reset();
      setAskedId(null);
    },
    confirm: (snapshot) => withdraw.mutate(snapshot, { onSuccess: () => setAskedId(null) }),
  };
}
