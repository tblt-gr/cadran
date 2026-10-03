import {
  recordPortfolioValuation,
  type Account,
  type PortfolioValuation,
  type PortfolioValuationDraft,
  type PortfolioValuationRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { refreshValuations } from '@/features/portfolio/valuations/refreshValuations';
import { useValuationDraft } from '@/features/portfolio/valuations/useValuations';
import {
  isStaleValuationError,
  valuationFieldErrors,
  valuationRequestError,
  valuationSaveMessage,
} from '@/features/portfolio/valuations/valuationError';
import { ValuationDateStep } from './ValuationDateStep';
import { ValuationDraftSkeleton } from './ValuationDraftSkeleton';
import { ValuationForm } from './ValuationForm';

/** A refetched draft that changed (version or held positions) must rebuild the typed rows. */
function draftKey(draft: PortfolioValuationDraft): string {
  const positions = draft.positions.map((item) => `${item.instrument.id}:${item.quantity}`);
  return `${draft.currentValuation?.version ?? 'none'}|${positions.join(',')}`;
}

/**
 * Records a valuation in two steps: the date, then the form built by the server from that date.
 * With `replacing` the date is fixed and the draft carries the version to supersede. The
 * idempotency key is reused only for an identical body, so a retry after a lost answer replays
 * the first result while an edited body gets a fresh key.
 */
export function ValuationModal({
  account,
  onClose,
  onSaved,
  onStale,
  replacing,
  today,
}: {
  account: Pick<Account, 'id' | 'assetCode'>;
  onClose: () => void;
  onSaved: (valuation: PortfolioValuation) => void;
  onStale: () => void;
  /** The row the user clicked: its version, not the draft's, is the one to supersede. */
  replacing?: { valuedOn: string; version: number };
  today: string;
}) {
  const client = useQueryClient();
  const [valuedOn, setValuedOn] = useState(replacing?.valuedOn ?? today);
  const [confirmed, setConfirmed] = useState<string | null>(replacing?.valuedOn ?? null);
  const draft = useValuationDraft(account.id, confirmed);
  const attempt = useRef<{ body: string; key: string } | null>(null);
  const save = useMutation({
    mutationFn: async (request: PortfolioValuationRequest) => {
      const body = JSON.stringify(request);
      if (attempt.current?.body !== body) {
        attempt.current = { body, key: crypto.randomUUID() };
      }
      const headers = { ...authApiOptions().headers, 'Idempotency-Key': attempt.current.key };
      const result = await withCsrfRetry(() =>
        recordPortfolioValuation({
          ...authApiOptions(),
          headers,
          path: { accountId: account.id },
          body: request,
        }),
      );
      if (!result.response?.ok || !result.data) throw valuationRequestError(result);
      return result.data;
    },
    onSuccess: async (valuation) => {
      client.setQueryData(['portfolio-valuation', valuation.id], valuation);
      onSaved(valuation);
      await refreshValuations(client, account.id);
    },
    onError: async (error) => {
      if (!isStaleValuationError(error)) return;
      onStale();
      await refreshValuations(client, account.id);
    },
  });

  const title = replacing
    ? `Remplacer la valorisation du ${formatCalendarNumericDay(replacing.valuedOn)}`
    : 'Enregistrer une valorisation';

  return (
    <Modal close={onClose} eyebrow="Portefeuille" title={title}>
      {confirmed === null ? (
        <ValuationDateStep
          onChange={setValuedOn}
          onContinue={() => setConfirmed(valuedOn)}
          today={today}
          value={valuedOn}
        />
      ) : draft.isPending ? (
        <ValuationDraftSkeleton />
      ) : draft.isError ? (
        <div role="alert">
          <p>Le brouillon de valorisation est indisponible.</p>
          <button className="secondary-action" onClick={() => void draft.refetch()} type="button">
            Réessayer
          </button>
          {replacing ? null : (
            <button className="secondary-action" onClick={() => setConfirmed(null)} type="button">
              Changer la date
            </button>
          )}
        </div>
      ) : (
        <ValuationForm
          key={draftKey(draft.data)}
          assetCode={account.assetCode}
          draft={draft.data}
          version={replacing?.version ?? draft.data.currentValuation?.version ?? null}
          fieldErrors={valuationFieldErrors(save.error)}
          onSubmit={(request) => save.mutate(request)}
          pending={save.isPending}
          saveMessage={valuationSaveMessage(save.error)}
        />
      )}
    </Modal>
  );
}
