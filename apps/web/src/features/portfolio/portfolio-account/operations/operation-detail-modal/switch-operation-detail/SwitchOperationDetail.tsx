import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatDecimal } from '@/lib/decimal';
import { OperationLegsTable } from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationLegsTable';
import styles from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationDetailModal.module.css';

export function SwitchOperationDetail({ operation }: { operation: PortfolioOperation }) {
  const { i18n } = useTranslation();
  const rate = operation.applicableSwitchFeeRate;
  const fixed = operation.applicableSwitchFeeFixed;
  const amount = (value: { value: string; assetCode: string }) =>
    `${formatDecimal(value.value, i18n.language)} ${value.assetCode}`;
  return (
    <>
      <OperationLegsTable
        caption="Vendus"
        legs={(operation.legs ?? []).filter((leg) => leg.direction === 'OUT')}
      />
      <OperationLegsTable
        caption="Achetés"
        legs={(operation.legs ?? []).filter((leg) => leg.direction === 'IN')}
      />
      <dl className={styles.list}>
        <div className={styles.row}>
          <dt>Frais d’arbitrage</dt>
          <dd>{operation.fees ? amount(operation.fees) : <EmptyValue label="Non renseigné" />}</dd>
        </div>
        <div className={styles.row}>
          <dt>Barème applicable</dt>
          <dd>
            {rate === null && fixed === null ? (
              <EmptyValue label="Non renseigné" />
            ) : (
              <>
                {rate !== null ? `${formatDecimal(rate, i18n.language)} %` : null}
                {rate !== null && fixed !== null ? ' + ' : null}
                {fixed !== null ? amount(fixed) : null} (informatif)
              </>
            )}
          </dd>
        </div>
      </dl>
    </>
  );
}
