import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { InsufficientBalanceMessage } from '@/features/transactions/insufficient-balance/InsufficientBalanceMessage';
import styles from './AccountSubmitError.module.css';

interface AccountSubmitErrorProps {
  error: AccountErrorKind;
  /** For an insufficient balance: the first day the account would be negative (ISO 8601). */
  day: string | null;
}

/** The refusal of an account save; a floor refusal names its day, since the form has no amount to blame. */
export function AccountSubmitError({ error, day }: AccountSubmitErrorProps) {
  const { t } = useTranslation();

  return (
    <p className={styles.alert} role="alert">
      {error === 'insufficientBalance' ? (
        <InsufficientBalanceMessage date={day} write="reconfiguration" />
      ) : (
        t(`accounts.errors.${error}`)
      )}
    </p>
  );
}
