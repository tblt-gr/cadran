import type {
  Account,
  CreateAccountRequest,
  RecordAccountBalanceRequest,
  UpdateAccountRequest,
} from '@cadran/api-client';
import type { UseMutationResult } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { AccountEditor } from '@/features/accounts/account-editor/AccountEditor';
import { AccountWizard } from '@/features/accounts/account-wizard/AccountWizard';
import { ArchiveAccountDialog } from '@/features/accounts/archive-account-dialog/ArchiveAccountDialog';
import { RecordBalanceForm } from '@/features/accounts/record-balance-form/RecordBalanceForm';
import { accountErrorKind } from '@/features/accounts/accountError';

type Editor = Account | 'create' | null;

/**
 * The create/edit, record-balance and archive modals of one account, shared by the accounts
 * list and the header menu of the account and portfolio pages.
 */
export function AccountActionsModals({
  archive,
  archiving,
  closeArchive,
  closeEditor,
  closeRecording,
  editor,
  onBalanceCorrected,
  recordBalance,
  recording,
  save,
}: {
  archive: UseMutationResult<unknown, unknown, Account, unknown>;
  archiving: Account | null;
  closeArchive: () => void;
  closeEditor: () => void;
  closeRecording: () => void;
  editor: Editor;
  onBalanceCorrected: () => void;
  recordBalance: UseMutationResult<
    unknown,
    unknown,
    { account: Account; body: RecordAccountBalanceRequest },
    unknown
  >;
  recording: Account | null;
  save: UseMutationResult<unknown, unknown, CreateAccountRequest | UpdateAccountRequest, unknown>;
}) {
  const { t } = useTranslation();

  return (
    <>
      {editor ? (
        <Modal
          intent={editor === 'create' ? 'create' : 'edit'}
          close={closeEditor}

          title={t(editor === 'create' ? 'accounts.form.createTitle' : 'accounts.form.editTitle')}
        >
          {editor === 'create' ? (
            <AccountWizard
              onCancel={closeEditor}
              onCreate={(body) => save.mutate(body)}
              pending={save.isPending}
              submitError={accountErrorKind(save.error, save.isError)}
            />
          ) : (
            <AccountEditor
              account={editor}
              key={editor.id}
              onCancel={closeEditor}
              onSubmit={(body) => save.mutate(body)}
              pending={save.isPending}
              submitError={accountErrorKind(save.error, save.isError)}
            />
          )}
        </Modal>
      ) : null}

      {recording ? (
        <Modal
          intent="create"
          close={closeRecording}
          title={t('accounts.balances.title', { label: recording.label })}
        >
          <RecordBalanceForm
            account={recording}
            key={recording.id}
            onCancel={closeRecording}
            onCorrected={onBalanceCorrected}
            onSubmit={(body) => recordBalance.mutate({ account: recording, body })}
            pending={recordBalance.isPending}
            submitError={accountErrorKind(recordBalance.error, recordBalance.isError)}
            valuation={recording.valuation}
          />
        </Modal>
      ) : null}

      {archiving ? (
        <Modal
          intent="archive"
          close={closeArchive}

          title={t('accounts.archive.title')}
        >
          <ArchiveAccountDialog
            account={archiving}
            onCancel={closeArchive}
            onConfirm={() => archive.mutate(archiving)}
            pending={archive.isPending}
            submitError={accountErrorKind(archive.error, archive.isError)}
          />
        </Modal>
      ) : null}
    </>
  );
}
