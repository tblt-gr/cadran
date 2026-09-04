import type { Account, CreateAccountRequest, UpdateAccountRequest } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { AccountOrigin } from '@/features/accounts/account-form/accountOrigin';
import { AccountForm } from '@/features/accounts/account-form/AccountForm';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { useAccountProduct } from './useAccountProduct';
import { useAccountProductModel } from './useAccountProductModel';
import styles from './AccountEditor.module.css';

interface AccountEditorProps {
  account: Account;
  onCancel: () => void;
  onSubmit: (body: CreateAccountRequest | UpdateAccountRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Editing an existing account, with the catalogue product or workspace
 * template it follows resolved first.
 *
 * That origin is what decides which kind and which valuation modes the API
 * will accept, so the form waits for it instead of offering a choice that
 * would come back as a refusal. A product's rules are read on the account's
 * opening date, so it is edited against the catalogue that covered it, not
 * against today's; a template carries no such dated resolution and is read
 * as it stands.
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
  const template = useAccountProductModel(account.productModelId);
  const isPending = catalogue.isPending || template.isPending;
  const isError = catalogue.isError || template.isError;
  const origin: AccountOrigin = catalogue.product
    ? { type: 'product', product: catalogue.product }
    : template.template
      ? { type: 'template', template: template.template }
      : null;

  if (isPending) {
    return (
      <p aria-busy="true" className={styles.state} role="status">
        {t(account.productModelId ? 'accounts.editor.templateLoading' : 'accounts.editor.loading')}
      </p>
    );
  }

  return (
    <>
      {isError ? (
        // The account keeps its reference either way; only the fields the
        // product or the template constrains are unavailable, and the API
        // still refuses an incompatible edit.
        <p className={styles.warning} role="alert">
          {t(
            account.productModelId
              ? 'accounts.editor.templateUnavailable'
              : 'accounts.editor.productUnavailable',
          )}
        </p>
      ) : null}
      <AccountForm
        account={account}
        key={account.id}
        onCancel={onCancel}
        onSubmit={onSubmit}
        origin={origin}
        pending={pending}
        submitError={submitError}
      />
    </>
  );
}
