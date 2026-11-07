import {
  createInstrument,
  updateInstrument,
  type CreateInstrumentRequest,
  type Instrument,
  type UpdateInstrumentRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { useSubmissionKey } from '@/hooks/use-submission-key';
import {
  InstrumentRequestError,
  instrumentFieldErrors,
  instrumentRequestError,
  instrumentSaveMessage,
  isStaleInstrumentError,
} from '@/features/investments/instruments/instrumentError';

type Body = CreateInstrumentRequest | UpdateInstrumentRequest;

/** Save orchestration for the instrument modal, including the confirmed-duplicate retry. */
export function useInstrumentEditor(
  instrument: Instrument | undefined,
  onSaved: (instrument: Instrument) => void,
) {
  const client = useQueryClient();
  const [retryDuplicate, setRetryDuplicate] = useState(false);
  const [submittedBody, setSubmittedBody] = useState<Body | null>(null);
  const submission = useSubmissionKey();
  const save = useMutation({
    mutationFn: async ({ body, duplicate }: { body: Body; duplicate: boolean }) => {
      const payload = duplicate ? { ...body, confirmDuplicate: true } : body;
      const result = instrument
        ? await withCsrfRetry(() =>
            updateInstrument({
              ...authApiOptions(),
              path: { id: instrument.id },
              body: payload as UpdateInstrumentRequest,
            }),
          )
        : await withCsrfRetry(() =>
            createInstrument({
              ...authApiOptions(),
              headers: {
                ...authApiOptions().headers,
                'Idempotency-Key': submission.keyFor(payload),
              },
              body: payload as CreateInstrumentRequest,
            }),
          );
      if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
      const saved = result.data;
      if (
        typeof saved.id !== 'string' ||
        !saved.id.trim() ||
        typeof saved.name !== 'string' ||
        !saved.name.trim() ||
        saved.currencyCode !== payload.currencyCode ||
        saved.instrumentType !== payload.instrumentType ||
        (saved.status !== 'ACTIVE' && (saved.status !== 'INACTIVE' || !instrument))
      )
        throw instrumentRequestError({ error: { code: 'instrument_response_incompatible' } });
      return saved;
    },
    onSuccess: async (saved) => {
      submission.release();
      onSaved(saved);
      await Promise.all([
        client.invalidateQueries({ queryKey: ['instruments'] }),
        client.invalidateQueries({ queryKey: ['instrument-picker'] }),
        client.invalidateQueries({ queryKey: ['holdings'] }),
      ]);
    },
    onError: async (error) => {
      if (
        error instanceof InstrumentRequestError &&
        error.code === 'instrument_response_incompatible'
      ) {
        await Promise.all([
          client.invalidateQueries({ queryKey: ['instruments'] }),
          client.invalidateQueries({ queryKey: ['instrument-picker'] }),
        ]);
      } else if (isStaleInstrumentError(error)) {
        await client.invalidateQueries({ queryKey: ['instruments'] });
      }
    },
  });
  const error = save.error;
  const requestError = error instanceof InstrumentRequestError ? error : null;

  return {
    candidates:
      requestError?.code === 'instrument_duplicate_candidates' && !retryDuplicate && submittedBody
        ? requestError.candidates
        : null,
    existingInstrumentId:
      requestError?.code === 'instrument_isin_exists' ? requestError.existingId : undefined,
    fieldErrors: instrumentFieldErrors(error),
    message: instrumentSaveMessage(error),
    pending: save.isPending,
    submit(body: Body) {
      setSubmittedBody(body);
      if (save.isPending) return;
      setRetryDuplicate(false);
      save.mutate({ body, duplicate: false });
    },
    confirmDuplicate() {
      if (!submittedBody || save.isPending) return;
      setRetryDuplicate(true);
      save.mutate({ body: submittedBody, duplicate: true });
    },
    dismissDuplicate() {
      save.reset();
      setRetryDuplicate(false);
    },
  };
}
