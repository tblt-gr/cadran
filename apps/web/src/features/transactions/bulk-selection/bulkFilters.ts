import type { BulkTransactionFilters, CountTransactionsData } from '@cadran/api-client';

export type TransactionCountQuery = NonNullable<CountTransactionsData['query']>;

/**
 * The listing's query-string filters in the body form the bulk endpoint takes. Paging
 * parameters are not filters and never reach it.
 */
export function toBulkFilters(query: TransactionCountQuery): BulkTransactionFilters {
  const filters: BulkTransactionFilters = {
    from: query.from,
    to: query.to,
    accountIds: query.accountId,
    states: query.state,
    natures: query.nature,
    categoryIds: query.categoryId,
    includeDescendants: query.includeDescendants,
    axes: query.axis,
    minAmount: query.minAmount,
    maxAmount: query.maxAmount,
    assetCode: query.assetCode,
    sources: query.source,
    categorization: query.categorization,
    q: query.q,
    includeVoided: query.includeVoided,
  };

  return Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== undefined),
  ) as BulkTransactionFilters;
}
