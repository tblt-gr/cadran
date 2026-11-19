import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { readPortfolioPositionLots, type PortfolioPosition } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
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
  const { t, i18n } = useTranslation();
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
      formatAmount(amount.value, amount.assetCode, i18n.language)
    ) : (
      <EmptyValue label={t('portfolio.positions.unknownCost')} />
    );

  return (
    <Modal
      intent="detail"
      close={onClose}
      title={t('portfolio.positions.lots.title', { name: position.instrument.name })}
    >
      <div className={styles.content}>
        <p>
          <InstrumentLink instrument={position.instrument} />
        </p>
        <dl className={styles.figures}>
          <div>
            <dt>{t('portfolio.positions.lots.heldQuantity')}</dt>
            <dd>{formatDecimal(position.quantity, i18n.language)}</dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.lots.fifoCost')}</dt>
            <dd>{cost(position.fifoCostBasis)}</dd>
          </div>
          <div>
            <dt>{t('portfolio.positions.lots.avgCost')}</dt>
            <dd>{cost(position.averageCostBasis)}</dd>
          </div>
        </dl>
        {lots.isPending ? (
          <PositionLotsLists accountId={accountId} lots={null} />
        ) : lots.isError ? (
          <div className={styles.errorState}>
            <p role="alert">{t('portfolio.positions.lots.unavailable')}</p>
            <button className="secondary-action" onClick={() => void lots.refetch()} type="button">
              {t('actions.retry')}
            </button>
          </div>
        ) : (
          <PositionLotsLists accountId={accountId} lots={lots.data} />
        )}
        <p className={styles.note}>{t('portfolio.positions.lots.note')}</p>
      </div>
    </Modal>
  );
}
