import { voidPortfolioOperation, type PortfolioOperation } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  isStaleOperationError,
  operationRequestError,
  operationSaveMessage,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import { VoidOperationDialog } from './VoidOperationDialog';

/** The mutation lives here so its error is discarded whenever the modal closes. */
export function VoidOperationModal({
  onClose,
  onStale,
  operation,
}: {
  onClose: () => void;
  onStale: () => void;
  operation: PortfolioOperation;
}) {
  const client = useQueryClient();
  const refresh = () =>
    Promise.all([
      client.invalidateQueries({ queryKey: ['portfolio-summary', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-allocation', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-valuation-history', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-performance', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-performance-annual', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolios'] }),
      client.invalidateQueries({ queryKey: ['portfolio-cash', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['life-insurance-contract', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-positions', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['full-surrender-positions', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-operations', operation.accountId] }),
      client.invalidateQueries({ queryKey: ['portfolio-operation', operation.id] }),
      client.invalidateQueries({ queryKey: ['portfolio-transfer-candidates'] }),
    ]);
  const voidOperation = useMutation({
    mutationFn: async (reason: string) => {
      const result = await withCsrfRetry(() =>
        voidPortfolioOperation({
          ...authApiOptions(),
          path: { id: operation.id },
          body: { version: operation.version, reason },
        }),
      );
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onClose();
      await refresh();
    },
    onError: async (error) => {
      if (!isStaleOperationError(error)) return;
      onClose();
      onStale();
      await refresh();
    },
  });

  return (
    <Modal close={onClose} eyebrow="Portefeuille" title="Annuler l’opération">
      <VoidOperationDialog
        error={operationSaveMessage(voidOperation.error, [
          operation.instrument,
          operation.counterInstrument,
        ])}
        onConfirm={(reason) => voidOperation.mutate(reason)}
        pending={voidOperation.isPending}
      />
    </Modal>
  );
}
