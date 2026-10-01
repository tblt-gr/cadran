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
import {
  InstrumentRequestError,
  instrumentFieldErrors,
  instrumentRequestError,
  instrumentSaveMessage,
  isStaleInstrumentError,
} from '@/features/portfolio/instruments/instrumentError';

type Body = CreateInstrumentRequest | UpdateInstrumentRequest;

/** Save orchestration for the instrument modal, including the confirmed-duplicate retry. */
export function useInstrumentEditor(instrument: Instrument | undefined, onSaved: () => void) {
  const client = useQueryClient();
  const [retryDuplicate, setRetryDuplicate] = useState(false);
  const [submittedBody, setSubmittedBody] = useState<Body | null>(null);
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
            createInstrument({ ...authApiOptions(), body: payload as CreateInstrumentRequest }),
          );
      if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onSaved();
      await client.invalidateQueries({ queryKey: ['instruments'] });
    },
    onError: async (error) => {
      if (isStaleInstrumentError(error)) {
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
      save.mutate({ body, duplicate: false });
    },
    confirmDuplicate() {
      if (!submittedBody) return;
      setRetryDuplicate(true);
      save.mutate({ body: submittedBody, duplicate: true });
    },
    dismissDuplicate() {
      save.reset();
      setRetryDuplicate(false);
    },
  };
}
