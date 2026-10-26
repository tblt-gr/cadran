import type { BulkCategorizeResult } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type {
  BulkScope,
  BulkSelection,
} from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkRequestError, useBulkCategorize } from './useBulkCategorize';
import { BulkCategoryResult } from './BulkCategoryResult';
import styles from './BulkCategoryModal.module.css';

interface BulkCategoryModalProps {
  /** Closes the dialog; `applied` tells the host the selection was written and is now stale. */
  close: (applied: boolean) => void;
  pageItems: BulkSelection['pageItems'];
  reloadAfterWrite: () => Promise<void>;
  scope: BulkScope;
}

/**
 * Confirmation of a bulk category change, then its outcome. The server applies what it can
 * and reports the rest, so the result view is part of the same dialog rather than a toast.
 */
export function BulkCategoryModal({
  close,
  pageItems,
  reloadAfterWrite,
  scope,
}: BulkCategoryModalProps) {
  const { t } = useTranslation();
  const [categoryId, setCategoryId] = useState('');
  // The server refuses a filter scope whose count moved; the user confirms the new one.
  const [expectedCount, setExpectedCount] = useState(
    scope.type === 'FILTER' ? scope.expectedCount : 0,
  );
  const [result, setResult] = useState<BulkCategorizeResult | null>(null);
  const mutation = useBulkCategorize({ reloadAfterWrite });
  const error = mutation.error instanceof BulkRequestError ? mutation.error : null;
  const effectiveScope: BulkScope = scope.type === 'FILTER' ? { ...scope, expectedCount } : scope;
  const count = scope.type === 'IDS' ? scope.ids.length : expectedCount;
  const done = () => close(result !== null);

  function confirm() {
    mutation.mutate(
      { categoryId, scope: effectiveScope },
      {
        onSuccess: setResult,
        onError: (failure) => {
          if (failure instanceof BulkRequestError && failure.actualCount !== null) {
            setExpectedCount(failure.actualCount);
          }
        },
      },
    );
  }

  return (
    <Modal close={done} title={t('transactions.bulk.title')}>
      {result ? (
        <BulkCategoryResult onDone={done} pageItems={pageItems} result={result} />
      ) : (
        <form
          className={styles.form}
          onSubmit={(event) => {
            event.preventDefault();
            if (categoryId !== '' && !mutation.isPending) confirm();
          }}
        >
          <p>
            {t(
              scope.type === 'IDS'
                ? 'transactions.bulk.summaryIds'
                : 'transactions.bulk.summaryFilter',
              { count },
            )}
          </p>
          <CategoryPicker
            label={t('transactions.fields.category')}
            onChange={(id) => setCategoryId(id)}
            value={categoryId}
          />
          <p className={styles.note}>{t('transactions.bulk.note')}</p>
          {error ? (
            <p className={styles.error} role="alert">
              {t(`transactions.bulk.errors.${error.kind}`, {
                count: error.actualCount ?? undefined,
              })}
            </p>
          ) : null}
          <div className={styles.actions}>
            <button className="secondary-action" onClick={done} type="button">
              {t('actions.cancel')}
            </button>
            <button
              className="primary-action"
              disabled={categoryId === '' || mutation.isPending}
              type="submit"
            >
              {t(mutation.isPending ? 'transactions.bulk.applying' : 'transactions.bulk.confirm')}
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}
