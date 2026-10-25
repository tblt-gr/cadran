import type { TransactionFormDefaults } from '@/features/transactions/transaction-form/TransactionForm';
import type { BudgetCreationDraft } from './BudgetCreationModals';

export function budgetTransactionDefaults(
  draft: Exclude<BudgetCreationDraft, { kind: 'account' } | null>,
  bookedOn: string,
): TransactionFormDefaults {
  return {
    bookedOn,
    category: {
      color: draft.row?.color ?? null,
      icon: draft.row?.icon ?? null,
      id: draft.row?.id ?? '',
      label: draft.row?.label ?? '',
      type: draft.kind === 'income' ? 'INCOME' : 'EXPENSE',
    },
    nature: draft.kind === 'income' ? 'INCOME' : 'EXPENSE',
  };
}
