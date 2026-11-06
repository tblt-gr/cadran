import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { BulkEditResult } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { BulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkSkippedList } from './BulkSkippedList';
import styles from './BulkEditModal.module.css';

interface BulkEditResultViewProps {
  onDone: () => void;
  pageItems: BulkSelection['pageItems'];
  result: BulkEditResult;
}

/** What a bulk edit did: how many were written, and every skipped one under its reason. */
export function BulkEditResultView({ onDone, pageItems, result }: BulkEditResultViewProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.form}>
      <p role="status">{t('transactions.bulk.changed', { count: result.changed })}</p>
      <BulkSkippedList pageItems={pageItems} skipped={result.skipped} />
      <ModalActions>
        <button className="primary-action" onClick={onDone} type="button">
          {t('transactions.bulk.done')}
        </button>
      </ModalActions>
    </div>
  );
}
