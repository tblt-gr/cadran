import { recordPortfolioTakeover, type PortfolioTakeoverRequest } from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { invalidatePortfolioAccount } from '@/features/portfolio/portfolio-account/operations/operation-form/invalidatePortfolioAccount';
import { DiscardTakeoverDialog } from './DiscardTakeoverDialog';
import { PortfolioTakeoverForm } from './PortfolioTakeoverForm';
import { takeoverRequestError, takeoverSaveMessage, TakeoverRequestError } from './takeoverError';
import { mapTakeoverErrors } from './takeoverRequest';

/**
 * Records the takeover. The idempotency key is reused only for an identical body, so a retry
 * after a lost answer replays the first result while an edited body gets a fresh key.
 */
export function PortfolioTakeoverModal({
  account,
  defaultDate,
  onClose,
  onSaved,
}: {
  account: { id: string; label: string; assetCode: string };
  defaultDate: string;
  onClose: () => void;
  /** Called with the takeover date once everything is recorded. */
  onSaved: (takenOverOn: string) => void;
}) {
  const client = useQueryClient();
  const attempt = useRef<{ body: string; key: string } | null>(null);
  const [dirty, setDirty] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const save = useMutation({
    mutationFn: async (request: PortfolioTakeoverRequest) => {
      const body = JSON.stringify(request);
      if (attempt.current?.body !== body) attempt.current = { body, key: crypto.randomUUID() };
      const headers = { ...authApiOptions().headers, 'Idempotency-Key': attempt.current.key };
      const result = await withCsrfRetry(() =>
        recordPortfolioTakeover({
          ...authApiOptions(),
          headers,
          path: { accountId: account.id },
          body: request,
        }),
      );
      if (!result.response?.ok || !result.data) throw takeoverRequestError(result);
      return result.data;
    },
    onSuccess: async (takeover) => {
      onSaved(takeover.takenOverOn);
      await Promise.all([
        invalidatePortfolioAccount(client, account.id),
        client.invalidateQueries({ queryKey: ['portfolio-valuations', account.id] }),
        client.invalidateQueries({ queryKey: ['portfolio-takeover', account.id] }),
        client.invalidateQueries({ queryKey: ['accounts'] }),
        client.invalidateQueries({ queryKey: ['net-worth'] }),
        client.invalidateQueries({ queryKey: ['holdings'] }),
      ]);
    },
  });

  const refusal = save.error instanceof TakeoverRequestError ? save.error : null;
  return (
    <>
      <Modal
        close={() => (dirty ? setConfirming(true) : onClose())}
        eyebrow={account.label}
        intent="create"
        title="Saisir mes positions"
      >
        <PortfolioTakeoverForm
          account={account}
          defaultDate={defaultDate}
          onEdit={() => setDirty(true)}
          onSubmit={(request) => save.mutate(request)}
          pending={save.isPending}
          saveMessage={takeoverSaveMessage(save.error)}
          serverErrors={refusal ? mapTakeoverErrors(refusal.errors) : undefined}
        />
      </Modal>
      {confirming ? (
        <DiscardTakeoverDialog onContinue={() => setConfirming(false)} onDiscard={onClose} />
      ) : null}
    </>
  );
}
