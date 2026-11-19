import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { InsufficientBalanceMessage } from '@/features/transactions/insufficient-balance/InsufficientBalanceMessage';

interface Props {
  /** The account the user was booking on: target of the portfolio link. */
  accountId?: string;
  className: string;
  /** For an insufficient balance: the first day the account would be negative (ISO 8601). */
  day?: string | null;
  /** The server's own explanation, kept for every refusal that does not have a dedicated message. */
  detail?: string | null;
  kind: TransactionErrorKind;
}

/** The refusal banner of a transaction, refund or transfer form. */
export function TransactionSubmitError({ accountId, className, day, detail, kind }: Props) {
  const { t } = useTranslation();
  const portfolio = kind === 'portfolioOperationsRequired';
  const href = accountId ? `/portfolios/${accountId}` : null;

  return (
    <p className={className} role="alert">
      {kind === 'insufficientBalance' ? (
        <InsufficientBalanceMessage date={day ?? null} />
      ) : (
        ((portfolio ? null : detail) ?? t(`transactions.errors.${kind}`))
      )}
      {portfolio && href ? (
        <>
          {' '}
          <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
            {t('transactions.errors.portfolioOperationsLink')}
          </a>
        </>
      ) : null}
    </p>
  );
}
