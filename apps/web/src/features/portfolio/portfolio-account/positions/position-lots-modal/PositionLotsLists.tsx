import type { PortfolioPositionLots } from '@cadran/api-client';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { DatedRowList } from '@/components/ui/dated-list/DatedRowList';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';

import styles from './PositionLotsLists.module.css';

type OpenLot = PortfolioPositionLots['openLots'][number];
type Movement = PortfolioPositionLots['movements'][number];

interface Props {
  accountId: string;
  /** Null while loading: both lists mirror their loaded markup with placeholder rows. */
  lots: PortfolioPositionLots | null;
}

function Cost({ amount }: { amount: OpenLot['costBasis'] }) {
  return amount ? (
    formatAmount(amount.value, amount.assetCode, 'fr-FR')
  ) : (
    <EmptyValue label="Coût inconnu" />
  );
}

/** Open lots then the movement history, each as compact rows that never scroll sideways. */
export function PositionLotsLists({ accountId, lots }: Props) {
  const operationLink = (id: string) => `/portfolios/${accountId}?tab=operations&operation=${id}`;
  const operation = (id: string, type: OpenLot['originType']) => (
    <a
      className={styles.operation}
      href={operationLink(id)}
      onClick={(event) => handleClientNavigation(event, operationLink(id))}
    >
      {operationTypeLabel(type)}
    </a>
  );

  return (
    <>
      <h3>Lots ouverts</h3>
      <DatedRowList
        getKey={(lot) => lot.lotId}
        items={lots?.openLots ?? null}
        label="Lots ouverts"
        loadingLabel="Chargement des lots…"
        renderRow={(lot) => (
          <DatedRow
            meta={
              <>
                <small>
                  {lot ? (
                    `Quantité restante : ${formatDecimal(lot.openQuantity, 'fr-FR')}`
                  ) : (
                    <SkeletonBlock />
                  )}
                </small>
                <small>
                  {lot ? (
                    <>
                      Quantité initiale {formatDecimal(lot.originalQuantity, 'fr-FR')} · Coût
                      unitaire <Cost amount={lot.unitCost} /> ·{' '}
                      {operation(lot.originOperationId, lot.originType)}
                    </>
                  ) : (
                    <SkeletonBlock />
                  )}
                </small>
              </>
            }
            title={lot ? formatCalendarNumericDay(lot.acquiredOn) : <SkeletonBlock />}
            value={lot ? <Cost amount={lot.costBasis} /> : <SkeletonBlock />}
          />
        )}
        skeletonRows={3}
        skeletonTestId="position-lots-skeleton"
      />
      <h3>Historique</h3>
      <DatedRowList
        announce={false}
        getKey={(movement: Movement) =>
          `${movement.operationId}-${movement.lotId}-${movement.sequence}`
        }
        items={lots?.movements ?? null}
        label="Mouvements des lots"
        loadingLabel="Chargement des lots…"
        renderRow={(movement) => (
          <DatedRow
            meta={
              <>
                <small>
                  {movement ? operation(movement.operationId, movement.type) : <SkeletonBlock />}
                </small>
                <small>
                  {movement ? (
                    `Δ quantité : ${formatDecimal(movement.quantityDelta, 'fr-FR')}`
                  ) : (
                    <SkeletonBlock />
                  )}
                </small>
              </>
            }
            title={movement ? formatCalendarNumericDay(movement.tradeDate) : <SkeletonBlock />}
            value={movement ? <Cost amount={movement.costDelta} /> : <SkeletonBlock />}
          />
        )}
        skeletonRows={3}
        skeletonTestId="position-movements-skeleton"
      />
    </>
  );
}
