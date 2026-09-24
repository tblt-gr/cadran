import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { ReconciliationPanel } from './ReconciliationPanel';

interface AccountReconciliationModalProps {
  account: Account;
  close: () => void;
  onReconciled: () => void;
}

/**
 * The reconciliation modal for one account, shared by the account list and
 * the account detail page so both open the same panel in the same frame
 * rather than keeping two copies of the wiring around it.
 */
export function AccountReconciliationModal({
  account,
  close,
  onReconciled,
}: AccountReconciliationModalProps) {
  const { t } = useTranslation();

  return (
    <Modal
      close={close}
      eyebrow={t('accounts.reconciliation.eyebrow')}
      title={t('accounts.reconciliation.title', { label: account.label })}
    >
      <ReconciliationPanel account={account} onReconciled={onReconciled} />
    </Modal>
  );
}
