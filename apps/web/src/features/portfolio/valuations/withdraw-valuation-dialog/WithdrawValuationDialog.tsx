import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import styles from './WithdrawValuationDialog.module.css';

export function WithdrawValuationDialog({
  error,
  onCancel,
  onConfirm,
  pending,
}: {
  error?: string;
  onCancel?: () => void;
  onConfirm: () => void;
  pending: boolean;
}) {
  const { t } = useTranslation();
  return (
    <div className={styles.dialog}>
      <p>{t('portfolio.valuations.withdraw.explain')}</p>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <ModalActions>
        {onCancel ? (
          <button className="secondary-action" type="button" onClick={onCancel}>
            {t('portfolio.valuations.withdraw.cancel')}
          </button>
        ) : null}
        <button
          className="primary-action"
          data-autofocus
          disabled={pending}
          onClick={onConfirm}
          type="button"
        >
          {pending
            ? t('portfolio.valuations.withdraw.pending')
            : t('portfolio.valuations.row.withdraw')}
        </button>
      </ModalActions>
    </div>
  );
}
