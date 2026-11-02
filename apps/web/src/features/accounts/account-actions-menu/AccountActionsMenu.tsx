import type { Account } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import type { IconName } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { AccountActionsModals } from './AccountActionsModals';
import { useAccountsPageMutations } from '@/features/accounts/useAccountsPageMutations';
import { navigateFromPage } from '@/hooks/use-client-navigation';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';

const INSTRUMENTS_HREF = '/portfolios/instruments';

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
  onAddTransfer,
  onReconcile,
  onRecordValuation,
  onRules,
}: {
  account?: Account;
  onAddTransfer?: () => void;
  onReconcile?: () => void;
  onRecordValuation?: () => void;
  onRules: () => void;
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

  const portfolio = valuedByPortfolio(account);
  const writable = account.editable && account.status === 'ACTIVE';
  const lockedReason = writable
    ? undefined
    : t(
        `accounts.detail.actionsMenu.reasons.${account.status === 'ACTIVE' ? 'READ_ONLY' : account.status}`,
      );
  const nothingToReconcile =
    account.valuation.snapshotId === null ||
    account.valuation.reconciliationStatus !== 'UNRECONCILED';

  function item(id: string, icon: IconName, text: string, onSelect: () => void, reason?: string) {
    return {
      disabled: reason !== undefined,
      hint: reason,
      icon,
      id,
      label:
        reason === undefined
          ? text
          : t('accounts.detail.actionsMenu.unavailable', { action: text, reason }),
      onSelect,
      text,
    };
  }

  const rules = item('rules', 'sliders', t('accounts.detail.actionsMenu.rules'), onRules);
  const edit = item(
    'edit',
    'edit',
    t('accounts.detail.actionsMenu.edit'),
    () => setEditing(account),
    lockedReason,
  );
  const archiveItem = item(
    'archive',
    'archive',
    t('accounts.detail.actionsMenu.archive'),
    () => setArchiving(account),
    lockedReason,
  );
  const items = portfolio
    ? [
        item(
          'valuation',
          'balance',
          t('accounts.detail.actionsMenu.recordValuation'),
          () => onRecordValuation?.(),
          lockedReason,
        ),
        rules,
        item('instruments', 'chart-line', t('accounts.detail.actionsMenu.instruments'), () => {
          navigateFromPage(INSTRUMENTS_HREF);
        }),
        edit,
        archiveItem,
      ]
    : [
        item(
          'transfer',
          'transfer',
          t('accounts.detail.actionsMenu.addTransfer'),
          () => onAddTransfer?.(),
          lockedReason,
        ),
        item(
          'recordBalance',
          'balance',
          t('accounts.detail.actionsMenu.recordBalance'),
          () => setRecording(account),
          lockedReason,
        ),
        item(
          'reconcile',
          'check-circle',
          t('accounts.detail.actionsMenu.reconcile'),
          () => onReconcile?.(),
          lockedReason ??
            (nothingToReconcile
              ? t('accounts.detail.actionsMenu.reasons.NOTHING_TO_RECONCILE')
              : undefined),
        ),
        rules,
        edit,
        archiveItem,
      ];

  return (
    <>
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
