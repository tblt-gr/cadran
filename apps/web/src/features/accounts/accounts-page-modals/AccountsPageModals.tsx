import type {
  Account,
  CreateAccountRequest,
  RecordAccountBalanceRequest,
  UpdateAccountRequest,
} from '@cadran/api-client';
import type { UseMutationResult } from '@tanstack/react-query';
import { AccountActionsModals } from '@/features/accounts/account-actions-menu/AccountActionsModals';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import { AccountReconciliationModal } from '@/features/accounts/reconciliation-panel/AccountReconciliationModal';

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
  onBalanceCorrected,
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
  onBalanceCorrected: () => void;
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
  return (
    <>
      <AccountActionsModals
        archive={archive}
        archiving={archiving}
        closeArchive={closeArchive}
        closeEditor={closeEditor}
        closeRecording={closeRecording}
        editor={editor}
        onBalanceCorrected={onBalanceCorrected}
        recordBalance={recordBalance}
        recording={recording}
        save={save}
      />

      {inspecting ? (
        <AccountRulesModals
          account={inspecting}
          close={() => setInspecting(null)}
          key={inspecting.id}
          onOverrideRecorded={onOverrideRecorded}
          onOverrideWithdrawn={onOverrideWithdrawn}
        />
      ) : null}

      {reconciling ? (
        <AccountReconciliationModal
          account={reconcilingLive ?? reconciling}
          close={() => setReconciling(null)}
          key={reconciling.id}
          onReconciled={onReconciled}
        />
      ) : null}
    </>
  );
}
