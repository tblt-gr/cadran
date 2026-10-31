import {
  readPortfolioPositionLots,
  type PortfolioPosition,
  type PortfolioPositionLots,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { operationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import styles from './PositionLotsModal.module.css';
import { PositionLotsModalSkeleton } from './PositionLotsModalSkeleton';

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
    <Modal close={onClose} title={`Lots : ${position.instrument.name}`}>
      <div className={styles.content}>
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
          <PositionLotsModalSkeleton />
        ) : lots.isError ? (
          <div className={styles.errorState}>
            <p role="alert">Les lots sont indisponibles.</p>
            <button className="secondary-action" onClick={() => void lots.refetch()} type="button">
              Réessayer
            </button>
          </div>
        ) : (
          <PositionLotsTables accountId={accountId} lots={lots.data} />
        )}
        <p className={styles.note}>
          Lots consommés dans l’ordre d’acquisition (FIFO). Ce n’est pas un calcul fiscal.
        </p>
      </div>
    </Modal>
  );
}

function PositionLotsTables({
  accountId,
  lots,
}: {
  accountId: string;
  lots: PortfolioPositionLots;
}) {
  const operationLink = (id: string) => `/portfolios/${accountId}?tab=operations&operation=${id}`;

  return (
    <>
      <h3>Lots ouverts</h3>
      <div className={styles.scroll}>
        <table aria-label="Lots ouverts" className={styles.table}>
          <thead>
            <tr>
              <th scope="col">Acquis le</th>
              <th scope="col">Origine</th>
              <th scope="col">Quantité initiale</th>
              <th scope="col">Quantité restante</th>
              <th scope="col">Coût restant</th>
              <th scope="col">Coût unitaire</th>
            </tr>
          </thead>
          <tbody>
            {lots.openLots.map((lot) => (
              <tr key={lot.lotId}>
                <td>{formatCalendarNumericDay(lot.acquiredOn)}</td>
                <td>
                  <a
                    href={operationLink(lot.originOperationId)}
                    onClick={(event) =>
                      handleClientNavigation(event, operationLink(lot.originOperationId))
                    }
                  >
                    {operationTypeLabel(lot.originType)}
                  </a>
                </td>
                <td>{formatDecimal(lot.originalQuantity, 'fr-FR')}</td>
                <td>{formatDecimal(lot.openQuantity, 'fr-FR')}</td>
                <td>
                  {lot.costBasis ? (
                    formatAmount(lot.costBasis.value, lot.costBasis.assetCode, 'fr-FR')
                  ) : (
                    <EmptyValue label="Coût inconnu" />
                  )}
                </td>
                <td>
                  {lot.unitCost ? (
                    formatAmount(lot.unitCost.value, lot.unitCost.assetCode, 'fr-FR')
                  ) : (
                    <EmptyValue label="Coût inconnu" />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <h3>Historique</h3>
      <div className={styles.scroll}>
        <table aria-label="Mouvements des lots" className={styles.table}>
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Opération</th>
              <th scope="col">Δ quantité</th>
              <th scope="col">Δ coût</th>
            </tr>
          </thead>
          <tbody>
            {lots.movements.map((movement) => (
              <tr key={`${movement.operationId}-${movement.lotId}-${movement.sequence}`}>
                <td>{formatCalendarNumericDay(movement.tradeDate)}</td>
                <td>
                  <a
                    href={operationLink(movement.operationId)}
                    onClick={(event) =>
                      handleClientNavigation(event, operationLink(movement.operationId))
                    }
                  >
                    {operationTypeLabel(movement.type)}
                  </a>
                </td>
                <td>{formatDecimal(movement.quantityDelta, 'fr-FR')}</td>
                <td>
                  {movement.costDelta ? (
                    formatAmount(movement.costDelta.value, movement.costDelta.assetCode, 'fr-FR')
                  ) : (
                    <EmptyValue label="Coût inconnu" />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
