import type { ComponentProps } from 'react';
import { FilterPanel } from '@/features/transactions/transaction-filters/filter-panel/FilterPanel';
import {
  useTransactionFacets,
  type TransactionFacetsQuery,
} from '@/features/transactions/transaction-filters/useTransactionFacets';

interface FacetedFilterPanelProps extends Omit<ComponentProps<typeof FilterPanel>, 'facets'> {
  /** False while nobody can read the counts, or the filters can match nothing: nothing is asked. */
  facetsEnabled: boolean;
  /** The list's filters the options are counted under. */
  facetsQuery: TransactionFacetsQuery;
}

/**
 * The filter panel with a count beside every option. It asks for the counts itself, so a screen
 * that shows none mounts the plain panel and never reaches the facets endpoint.
 */
export function FacetedFilterPanel({
  facetsEnabled,
  facetsQuery,
  ...panel
}: FacetedFilterPanelProps) {
  const facets = useTransactionFacets(facetsQuery, facetsEnabled);

  return <FilterPanel {...panel} facets={facets} />;
}
