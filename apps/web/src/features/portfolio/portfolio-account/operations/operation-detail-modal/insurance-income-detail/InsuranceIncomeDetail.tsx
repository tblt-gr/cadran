import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationDetailModal.module.css';

export function InsuranceIncomeDetail({ operation }: { operation: PortfolioOperation }) {
  const { t } = useTranslation();
  return (
    <>
      {operation.interestBasis ? (
        <div className={styles.row}>
          <dt>{t('portfolio.account.form.income.rateBasis')}</dt>
          <dd>{t(`portfolio.lifeInsurance.basis.${operation.interestBasis}`)}</dd>
        </div>
      ) : null}
      {operation.rateYear ? (
        <div className={styles.row}>
          <dt>{t('portfolio.account.form.income.rateYear')}</dt>
          <dd>{String(operation.rateYear)}</dd>
        </div>
      ) : null}
      {operation.feeNature ? (
        <div className={styles.row}>
          <dt>{t('portfolio.account.detail.feeNature')}</dt>
          <dd>{t(`portfolio.account.detail.feeNatures.${operation.feeNature}`)}</dd>
        </div>
      ) : null}
    </>
  );
}
