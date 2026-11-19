import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatDecimal } from '@/lib/decimal';
import { OperationLegsTable } from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationLegsTable';
import styles from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationDetailModal.module.css';
import { maskAmountText } from '@/lib/amountPrivacy';

export function SwitchOperationDetail({ operation }: { operation: PortfolioOperation }) {
  const { i18n, t } = useTranslation();
  const rate = operation.applicableSwitchFeeRate;
  const fixed = operation.applicableSwitchFeeFixed;
  const amount = (value: { value: string; assetCode: string }) =>
    maskAmountText(`${formatDecimal(value.value, i18n.language)} ${value.assetCode}`);
  return (
    <>
      <OperationLegsTable
        caption={t('portfolio.account.legs.sold')}
        legs={(operation.legs ?? []).filter((leg) => leg.direction === 'OUT')}
      />
      <OperationLegsTable
        caption={t('portfolio.account.legs.bought')}
        legs={(operation.legs ?? []).filter((leg) => leg.direction === 'IN')}
      />
      <dl className={styles.list}>
        <div className={styles.row}>
          <dt>{t('portfolio.account.form.switch.fees')}</dt>
          <dd>
            {operation.fees ? (
              amount(operation.fees)
            ) : (
              <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />
            )}
          </dd>
        </div>
        <div className={styles.row}>
          <dt>{t('portfolio.account.legs.applicableScale')}</dt>
          <dd>
            {rate === null && fixed === null ? (
              <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />
            ) : (
              <>
                {rate !== null ? `${formatDecimal(rate, i18n.language)} %` : null}
                {rate !== null && fixed !== null ? ' + ' : null}
                {fixed !== null ? amount(fixed) : null} {t('portfolio.account.legs.informational')}
              </>
            )}
          </dd>
        </div>
      </dl>
    </>
  );
}
