import { useTranslation } from 'react-i18next';

export function TransactionAccountNotice({
  failed,
  onRetry,
}: {
  failed: boolean;
  onRetry: () => void;
}) {
  const { t } = useTranslation();
  if (!failed) return null;
  return (
    <aside className="card" role="alert">
      <p>{t('transactions.accountContextError')}</p>
      <button type="button" className="secondary-action" onClick={onRetry}>
        {t('foundation.retry')}
      </button>
    </aside>
  );
}
