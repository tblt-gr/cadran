import type { Account } from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { AccountIdentityCard } from '@/features/accounts/account-detail/account-identity/AccountIdentityCard';
import {
  AccountLedger,
  type LedgerEditor,
} from '@/features/accounts/account-detail/account-ledger/AccountLedger';
import { AccountRulesModals } from '@/features/accounts/account-rules/AccountRulesModals';
import styles from './AccountTab.module.css';

type Saved = 'transactionSaved' | 'transferSaved' | 'claimed' | 'withdrawn' | null;

/**
 * The account behind a portfolio, on the portfolio page itself: identity, rules and bank
 * movements, with a new transfer received by this account. A life insurance puts its contract
 * (`children`) first.
 */
export function AccountTab({
  account,
  today,
  children,
}: {
  account: Account;
  today: string;
  children?: ReactNode;
}) {
  const { t } = useTranslation();
  const [editor, setEditor] = useState<LedgerEditor>(null);
  const [rulesOpen, setRulesOpen] = useState(false);
  const [saved, setSaved] = useState<Saved>(null);
  const creatable = account.status === 'ACTIVE';

  return (
    <div className={styles.tab}>
      {children}
      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>{t(`accounts.detail.toasts.${saved}`)}</Toast>
      ) : null}
      <div className={styles.actions}>
        <button className="secondary-action" onClick={() => setRulesOpen(true)} type="button">
          <Icon name="sliders" size={18} />
          {t('accounts.detail.actions.rules')}
        </button>
        <button
          className="secondary-action"
          disabled={!creatable}
          onClick={() => {
            setSaved(null);
            setEditor('transfer');
          }}
          type="button"
        >
          <Icon name="transfer" size={18} />
          {t('accounts.detail.actions.addTransfer')}
        </button>
      </div>
      <AccountIdentityCard account={account} />
      <AccountLedger
        account={account}
        creatable={creatable}
        editor={editor}
        onEditorChange={setEditor}
        onSaved={setSaved}
        transferDefaults={{
          bookedOn: today,
          requireSourceChoice: true,
          targetAccountId: account.id,
        }}
      />
      {rulesOpen ? (
        <AccountRulesModals
          account={account}
          close={() => setRulesOpen(false)}
          onOverrideRecorded={() => setSaved('claimed')}
          onOverrideWithdrawn={() => setSaved('withdrawn')}
        />
      ) : null}
    </div>
  );
}
