import type { CreateTransferRequest, Transfer } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';

/** Reads a transfer can change: the server also moves portfolio cash and valuation. */
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

/** Submits once at a time, then refreshes every read the transfer, and the deposit the server derives from it, touch. */
export function useTransferSubmit({
  onSubmit,
  onSaved,
}: {
  onSubmit: (body: CreateTransferRequest) => Promise<Transfer>;
  onSaved: () => void;
}) {
  const client = useQueryClient();
  const inFlight = useRef(false);
  const [pending, setPending] = useState(false);

  async function submit(body: CreateTransferRequest) {
    if (inFlight.current) return;
    inFlight.current = true;
    setPending(true);
    try {
      await onSubmit(body);
      // A failed read must not turn a committed write into a retry of that write.
      await Promise.allSettled([
        client.invalidateQueries({
          predicate: (query) => relatedReads.has(String(query.queryKey[0])),
        }),
      ]);
      onSaved();
    } catch {
      // The transfer mutation owns its error presentation.
    } finally {
      inFlight.current = false;
      setPending(false);
    }
  }

  return { pending, submit };
}
