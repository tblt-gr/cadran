import { readInstrument, reactivateInstrument, type Instrument } from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { instrumentRequestError } from '@/features/portfolio/instruments/instrumentError';
import type {
  InstrumentInitial,
  InstrumentLock,
} from '@/features/portfolio/instruments/instrument-form/InstrumentForm';

async function read(id: string, signal?: AbortSignal) {
  const result = await readInstrument({ ...authApiOptions(), path: { id }, signal });
  if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
  return result.data;
}

/** Resolve candidates through scoped endpoints before checking compatibility and reactivating. */
export function useInstrumentReuse({
  existingId,
  initial,
  lock,
  onSaved,
}: {
  existingId?: string | null;
  initial?: InstrumentInitial;
  lock?: InstrumentLock;
  onSaved: (instrument: Instrument) => void;
}) {
  const client = useQueryClient();
  const existing = useQuery({
    queryKey: ['instrument', existingId],
    enabled: Boolean(existingId),
    queryFn: ({ signal }) => read(existingId!, signal),
    retry: false,
  });
  const compatible = (item: Instrument) =>
    (!lock?.currencyCode || item.currencyCode === initial?.currencyCode) &&
    (!lock?.instrumentType || item.instrumentType === initial?.instrumentType);
  const use = useMutation({
    mutationFn: async (id: string) => {
      const item = await read(id);
      if (!compatible(item)) throw new Error('Incompatible instrument');
      if (item.status === 'ACTIVE') return item;
      const result = await withCsrfRetry(() =>
        reactivateInstrument({
          ...authApiOptions(),
          path: { id },
          body: { version: item.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
      if (!compatible(result.data)) throw new Error('Incompatible instrument');
      return result.data;
    },
    onSuccess: async (instrument) => {
      onSaved(instrument);
      await Promise.all([
        client.invalidateQueries({ queryKey: ['instruments'] }),
        client.invalidateQueries({ queryKey: ['instrument-picker'] }),
        client.invalidateQueries({ queryKey: ['instrument', instrument.id] }),
      ]);
    },
  });
  return { existing, compatible, use };
}
