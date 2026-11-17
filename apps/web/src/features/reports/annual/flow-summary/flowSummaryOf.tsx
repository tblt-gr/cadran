import type { AnnualColumn } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { FlowSummary } from './FlowSummary';

const INCOME_COLUMN_ID = 'cashIncome';
const EXPENSES_COLUMN_ID = 'budgetExpenses';

/**
 * Builds the folded summary of a card from the selected columns: income and expenses only appear
 * when the reader kept those columns. Without either, the card has no summary.
 */
export function flowSummaryOf(
  columns: readonly AnnualColumn[],
  render: (column: AnnualColumn) => ReactNode,
): ReactNode {
  const income = columns.find((column) => column.id === INCOME_COLUMN_ID);
  const expenses = columns.find((column) => column.id === EXPENSES_COLUMN_ID);
  if (!income && !expenses) return null;
  return (
    <FlowSummary
      expenses={expenses ? render(expenses) : null}
      income={income ? render(income) : null}
    />
  );
}
