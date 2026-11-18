import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { readPortfolioPositionLots, type PortfolioPosition } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import styles from './PositionLotsModal.module.css';
import { PositionLotsLists } from './PositionLotsLists';

interface Props {
  accountId: string;
  asOf: string;
  onClose: () => void;
  position: PortfolioPosition;
}

export function PositionLotsModal({ accountId, asOf, position, onClose }: Props) {
  const lots = useQuery({
    queryKey: ['portfolio-position-lots', accountId, position.instrument.id, asOf],
    queryFn: async ({ signal }) => {
      const result = await readPortfolioPositionLots({
        ...authApiOptions(),
        path: { accountId, instrumentId: position.instrument.id },
        query: { asOf },
        signal,
      });
      if (!result.response?.ok || !result.data) throw operationRequestError(result);
      return result.data;
    },
    retry: false,
  });

  const cost = (amount: PortfolioPosition['fifoCostBasis']) =>
    amount ? (
      formatAmount(amount.value, amount.assetCode, 'fr-FR')
    ) : (
      <EmptyValue label="Coût inconnu" />
    );

  return (
    <Modal intent="detail" close={onClose} title={`Lots : ${position.instrument.name}`}>
      <div className={styles.content}>
        <p>
          <InstrumentLink instrument={position.instrument} />
        </p>
        <dl className={styles.figures}>
          <div>
            <dt>Quantité détenue</dt>
            <dd>{formatDecimal(position.quantity, 'fr-FR')}</dd>
          </div>
          <div>
            <dt>Coût restant (FIFO)</dt>
            <dd>{cost(position.fifoCostBasis)}</dd>
          </div>
          <div>
            <dt>Prix de revient moyen</dt>
            <dd>{cost(position.averageCostBasis)}</dd>
          </div>
        </dl>
        {lots.isPending ? (
          <PositionLotsLists accountId={accountId} lots={null} />
        ) : lots.isError ? (
          <div className={styles.errorState}>
            <p role="alert">Les lots sont indisponibles.</p>
            <button className="secondary-action" onClick={() => void lots.refetch()} type="button">
              Réessayer
            </button>
          </div>
        ) : (
          <PositionLotsLists accountId={accountId} lots={lots.data} />
        )}
        <p className={styles.note}>
          Lots consommés dans l’ordre d’acquisition (FIFO). Ce n’est pas un calcul fiscal.
        </p>
      </div>
    </Modal>
  );
}
