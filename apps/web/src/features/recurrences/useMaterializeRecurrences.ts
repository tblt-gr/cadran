import { materializeRecurrences } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';

/** Everything a created or booked transaction moves: lists, balances, ledgers and reports. */
const LEDGER_QUERY_FAMILIES = [
  'transactions',
  'accounts',
  'account',
  'account-balances',
  'account-movements',
  'net-worth',
  'net-worth-history',
  'monthly-ledger',
  'monthly-ledger-movements',
  'monthly-bounds',
  'monthly-recap',
  'monthly-report',
  'period-status',
  'budget-comparisons',
  'annual-report',
  'multi-year-report',
  'recurrences',
  'recurrence-occurrences',
];

/**
 * Asks the server once per application load to create the current month's transactions of the
 * automatic recurrences. The use case is idempotent, so a repeat is harmless; a failure is silent
 * and non-blocking because the next load retries.
 */
export function useMaterializeRecurrences(): void {
  const queryClient = useQueryClient();
  // A ref survives the development double effect, which a module flag would not survive in tests.
  const requested = useRef(false);

  useEffect(() => {
    if (requested.current) return;
    requested.current = true;

    void (async () => {
      try {
        const result = await withCsrfRetry(() =>
          materializeRecurrences({ ...authApiOptions(), body: {} }),
        );
        if (!result.response?.ok || !result.data) return;
        if (result.data.transactionsCreated + result.data.transactionsBooked === 0) return;

        await Promise.all(
          LEDGER_QUERY_FAMILIES.map((key) => queryClient.invalidateQueries({ queryKey: [key] })),
        );
      } catch {
        // Silent by design: nothing is lost, the next load runs the same idempotent call.
      }
    })();
  }, [queryClient]);
}
