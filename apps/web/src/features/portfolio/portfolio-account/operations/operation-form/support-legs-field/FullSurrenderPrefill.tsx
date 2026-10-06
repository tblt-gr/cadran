import { listPortfolioPositions, readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from './SupportLegsField.module.css';
import { isZeroDecimal } from '@/lib/decimal';
import type { SupportLegValue } from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';

export function FullSurrenderPrefill({
  accountId,
  tradeDate,
  onPrefill,
}: {
  accountId: string;
  tradeDate: string;
  onPrefill: (legs: SupportLegValue[]) => void;
}) {
  const query = useQuery({
    queryKey: ['full-surrender-positions', accountId, tradeDate],
    enabled: tradeDate !== '',
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await listPortfolioPositions({
        ...authApiOptions(),
        path: { accountId },
        query: { asOf: tradeDate, includeClosed: false },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Positions unavailable');
      return Promise.all(
        result.data.items
          .filter((position) => !isZeroDecimal(position.quantity))
          .map(async (position) => {
            const instrument = await readInstrument({
              ...authApiOptions(),
              path: { id: position.instrument.id },
              signal,
            });
            if (!instrument.response?.ok || !instrument.data)
              throw new Error('Support unavailable');
            return {
              key: position.instrument.id,
              instrument: instrument.data,
              quantity: position.quantity,
              unitPrice: '',
              amount: instrument.data.instrumentType === 'EURO_FUND' ? position.quantity : '',
            };
          }),
      );
    },
  });
  return (
    <div>
      <button
        className="secondary-action"
        type="button"
        disabled={
          !query.data || query.data.length === 0 || query.data.length > 20 || query.isFetching
        }
        onClick={() => onPrefill(query.data!)}
      >
        Reprendre toutes les positions
      </button>
      <div aria-busy={query.isPending} className={styles.summary}>
        <span>Positions à reprendre</span>
        {query.isPending ? (
          <>
            <SkeletonStatus>Chargement des positions</SkeletonStatus>
            <SkeletonBlock className={styles.summaryValue} />
          </>
        ) : (
          <span>{query.data ? `${query.data.length} supports` : '-'}</span>
        )}
      </div>
      {query.isError ? (
        <p role="alert">
          Les positions sont indisponibles.{' '}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </p>
      ) : null}
      {query.data?.length === 0 ? <p>Aucune position détenue à cette date.</p> : null}
      {query.data && query.data.length > 20 ? (
        <p role="alert">Le contrat dépasse la limite de 20 supports par opération.</p>
      ) : null}
    </div>
  );
}
