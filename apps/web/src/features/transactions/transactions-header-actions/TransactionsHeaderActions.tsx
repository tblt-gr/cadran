import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';

export function TransactionsHeaderActions({
  onCreate,
  onTransfer,
}: {
  onCreate: () => void;
  onTransfer: () => void;
}) {
  const { t } = useTranslation();
  return (
    <HeaderActions>
      <button className="primary-action" onClick={onCreate} type="button">
        <Icon name="add" size={18} />
        {t('transactions.add')}
      </button>
      <button
        aria-label={t('transactions.addTransfer')}
        className="icon-button"
        onClick={onTransfer}
        title={t('transactions.addTransfer')}
        type="button"
      >
        <Icon name="transactions" size={18} />
      </button>
    </HeaderActions>
  );
}
