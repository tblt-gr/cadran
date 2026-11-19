import type { PortfolioPosition } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { ReorderDayModal } from '@/features/portfolio/portfolio-account/operations/reorder-day-modal/ReorderDayModal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { PositionLotsModal } from './position-lots-modal/PositionLotsModal';
import { PositionDetailModal } from './position-detail-modal/PositionDetailModal';
import { PositionsList } from './positions-list/PositionsList';
import { usePositions } from './usePositions';
import styles from './PositionsTab.module.css';

export function PositionsTab({
  accountId,
  asOf,
  includeClosed = false,
  onTakeover,
}: {
  accountId: string;
  asOf: string;
  /** Owned by the page header, which carries the toggle. */
  includeClosed?: boolean;
  /** Supplied only while the account can still take over the positions it already holds. */
  onTakeover?: () => void;
}) {
  const { t } = useTranslation();
  const [detail, setDetail] = useState<PortfolioPosition | null>(null);
  const [lots, setLots] = useState<PortfolioPosition | null>(null);
  const [reorderingDate, setReorderingDate] = useState<string | null>(null);
  const positions = usePositions(accountId, asOf, includeClosed);

  return (
    <div className={styles.tab}>
      {positions.isPending ? (
        <PositionsList items={null} onOpen={setDetail} onReorder={() => {}} />
      ) : positions.isError ? (
        <OperationsState error title={t('portfolio.account.form.legs.positionsUnavailable')}>
          <button
            className="secondary-action"
            onClick={() => void positions.refetch()}
            type="button"
          >
            {t('actions.retry')}
          </button>
        </OperationsState>
      ) : positions.data.items.length === 0 ? (
        <OperationsState title={t('portfolio.account.positions.none')}>
          <a
            className="secondary-action"
            href={`/portfolios/${accountId}?tab=operations&asOf=${asOf}`}
            onClick={(event) =>
              handleClientNavigation(event, `/portfolios/${accountId}?tab=operations&asOf=${asOf}`)
            }
          >
            {t('portfolio.account.positions.seeOperations')}
          </a>
          {onTakeover ? (
            <button className="primary-action" onClick={onTakeover} type="button">
              {t('portfolio.account.positions.enter')}
            </button>
          ) : null}
        </OperationsState>
      ) : (
        <PositionsList
          items={positions.data.items}
          onOpen={setDetail}
          onReorder={(position) =>
            setReorderingDate(
              position.flags.find((flag) => flag.code === 'ORDER_DEPENDENT_DAY')?.dates[0] ?? null,
            )
          }
        />
      )}
      {detail ? (
        <PositionDetailModal
          onClose={() => setDetail(null)}
          onLots={(position) => {
            setDetail(null);
            setLots(position);
          }}
          position={detail}
        />
      ) : null}
      {lots ? (
        <PositionLotsModal
          accountId={accountId}
          asOf={asOf}
          onClose={() => setLots(null)}
          position={lots}
        />
      ) : null}
      {reorderingDate ? (
        <ReorderDayModal
          accountId={accountId}
          onClose={() => setReorderingDate(null)}
          onSaved={() => void positions.refetch()}
          tradeDate={reorderingDate}
        />
      ) : null}
    </div>
  );
}
