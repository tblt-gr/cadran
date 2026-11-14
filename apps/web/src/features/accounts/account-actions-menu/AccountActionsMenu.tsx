import type { Account } from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { Toast } from '@/components/ui/toast/Toast';
import { AccountActionsModals } from './AccountActionsModals';
import { useAccountsPageMutations } from '@/features/accounts/useAccountsPageMutations';
import { accountMenuItems } from './accountMenuItems';

/**
 * The single three-dot menu at the end of the shell header of an account or portfolio page.
 * It offers every action valid for the account type (derived from the valuation mode the API
 * returns) and only mirrors the server's refusals: a state that cannot accept the action keeps
 * the item visible and disabled, with the reason in its accessible name. Edit, archive and the
 * balance record reuse the modals of the accounts list. Without an account (loading) only a
 * disabled trigger is rendered.
 */
export function AccountActionsMenu({
  account,
  onAddOperation,
  onAddTransfer,
  onReconcile,
  onRecordValuation,
  onTakeover,
  onRules,
  leading,
}: {
  account?: Account;
  onAddOperation?: () => void;
  onAddTransfer?: () => void;
  onReconcile?: () => void;
  onRecordValuation?: () => void;
  onTakeover?: () => void;
  onRules: () => void;
  /**
   * Buttons shown before the menu. When given, the page owns the balance record: they receive its
   * trigger and the menu no longer lists it.
   */
  leading?: (actions: { recordBalance: () => void }) => ReactNode;
}) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState<Account | null>(null);
  const [archiving, setArchiving] = useState<Account | null>(null);
  const [recording, setRecording] = useState<Account | null>(null);
  const [saved, setSaved] = useState<'saved' | 'archived' | 'recorded' | null>(null);
  const { save, archive, recordBalance } = useAccountsPageMutations({
    editor: editing,
    closeEditor: () => setEditing(null),
    closeArchive: () => setArchiving(null),
    closeRecording: () => setRecording(null),
    notify: setSaved,
  });

  if (account === undefined) {
    return <ActionMenu disabled items={[]} label={t('accounts.detail.actionsMenu.openPending')} />;
  }

  const items = accountMenuItems({
    account,
    handlers: {
      onAddTransfer,
      onAddOperation,
      onArchive: () => setArchiving(account),
      onEdit: () => setEditing(account),
      onReconcile,
      onRecordBalance: leading ? undefined : () => setRecording(account),
      onRecordValuation,
      onTakeover,
      onRules,
    },
    t,
  });

  return (
    <>
      {leading?.({ recordBalance: () => setRecording(account) })}
      <ActionMenu
        items={items}
        label={t('accounts.detail.actionsMenu.open', { label: account.label })}
        triggerVariant="framed"
      />
      <AccountActionsModals
        archive={archive}
        archiving={archiving}
        closeArchive={() => {
          setArchiving(null);
          archive.reset();
        }}
        closeEditor={() => {
          setEditing(null);
          save.reset();
        }}
        closeRecording={() => {
          setRecording(null);
          recordBalance.reset();
        }}
        editor={editing}
        onBalanceCorrected={() => {
          setRecording(null);
          recordBalance.reset();
          setSaved('recorded');
        }}
        recordBalance={recordBalance}
        recording={recording}
        save={save}
      />
      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accounts.toasts.${saved}`)}</Toast>
      ) : null}
    </>
  );
}
