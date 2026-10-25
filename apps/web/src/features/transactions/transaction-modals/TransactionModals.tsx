import type {
  Account,
  CreateTransactionRequest,
  CreateTransferRequest,
  Transaction,
  Transfer,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import type { UseMutationResult } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { RefundEditor } from '@/features/transactions/refund-editor/RefundEditor';
import { TransactionEditor } from '@/features/transactions/transaction-editor/TransactionEditor';
import type { Editor } from '@/features/transactions/transactionEditorState';
import {
  transactionErrorDetail,
  transactionErrorKind,
  type TransactionErrorKind,
} from '@/features/transactions/transactionError';
import { TransferEditor } from '@/features/transactions/transfer-editor/TransferEditor';
import { VoidTransactionDialog } from '@/features/transactions/void-transaction-dialog/VoidTransactionDialog';

interface TransactionModalsProps {
  accountOptions: Account[];
  activeAccountOptions: Account[];
  defaultAccountId?: string;
  closeEditor: () => void;
  closeTransferEditor: () => void;
  closeVoiding: () => void;
  editor: Editor;
  onRefundClosed: () => void;
  onRefundSaved: () => Promise<void>;
  refundTarget: Transaction | null;
  save: UseMutationResult<
    Transaction,
    unknown,
    CreateTransactionRequest | UpdateTransactionRequest
  >;
  submitError: TransactionErrorKind | null;
  transferEditorOpen: boolean;
  onTransferSaved: () => void;
  transferSave: UseMutationResult<Transfer, unknown, CreateTransferRequest>;
  voidError: TransactionErrorKind | null;
  voidMutation: UseMutationResult<Transaction, unknown, Transaction>;
  voiding: Transaction | null;
}

/**
 * The transactions screen's four entity-editing overlays: the transfer, transaction and
 * refund editors, and the void confirmation. Grouped here so the page itself only decides
 * *whether* each one is open, never how it renders or which mutation it drives.
 */
export function TransactionModals({
  accountOptions,
  activeAccountOptions,
  defaultAccountId,
  closeEditor,
  closeTransferEditor,
  closeVoiding,
  editor,
  onRefundClosed,
  onRefundSaved,
  refundTarget,
  save,
  submitError,
  transferEditorOpen,
  transferSave,
  onTransferSaved,
  voidError,
  voidMutation,
  voiding,
}: TransactionModalsProps) {
  const { t } = useTranslation();

  return (
    <>
      {transferEditorOpen ? (
        <TransferEditor
          accounts={activeAccountOptions}
          close={closeTransferEditor}
          onSubmit={(body) => transferSave.mutateAsync(body)}
          onSaved={onTransferSaved}
          pending={transferSave.isPending}
          submitError={transactionErrorKind(transferSave.error, transferSave.isError)}
        />
      ) : null}

      {editor ? (
        <TransactionEditor
          accounts={editor === 'create' ? activeAccountOptions : accountOptions}
          defaultAccountId={defaultAccountId}
          close={closeEditor}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          submitError={submitError}
          submitErrorDetail={transactionErrorDetail(save.error)}
          transaction={editor === 'create' ? undefined : editor}
        />
      ) : null}

      {refundTarget ? (
        <RefundEditor
          accounts={activeAccountOptions}
          close={onRefundClosed}
          onSaved={onRefundSaved}
          transaction={refundTarget}
        />
      ) : null}

      {voiding ? (
        <Modal
          close={closeVoiding}
          eyebrow={t('transactions.void.eyebrow')}
          title={t('transactions.void.title')}
        >
          <VoidTransactionDialog
            onConfirm={() => voidMutation.mutate(voiding)}
            pending={voidMutation.isPending}
            submitError={voidError}
            submitErrorDetail={transactionErrorDetail(voidMutation.error)}
            transaction={voiding}
          />
        </Modal>
      ) : null}
    </>
  );
}
