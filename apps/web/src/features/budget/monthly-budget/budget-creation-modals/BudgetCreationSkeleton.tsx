import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { TransactionForm } from '@/features/transactions/transaction-form/TransactionForm';
import { TransferForm } from '@/features/transactions/transfer-editor/TransferForm';
import type { BudgetCreationDraft } from './BudgetCreationModals';
import { budgetTransactionDefaults } from './budgetCreationDefaults';
import styles from './BudgetCreationSkeleton.module.css';

const noop = () => undefined;

/** The real forms keep the known draft visible while their account options resolve. */
export function BudgetCreationSkeleton({
  bookedOn,
  close,
  draft,
}: {
  bookedOn: string;
  close: () => void;
  draft: Exclude<BudgetCreationDraft, null>;
}) {
  const { t } = useTranslation();
  const transfer = draft.kind === 'account';

  return (
    <Modal
      close={close}
      eyebrow={t(transfer ? 'transactions.transfer.eyebrow' : 'transactions.form.createEyebrow')}
      title={t(transfer ? 'transactions.transfer.title' : 'transactions.form.createTitle')}
    >
      <div aria-busy="true" className={styles.frozen} data-testid="budget-creation-skeleton">
        <SkeletonStatus>{t('budget.monthly.accountsLoading')}</SkeletonStatus>
        <fieldset aria-hidden="true" className={styles.frozen} disabled inert>
          {draft.kind === 'account' ? (
            <TransferForm
              accounts={[]}
              defaults={{ bookedOn, requireSourceChoice: true, targetAccountId: draft.row.id }}
              depositError={null}
              onRetryDeposit={noop}
              onSubmit={noop}
              pending={false}
              submitError={null}
              transferCreated={false}
            />
          ) : (
            <TransactionForm
              accounts={[]}
              defaults={budgetTransactionDefaults(draft, bookedOn)}
              onSubmit={noop}
              pending={false}
              submitError={null}
            />
          )}
        </fieldset>
      </div>
    </Modal>
  );
}
