import type { BulkEditPreview, BulkEditResult } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { BulkItem, BulkScope } from '@/features/transactions/bulk-selection/useBulkSelection';
import { buildChanges, isMove, type BulkEditDraft } from './bulkEditChanges';
import { BulkRequestError, useBulkEdit, useBulkPreview } from './useBulkEdit';

interface UseBulkMoveFlowOptions {
  draft: BulkEditDraft;
  /** The server refused a filter scope whose count moved: the modal asks again with the new one. */
  onActualCount: (count: number) => void;
  onApplied: (result: BulkEditResult) => void;
  pageItems: readonly BulkItem[];
  reloadAfterWrite: () => Promise<void>;
  scope: BulkScope;
}

const asBulkError = (failure: unknown) => (failure instanceof BulkRequestError ? failure : null);

/**
 * Submit orchestration of the bulk edit: a plain edit applies at once, a move is previewed first
 * and applied against the preview token. A stale token refreshes the preview in place.
 */
export function useBulkMoveFlow({
  draft,
  onActualCount,
  onApplied,
  pageItems,
  reloadAfterWrite,
  scope,
}: UseBulkMoveFlowOptions) {
  const { t } = useTranslation();
  const [stage, setStage] = useState<'form' | 'preview'>('form');
  const [preview, setPreview] = useState<BulkEditPreview | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const mutation = useBulkEdit({ reloadAfterWrite });
  const previewMutation = useBulkPreview();

  function followCount(failure: unknown) {
    const error = asBulkError(failure);
    if (error?.actualCount != null) onActualCount(error.actualCount);
  }

  function showPreview(refreshNotice: string | null) {
    setStage('preview');
    setPreview(null);
    setNotice(refreshNotice);
    mutation.reset();
    previewMutation.mutate(
      { changes: buildChanges(draft, scope, pageItems), scope },
      {
        onSuccess: setPreview,
        onError: (failure) => {
          followCount(failure);
          setStage('form');
        },
      },
    );
  }

  function confirm() {
    mutation.mutate(
      {
        changes: buildChanges(draft, scope, pageItems),
        previewToken: preview?.previewToken,
        scope,
      },
      {
        onSuccess: onApplied,
        onError: (failure) => {
          followCount(failure);
          const kind = asBulkError(failure)?.kind;
          if (kind === 'previewStale') showPreview(t('transactions.bulk.move.refreshed'));
          else if (kind === 'scopeChanged') setStage('form');
        },
      },
    );
  }

  return {
    back: () => {
      mutation.reset();
      setStage('form');
    },
    confirm,
    /** Failure shown on the form: the preview's, else the apply's. */
    error: asBulkError(previewMutation.error) ?? asBulkError(mutation.error),
    /** Failure of the confirmation, shown on the preview screen. */
    confirmError: asBulkError(mutation.error),
    notice,
    pending: mutation.isPending || previewMutation.isPending,
    applying: mutation.isPending,
    preview,
    stage,
    submit: () => {
      previewMutation.reset();
      if (isMove(draft)) showPreview(null);
      else confirm();
    },
  };
}
