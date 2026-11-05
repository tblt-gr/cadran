import type { TransactionFacets } from '@cadran/api-client';
import type { TransactionFacetsState } from '@/features/transactions/transaction-filters/useTransactionFacets';

/**
 * The counts of one filter section, by option value. A missing value means no transaction holds
 * it. `null` while the first answer is on its way.
 */
export type OptionCounts = Readonly<Record<string, number>> | null;

/**
 * The count to show beside one option: `undefined` where the list shows none, `null` while it is
 * pending, otherwise the number of transactions the option would keep. An option the answer
 * omits holds none, so it reads as zero rather than as unknown.
 */
export function optionCount(
  counts: OptionCounts | undefined,
  value: string,
): number | null | undefined {
  if (counts === undefined || counts === null) {
    return counts;
  }

  return Object.hasOwn(counts, value) ? counts[value] : 0;
}

/**
 * What the facets say to the list of one section: its counts, `null` while they are pending, and
 * `undefined` where the list shows none (a screen that asks for none, a failed request).
 */
export function sectionCounts(
  facets: TransactionFacetsState | undefined,
  section: keyof TransactionFacets,
): OptionCounts | undefined {
  if (facets === undefined) {
    return undefined;
  }

  return facets.pending ? null : facets.counts?.[section];
}
