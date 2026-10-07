import type { PortfolioOperation } from '@cadran/api-client';
import styles from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationDetailModal.module.css';

const basisLabels = {
  NET_OF_MANAGEMENT_FEES: 'Net de frais de gestion',
  GROSS_OF_MANAGEMENT_FEES: 'Brut de frais de gestion',
  UNKNOWN: 'Non précisé',
};
const feeLabels = {
  ENTRY: 'Frais sur versement',
  MANAGEMENT: 'Frais de gestion',
  SWITCH: 'Frais d’arbitrage',
  SURRENDER: 'Frais de rachat',
  OTHER: 'Autres frais',
};

export function InsuranceIncomeDetail({ operation }: { operation: PortfolioOperation }) {
  return (
    <>
      {operation.interestBasis ? (
        <div className={styles.row}>
          <dt>Base du taux</dt>
          <dd>{basisLabels[operation.interestBasis]}</dd>
        </div>
      ) : null}
      {operation.rateYear ? (
        <div className={styles.row}>
          <dt>Année du taux</dt>
          <dd>{String(operation.rateYear)}</dd>
        </div>
      ) : null}
      {operation.feeNature ? (
        <div className={styles.row}>
          <dt>Nature des frais</dt>
          <dd>{feeLabels[operation.feeNature]}</dd>
        </div>
      ) : null}
    </>
  );
}
