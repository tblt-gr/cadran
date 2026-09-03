import type { Account, CreateAccountRequest, UpdateAccountRequest } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountForm } from '@/features/accounts/account-form/AccountForm';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { useAccountProduct } from './useAccountProduct';
import styles from './AccountEditor.module.css';

interface AccountEditorProps {
  account: Account;
  onCancel: () => void;
  onSubmit: (body: CreateAccountRequest | UpdateAccountRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Editing an existing account, with the catalogue model it follows resolved
 * first.
 *
 * The product is what decides which kind and which valuation modes the API will
 * accept, so the form waits for it instead of offering a choice that would come
 * back as a refusal. The rules are read on the account's opening date: a
 * product-backed account is edited against the catalogue that covered it, not
 * against today's.
 */
export function AccountEditor({
  account,
  onCancel,
  onSubmit,
  pending,
  submitError,
}: AccountEditorProps) {
  const { t } = useTranslation();
  const catalogue = useAccountProduct(account.productCode, account.openedOn);

  if (catalogue.isPending) {
    return (
      <p aria-busy="true" className={styles.state} role="status">
        {t('accounts.editor.loading')}
      </p>
    );
  }

  return (
    <>
      {catalogue.isError ? (
        // The account keeps its product reference either way; only the fields
        // the catalogue constrains are unavailable, and the API still refuses
        // an incompatible edit.
        <p className={styles.warning} role="alert">
          {t('accounts.editor.productUnavailable')}
        </p>
      ) : null}
      <AccountForm
        account={account}
        key={account.id}
        onCancel={onCancel}
        onSubmit={onSubmit}
        pending={pending}
        product={catalogue.product}
        submitError={submitError}
      />
    </>
  );
}
