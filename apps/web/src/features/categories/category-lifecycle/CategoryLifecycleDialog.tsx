import {
  previewCategoryImpact,
  type Category,
  type CategoryLifecycleOperation,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import {
  categoryErrorBlockers,
  categoryErrorKind,
  categoryRequestError,
} from '@/features/categories/categoryError';
import { DeleteChoice, type DeleteChoiceValue } from './delete-choice/DeleteChoice';
import { ImpactPreview } from './impact-preview/ImpactPreview';
import styles from './CategoryLifecycleDialog.module.css';

/** `DELETE` is a user-facing entry point that resolves to MERGE or ARCHIVE. */
export type CategoryDialogOperation = CategoryLifecycleOperation | 'DELETE';

export interface CategoryLifecycleConfirmation {
  effectiveFrom: string;
  operation: CategoryLifecycleOperation;
  targetId: string | null;
}

interface CategoryLifecycleDialogProps {
  category: Category;
  onCancel: () => void;
  onConfirm: (confirmation: CategoryLifecycleConfirmation) => void;
  operation: CategoryDialogOperation;
  pending: boolean;
  submitError: unknown;
  submitFailed: boolean;
}

/**
 * Collects the operand of a lifecycle operation, shows what it would change and
 * asks for an explicit confirmation.
 *
 * The confirm button follows the preview: an operation the backend already
 * refuses is never offered, and the same refusal reasons are shown again if the
 * write is rejected between the preview and the confirmation.
 */
export function CategoryLifecycleDialog({
  category,
  onCancel: _onCancel,
  onConfirm,
  operation: requestedOperation,
  pending,
  submitError,
  submitFailed,
}: CategoryLifecycleDialogProps) {
  const { t } = useTranslation();
  const effectiveHintId = useId();
  const [targetId, setTargetId] = useState('');
  const [effectiveFrom, setEffectiveFrom] = useState('');
  const [choice, setChoice] = useState<DeleteChoiceValue | null>(null);

  const isDelete = requestedOperation === 'DELETE';
  // Until "transfer" is picked, a deletion previews the archive: that preview also tells whether
  // the category holds any history, and therefore whether a question is needed at all.
  const operation: CategoryLifecycleOperation = isDelete
    ? choice === 'transfer'
      ? 'MERGE'
      : 'ARCHIVE'
    : requestedOperation;
  const needsTarget = operation !== 'ARCHIVE';
  const needsDate = operation === 'REPLACE';
  const movesToRoot = operation === 'MOVE' && targetId === '';
  const operandReady =
    (!needsTarget || targetId !== '' || movesToRoot) && (!needsDate || effectiveFrom !== '');

  const impact = useQuery({
    queryKey: ['category-impact', category.id, operation, targetId, effectiveFrom],
    enabled: operandReady,
    queryFn: async ({ signal }) => {
      const result = await previewCategoryImpact({
        ...authApiOptions(),
        path: { id: category.id },
        query: {
          operation,
          targetId: targetId === '' ? undefined : targetId,
          effectiveFrom: needsDate ? effectiveFrom : undefined,
        },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw categoryRequestError(result);
      }
      return result.data;
    },
    retry: false,
  });

  const errorKind = categoryErrorKind(submitError, submitFailed);
  const refusedBlockers = categoryErrorBlockers(submitError);
  const previewStatus = !operandReady
    ? 'idle'
    : impact.isPending
      ? 'pending'
      : impact.isError
        ? 'error'
        : 'ready';
  // The archive preview never counts classifications, so the category's own usage flag decides.
  // A category without classified history is archived straight away: nothing to transfer or keep.
  const unused = isDelete && !category.used;
  const choiceMade = !isDelete || unused || choice !== null;
  const confirmable =
    operandReady && choiceMade && impact.isSuccess && impact.data.allowed && !pending;

  return (
    <form
      className={styles.dialog}
      onSubmit={(event) => {
        event.preventDefault();
        if (!confirmable) {
          return;
        }

        onConfirm({
          effectiveFrom,
          operation,
          targetId: needsTarget && targetId !== '' ? targetId : null,
        });
      }}
    >
      <p className={styles.summary}>
        {t(`categories.lifecycle.${requestedOperation}.description`, { label: category.label })}
      </p>

      {errorKind ? (
        <div className={styles.alert} role="alert">
          <p>{t(`categories.errors.${errorKind}`)}</p>
          {refusedBlockers.length > 0 ? (
            <ul>
              {refusedBlockers.map((blocker) => (
                <li key={blocker}>{t(`categories.lifecycle.blockers.${blocker}`)}</li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      {isDelete ? (
        unused ? (
          <p className={styles.summary}>{t('categories.lifecycle.DELETE.unused')}</p>
        ) : (
          <DeleteChoice onChange={setChoice} value={choice} />
        )
      ) : null}

      {needsTarget ? (
        <div className={styles.fields}>
          <CategoryPicker
            emptyOptionLabel={
              operation === 'MOVE' ? t('categories.lifecycle.MOVE.root') : undefined
            }
            excludeId={category.id}
            label={t(`categories.lifecycle.${operation}.target`)}
            onChange={setTargetId}
            parentEligible={operation === 'MOVE'}
            type={category.type}
            value={targetId}
          />
        </div>
      ) : null}

      {needsDate ? (
        <div className={styles.dateField}>
          <label>
            <span>{t('categories.lifecycle.REPLACE.effectiveFrom')}</span>
            <input
              aria-describedby={effectiveHintId}
              onChange={(event) => setEffectiveFrom(event.target.value)}
              required
              type="date"
              value={effectiveFrom}
            />
          </label>
          <small className={styles.hint} id={effectiveHintId}>
            {t('categories.lifecycle.REPLACE.effectiveHint')}
          </small>
        </div>
      ) : null}

      <ImpactPreview impact={impact.data} status={previewStatus} />

      <div className={styles.actions}>
        <button className="primary-action" disabled={!confirmable} type="submit">
          {t(
            pending
              ? 'categories.lifecycle.confirming'
              : `categories.lifecycle.${requestedOperation}.confirm`,
          )}
        </button>
      </div>
    </form>
  );
}
