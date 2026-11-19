import { listPortfolioPositions, readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
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
        {t('portfolio.account.form.legs.takeAll')}
      </button>
      <div aria-busy={query.isPending} className={styles.summary}>
        <span>{t('portfolio.account.form.legs.toTake')}</span>
        {query.isPending ? (
          <>
            <SkeletonStatus>{t('portfolio.account.form.legs.loadingPositions')}</SkeletonStatus>
            <SkeletonBlock className={styles.summaryValue} />
          </>
        ) : (
          <span>
            {query.data
              ? t('portfolio.account.form.legs.supportCount', { count: query.data.length })
              : '-'}
          </span>
        )}
      </div>
      {query.isError ? (
        <p role="alert">
          {t('portfolio.account.form.legs.positionsUnavailable')}{' '}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            {t('actions.retry')}
          </button>
        </p>
      ) : null}
      {query.data?.length === 0 ? <p>{t('portfolio.account.form.legs.noneHeld')}</p> : null}
      {query.data && query.data.length > 20 ? (
        <p role="alert">{t('portfolio.account.form.legs.limit')}</p>
      ) : null}
    </div>
  );
}
