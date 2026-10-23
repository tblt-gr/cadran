import {
  recordPortfolioOperation,
  type CreateTransferRequest,
  type Transfer,
} from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';

const relatedReads = new Set([
  'transactions',
  'account',
  'accounts',
  'account-movements',
  'monthly-ledger',
  'monthly-bounds',
  'monthly-ledger-movements',
  'monthly-recap',
  'net-worth',
  'portfolio-summary',
  'portfolio-allocation',
  'portfolio-valuation-history',
  'portfolio-performance',
  'portfolio-performance-annual',
  'portfolios',
  'portfolio-operations',
  'portfolio-transfer-candidates',
  'portfolio-cash',
]);

/** Retains the incoming leg and retry identity once the transfer has been committed. */
export function useLinkedTransferDeposit({
  onSubmit,
  onSaved,
}: {
  onSubmit: (body: CreateTransferRequest) => Promise<Transfer>;
  onSaved: () => void;
}) {
  const client = useQueryClient();
  const inFlight = useRef(false);
  const committed = useRef<{
    transfer: Transfer;
    key: string;
    createDeposit: boolean;
    depositSaved: boolean;
  } | null>(null);
  const [pending, setPending] = useState(false);
  const [transferCreated, setTransferCreated] = useState(false);
  const [depositError, setDepositError] = useState<unknown>(null);

  async function refresh() {
    // A failed read must not turn a committed write into a retry of that write.
    await Promise.allSettled([
      client.invalidateQueries({
        predicate: (query) => relatedReads.has(String(query.queryKey[0])),
      }),
    ]);
  }

  async function submit(body: CreateTransferRequest | undefined, createDeposit: boolean) {
    if (inFlight.current) return;
    inFlight.current = true;
    setPending(true);
    setDepositError(null);
    try {
      if (!committed.current) {
        if (!body) return;
        const transfer = await onSubmit(body);
        committed.current = {
          transfer,
          key: crypto.randomUUID(),
          createDeposit,
          depositSaved: false,
        };
        setTransferCreated(true);
        await refresh();
      }

      if (!committed.current.createDeposit || committed.current.depositSaved) {
        onSaved();
        return;
      }
      const { transfer, key } = committed.current;
      const target = transfer.target;
      try {
        const result = await withCsrfRetry(() =>
          recordPortfolioOperation({
            ...authApiOptions(),
            headers: { ...authApiOptions().headers, 'Idempotency-Key': key },
            path: { accountId: target.accountId },
            body: {
              type: 'DEPOSIT',
              tradeDate: target.bookedOn,
              grossAmount: {
                value: target.amount.value.replace(/^-/, ''),
                assetCode: target.amount.assetCode,
              },
              linkedTransactionId: target.id,
            },
          }),
        );
        if (!result.response?.ok || !result.data) throw operationRequestError(result);
        committed.current.depositSaved = true;
      } catch (error) {
        setDepositError(error);
        await refresh();
        return;
      }
      await refresh();
      onSaved();
    } catch {
      // The transfer mutation owns its error presentation; no deposit has been attempted.
    } finally {
      inFlight.current = false;
      setPending(false);
    }
  }

  return { depositError, pending, submit, transferCreated };
}
