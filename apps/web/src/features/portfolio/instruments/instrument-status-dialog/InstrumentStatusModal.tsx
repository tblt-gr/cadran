import { deactivateInstrument, reactivateInstrument, type Instrument } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  instrumentRequestError,
  isStaleInstrumentError,
} from '@/features/portfolio/instruments/instrumentError';
import { InstrumentStatusDialog } from './InstrumentStatusDialog';

/** The mutation lives here so its error state is discarded whenever the modal closes. */
export function InstrumentStatusModal({
  instrument,
  onClose,
  onStale,
}: {
  instrument: Instrument;
  onClose: () => void;
  onStale: () => void;
}) {
  const client = useQueryClient();
  const refresh = () => client.invalidateQueries({ queryKey: ['instruments'] });
  const changeStatus = useMutation({
    mutationFn: async (item: Instrument) => {
      const change = item.status === 'ACTIVE' ? deactivateInstrument : reactivateInstrument;
      const result = await withCsrfRetry(() =>
        change({ ...authApiOptions(), path: { id: item.id }, body: { version: item.version } }),
      );
      if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onClose();
      await refresh();
    },
    onError: async (error) => {
      if (!isStaleInstrumentError(error)) return;
      onClose();
      onStale();
      await refresh();
    },
  });
  return (
    <Modal
      close={onClose}
      eyebrow="Investissements"
      title={instrument.status === 'ACTIVE' ? 'Désactiver l’instrument' : 'Réactiver l’instrument'}
    >
      <InstrumentStatusDialog
        error={changeStatus.isError}
        instrument={instrument}
        onConfirm={() => changeStatus.mutate(instrument)}
        pending={changeStatus.isPending}
      />
    </Modal>
  );
}
