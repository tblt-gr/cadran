import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import { balanceOf } from './balancesAfterTransfer';
import styles from './TransferAccountField.module.css';

interface TransferAccountFieldProps {
  accounts: Account[];
  /** The balance this account would have once the transfer is booked, when it can be calculated. */
  after: { value: string; assetCode: string } | null;
  autoFocus: boolean;
  disabled: boolean;
  error?: boolean;
  errorId: string;
  errorLabel: string;
  label: string;
  value: string;
  onChange: (accountId: string) => void;
}

/** One side of the transfer: an account select that names the balance it ends with. */
export function TransferAccountField({
  accounts,
  after,
  autoFocus,
  disabled,
  error,
  errorId,
  errorLabel,
  label,
  value,
  onChange,
}: TransferAccountFieldProps) {
  const { i18n, t } = useTranslation();
  const money = (amount: { value: string; assetCode: string }) =>
    formatAmount(amount.value, amount.assetCode, i18n.language);
  const optionLabel = (account: Account) => {
    const balance = balanceOf(account);
    return balance === null ? account.label : `${account.label} · ${money(balance)}`;
  };

  return (
    <label>
      <span>{label}</span>
      <select
        disabled={disabled}
        aria-describedby={error ? errorId : undefined}
        aria-invalid={error ? true : undefined}
        data-autofocus={autoFocus ? true : undefined}
        onChange={(event) => onChange(event.target.value)}
        value={value}
      >
        <option value="">{t('transactions.transfer.fields.selectAccount')}</option>
        {accounts.map((account) => (
          <option key={account.id} value={account.id}>
            {optionLabel(account)}
          </option>
        ))}
      </select>
      {after ? (
        <small className={styles.after}>
          {t('transactions.transfer.balanceAfter', { amount: money(after) })}
        </small>
      ) : null}
      {error ? <small id={errorId}>{errorLabel}</small> : null}
    </label>
  );
}
