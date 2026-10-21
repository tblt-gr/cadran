import type { Account } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountsPageModals } from './accounts-page-modals/AccountsPageModals';
import { AccountFilters } from './account-filters/AccountFilters';
import { AccountOverview } from './account-overview/AccountOverview';
import { useLiveAccount } from './useLiveAccount';
import { useAccountsPageMutations } from './useAccountsPageMutations';
import styles from './AccountsPage.module.css';

type Editor = Account | 'create' | null;

export function AccountsPage() {
  const { t } = useTranslation();
  const [includeClosed, setIncludeClosed] = useState(false);
  const [includeArchived, setIncludeArchived] = useState(false);
  const [editor, setEditor] = useState<Editor>(null);
  const [archiving, setArchiving] = useState<Account | null>(null);
  const [inspecting, setInspecting] = useState<Account | null>(null);
  const [recording, setRecording] = useState<Account | null>(null);
  const [reconciling, setReconciling] = useState<Account | null>(null);
  const [saved, setSaved] = useState<
    'saved' | 'archived' | 'claimed' | 'withdrawn' | 'recorded' | 'reconciled' | null
  >(null);

  const { save, archive, recordBalance } = useAccountsPageMutations({
    editor,
    closeEditor: () => setEditor(null),
    closeArchive: () => setArchiving(null),
    closeRecording: () => setRecording(null),
    notify: setSaved,
  });

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function closeArchive() {
    setArchiving(null);
    archive.reset();
  }

  function closeRecording() {
    setRecording(null);
    recordBalance.reset();
  }

  function openRecording(account: Account) {
    setSaved(null);
    recordBalance.reset();
    setRecording(account);
  }

  function openReconciling(account: Account) {
    setSaved(null);
    setReconciling(account);
  }

  function openEditor(target: Exclude<Editor, null>) {
    setSaved(null);
    save.reset();
    setEditor(target);
  }

  function openArchive(account: Account) {
    setSaved(null);
    archive.reset();
    setArchiving(account);
  }

  // The modal must read the live row: a stale version would fail every retry.
  const reconcilingLive = useLiveAccount(reconciling);
  // Withdrawing a snapshot from the modal changes the version the form must present.
  const recordingLive = useLiveAccount(recording);

  return (
    <div className={styles.page}>
      <HeaderActions>
        <button
          aria-label={t('accounts.add')}
          className={`primary-action ${styles.add}`}
          onClick={() => openEditor('create')}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>{t('accounts.add')}</span>
        </button>
        <a
          aria-label={t('accounts.manageGroups')}
          className="icon-button"
          href="/accounts/groups"
          onClick={(event) => handleClientNavigation(event, '/accounts/groups')}
          title={t('accounts.manageGroups')}
        >
          <Icon name="layers" />
        </a>
      </HeaderActions>

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accounts.toasts.${saved}`)}</Toast>
      ) : null}

      <AccountsPageModals
        archive={archive}
        archiving={archiving}
        closeArchive={closeArchive}
        closeEditor={closeEditor}
        closeRecording={closeRecording}
        editor={editor}
        inspecting={inspecting}
        onOverrideRecorded={() => setSaved('claimed')}
        onOverrideWithdrawn={() => setSaved('withdrawn')}
        onReconciled={() => {
          setReconciling(null);
          setSaved('reconciled');
        }}
        reconciling={reconciling}
        reconcilingLive={reconcilingLive}
        recordBalance={recordBalance}
        recording={recordingLive}
        save={save}
        setInspecting={setInspecting}
        setReconciling={setReconciling}
      />

      <AccountFilters
        includeArchived={includeArchived}
        includeClosed={includeClosed}
        onIncludeArchivedChange={setIncludeArchived}
        onIncludeClosedChange={setIncludeClosed}
      />

      <AccountOverview
        includeArchived={includeArchived}
        includeClosed={includeClosed}
        onArchive={openArchive}
        onCreate={() => openEditor('create')}
        onEdit={openEditor}
        onReconcile={openReconciling}
        onRecordBalance={openRecording}
        onResetFilters={() => {
          setIncludeClosed(false);
          setIncludeArchived(false);
        }}
        onRules={setInspecting}
      />
    </div>
  );
}
