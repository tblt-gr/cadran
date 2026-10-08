import {
  correctPortfolioOperation,
  recordPortfolioOperation,
  type PortfolioOperation,
  type PortfolioOperationRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  isStaleOperationError,
  operationFieldErrors,
  operationRequestError,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import { OperationForm } from './OperationForm';

interface Submission {
  request: PortfolioOperationRequest;
  reason: string | null;
}

/**
 * Creates an operation, or replaces one when `operation` is given. The idempotency key
 * is reused only for an identical body, so a retry after a lost answer replays the
 * first result while an edited body gets a fresh key.
 */
export function OperationFormModal({
  account,
  onClose,
  onSaved,
  onStale,
  operation,
  today,
}: {
  account: { id: string; assetCode: string; kind?: string };
  onClose: () => void;
  onSaved: () => void;
  onStale: () => void;
  operation?: PortfolioOperation;
  today: string;
}) {
  const client = useQueryClient();
  const attempt = useRef<{ body: string; key: string } | null>(null);
  const save = useMutation({
    mutationFn: async ({ request, reason }: Submission) => {
      const body = JSON.stringify({ request, reason });
      if (attempt.current?.body !== body) {
        attempt.current = { body, key: crypto.randomUUID() };
      }
      const headers = { ...authApiOptions().headers, 'Idempotency-Key': attempt.current.key };
      const result = await withCsrfRetry(() =>
        operation
          ? correctPortfolioOperation({
              ...authApiOptions(),
              headers,
              path: { id: operation.id },
              body: { version: operation.version, reason, operation: request },
            })
          : recordPortfolioOperation({
              ...authApiOptions(),
              headers,
              path: { accountId: account.id },
              body: request,
            }),
      );
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      onSaved();
      await Promise.all([
        client.invalidateQueries({ queryKey: ['portfolio-summary', account.id] }),
        client.invalidateQueries({ queryKey: ['life-insurance-summary', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-allocation', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-valuation-history', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-performance', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-performance-annual', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolios'] }),
        client.invalidateQueries({ queryKey: ['portfolio-cash', account.id] }),
        client.invalidateQueries({ queryKey: ['life-insurance-contract', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-positions', account.id] }),
        client.invalidateQueries({ queryKey: ['full-surrender-positions', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-operations', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-operation'] }),
        client.invalidateQueries({ queryKey: ['portfolio-transfer-candidates'] }),
        client.invalidateQueries({ queryKey: ['account', account.id] }),
      ]);
    },
    onError: async (error) => {
      if (!isStaleOperationError(error)) return;
      onClose();
      onStale();
      await client.invalidateQueries({ queryKey: ['portfolio-operations', account.id] });
    },
  });

  return (
    <Modal
      close={onClose}
      eyebrow="Portefeuille"
      title={operation ? 'Modifier l’opération' : 'Nouvelle opération'}
    >
      <OperationForm
        account={account}
        fieldErrors={operationFieldErrors(save.error)}
        onSubmit={(request, reason) => save.mutate({ request, reason })}
        operation={operation}
        pending={save.isPending}
        saveError={save.error}
        today={today}
      />
    </Modal>
  );
}
