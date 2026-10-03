import { withdrawPortfolioValuation, type PortfolioValuationSummary } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  isStaleValuationError,
  valuationRequestError,
  valuationSaveMessage,
} from '@/features/portfolio/valuations/valuationError';
import { refreshValuations } from '@/features/portfolio/valuations/refreshValuations';
import { WithdrawValuationDialog } from './WithdrawValuationDialog';

/** The mutation lives here so its error is discarded whenever the modal closes. */
export function WithdrawValuationModal({
  onClose,
  onStale,
  valuation,
}: {
  onClose: () => void;
  onStale: () => void;
  valuation: PortfolioValuationSummary;
}) {
  const client = useQueryClient();
  const withdraw = useMutation({
    mutationFn: async () => {
      const result = await withCsrfRetry(() =>
        withdrawPortfolioValuation({
          ...authApiOptions(),
          path: { id: valuation.id },
          body: { version: valuation.version },
        }),
      );
      if (!result.response?.ok) throw valuationRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onClose();
      await refreshValuations(client, valuation.accountId);
    },
    onError: async (error) => {
      if (!isStaleValuationError(error)) return;
      onStale();
      await refreshValuations(client, valuation.accountId);
    },
  });

  return (
    <Modal
      close={onClose}
      eyebrow={`Valorisation du ${formatCalendarNumericDay(valuation.valuedOn)}`}
      title="Retirer la valorisation"
    >
      <WithdrawValuationDialog
        error={valuationSaveMessage(withdraw.error)}
        onConfirm={() => withdraw.mutate()}
        pending={withdraw.isPending}
      />
    </Modal>
  );
}
