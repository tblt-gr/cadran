import type { ProductModel } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { ProductModelErrorKind } from '@/features/product-models/productModelError';
import styles from './ArchiveModelDialog.module.css';

interface ArchiveModelDialogProps {
  model: ProductModel;
  pending: boolean;
  submitError: ProductModelErrorKind | null;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * Archiving is not a deletion, and the wording says so: the model leaves the
 * working set and frees its name, but it stays readable by identifier with
 * every period it ever recorded, so an account created from it keeps a
 * reproducible reference.
 */
export function ArchiveModelDialog({
  model,
  pending,
  submitError,
  onCancel: _onCancel,
  onConfirm,
}: ArchiveModelDialogProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.dialog}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`productModels.errors.${submitError}`)}
        </p>
      ) : null}
      <p>{t('productModels.archive.description', { name: model.name })}</p>
      <p className={styles.hint}>{t('productModels.archive.consequences')}</p>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(pending ? 'productModels.archive.confirming' : 'productModels.archive.confirm')}
        </button>
      </div>
    </div>
  );
}
