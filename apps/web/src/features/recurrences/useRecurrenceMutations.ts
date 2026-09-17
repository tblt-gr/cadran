import {
  archiveRecurrence,
  confirmRecurrence,
  dismissRecurrenceCandidate,
  restoreRecurrence,
  restoreRecurrenceCandidate,
  updateRecurrence,
  type CreateRecurrenceRequest,
  type Recurrence,
  type RecurrenceCandidate,
  type UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import type { Editor } from './recurrenceEditorState';
import { recurrenceErrorKind, recurrenceRequestError } from './recurrenceError';

function failed(result: { response?: Response }) {
  return new Error(`recurrence:${result.response?.status ?? 0}`);
}

interface UseRecurrenceMutationsOptions {
  /** The editor the create/edit mutation is bound to: `null`/`'create'` picks the create call. */
  editor: Editor;
  onArchived: () => void;
  onRestored: () => void;
  onSaved: () => void;
}

/**
 * The recurrences screen's five write operations — confirm or edit, dismiss a candidate,
 * undo that dismissal, and archive or restore a recurrence — and the one toast and one undo
 * banner they share.
 */
export function useRecurrenceMutations({
  editor,
  onArchived,
  onRestored,
  onSaved,
}: UseRecurrenceMutationsOptions) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [notice, setNotice] = useState<string | null>(null);
  const [dismissed, setDismissed] = useState<RecurrenceCandidate | null>(null);

  const save = useMutation({
    mutationFn: async (body: CreateRecurrenceRequest | UpdateRecurrenceRequest) => {
      const result =
        editor && editor !== 'create' && 'id' in editor
          ? await withCsrfRetry(() =>
              updateRecurrence({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateRecurrenceRequest,
              }),
            )
          : await withCsrfRetry(() =>
              confirmRecurrence({ ...authApiOptions(), body: body as CreateRecurrenceRequest }),
            );
      if (!result.response?.ok || !result.data) throw recurrenceRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onSaved();
      setNotice(t('recurrences.toasts.saved'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });

  const dismiss = useMutation({
    mutationFn: async (candidate: RecurrenceCandidate) => {
      const result = await withCsrfRetry(() =>
        dismissRecurrenceCandidate({
          ...authApiOptions(),
          body: { fingerprint: candidate.fingerprint },
        }),
      );
      if (!result.response?.ok) throw failed(result);
      return candidate;
    },
    onSuccess: async (candidate) => {
      setDismissed(candidate);
      setNotice(t('recurrences.toasts.dismissed'));
      await queryClient.invalidateQueries({ queryKey: ['recurrence-candidates'] });
    },
  });

  const undoDismissal = useMutation({
    mutationFn: async (candidate: RecurrenceCandidate) => {
      const result = await withCsrfRetry(() =>
        restoreRecurrenceCandidate({
          ...authApiOptions(),
          path: { fingerprint: candidate.fingerprint },
        }),
      );
      if (!result.response?.ok) throw failed(result);
    },
    onSuccess: async () => {
      setDismissed(null);
      setNotice(t('recurrences.toasts.restored'));
      await queryClient.invalidateQueries({ queryKey: ['recurrence-candidates'] });
    },
  });

  const restore = useMutation({
    mutationFn: async (recurrence: Recurrence) => {
      const result = await withCsrfRetry(() =>
        restoreRecurrence({
          ...authApiOptions(),
          path: { id: recurrence.id },
          body: { version: recurrence.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    onSuccess: async () => {
      onRestored();
      setNotice(t('recurrences.toasts.unarchived'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });

  const archive = useMutation({
    mutationFn: async (recurrence: Recurrence) => {
      const result = await withCsrfRetry(() =>
        archiveRecurrence({
          ...authApiOptions(),
          path: { id: recurrence.id },
          body: { version: recurrence.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    onSuccess: async () => {
      onArchived();
      setNotice(t('recurrences.toasts.archived'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });

  return {
    archive,
    dismiss,
    dismissed,
    notice,
    restore,
    save,
    setNotice,
    submitErrorKind: recurrenceErrorKind(save.error, save.isError),
    undoDismissal,
  };
}
