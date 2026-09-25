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
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import { AccountWizard } from '@/features/accounts/account-wizard/AccountWizard';
import { ArchiveAccountDialog } from '@/features/accounts/archive-account-dialog/ArchiveAccountDialog';
import { AccountReconciliationModal } from '@/features/accounts/reconciliation-panel/AccountReconciliationModal';
import { RecordBalanceForm } from '@/features/accounts/record-balance-form/RecordBalanceForm';
import { accountErrorKind } from '@/features/accounts/accountError';

type Editor = Account | 'create' | null;

/**
 * The five editors AccountsPage can open on a row: create/edit, rule
 * overrides, record a balance, reconcile and archive. Grouped in one
 * component because they are mutually exclusive per row and share nothing but
 * the page's account list; each still owns its own modal and form.
 */
export function AccountsPageModals({
  archive,
  archiving,
  closeArchive,
  closeEditor,
  closeRecording,
  editor,
  inspecting,
  onOverrideRecorded,
  onOverrideWithdrawn,
  onReconciled,
  reconciling,
  reconcilingLive,
  recordBalance,
  recording,
  save,
  setInspecting,
  setReconciling,
}: {
  archive: UseMutationResult<unknown, unknown, Account, unknown>;
  archiving: Account | null;
  closeArchive: () => void;
  closeEditor: () => void;
  closeRecording: () => void;
  editor: Editor;
  inspecting: Account | null;
  onOverrideRecorded: () => void;
  onOverrideWithdrawn: () => void;
  onReconciled: () => void;
  reconciling: Account | null;
  reconcilingLive: Account | null;
  recordBalance: UseMutationResult<
    unknown,
    unknown,
    { account: Account; body: RecordAccountBalanceRequest },
    unknown
  >;
  recording: Account | null;
  save: UseMutationResult<unknown, unknown, CreateAccountRequest | UpdateAccountRequest, unknown>;
  setInspecting: (account: Account | null) => void;
  setReconciling: (account: Account | null) => void;
}) {
  const { t } = useTranslation();

  return (
    <>
      {editor ? (
        <Modal
          close={closeEditor}
          eyebrow={t(
            editor === 'create' ? 'accounts.form.createEyebrow' : 'accounts.form.editEyebrow',
          )}
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

      {inspecting ? (
        <AccountRulesModals
          account={inspecting}
          close={() => setInspecting(null)}
          key={inspecting.id}
          onOverrideRecorded={onOverrideRecorded}
          onOverrideWithdrawn={onOverrideWithdrawn}
        />
      ) : null}

      {recording ? (
        <Modal
          close={closeRecording}
          eyebrow={t('accounts.balances.eyebrow')}
          title={t('accounts.balances.title', { label: recording.label })}
        >
          <RecordBalanceForm
            account={recording}
            key={recording.id}
            onCancel={closeRecording}
            onSubmit={(body) => recordBalance.mutate({ account: recording, body })}
            pending={recordBalance.isPending}
            submitError={accountErrorKind(recordBalance.error, recordBalance.isError)}
            valuation={recording.valuation}
          />
        </Modal>
      ) : null}

      {reconciling ? (
        <AccountReconciliationModal
          account={reconcilingLive ?? reconciling}
          close={() => setReconciling(null)}
          key={reconciling.id}
          onReconciled={onReconciled}
        />
      ) : null}

      {archiving ? (
        <Modal
          close={closeArchive}
          eyebrow={t('accounts.archive.eyebrow')}
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
