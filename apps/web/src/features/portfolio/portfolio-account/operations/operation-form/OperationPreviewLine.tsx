import type { PortfolioOperationPreview } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import styles from './OperationForm.module.css';

/** The read-only figures the server computed: cash effect and, for buys and sells, the residual. */
export function OperationPreviewLine({
  pending,
  preview,
  showResidual,
}: {
  pending: boolean;
  preview?: PortfolioOperationPreview;
  showResidual: boolean;
}) {
  const { i18n } = useTranslation();
  return (
    <div aria-live="polite" className={styles.preview}>
      <p>
        <span>Effet sur les espèces</span>{' '}
        {preview ? (
          <strong>
            <CashAmount amount={preview.cashAmount} />
          </strong>
        ) : (
          <span>{pending ? 'Calcul en cours…' : '-'}</span>
        )}
      </p>
      {showResidual ? (
        <p>
          <span>Écart d’arrondi</span>{' '}
          {preview?.roundingResidual ? (
            <strong>
              {formatDecimal(preview.roundingResidual.value, i18n.language)}{' '}
              {preview.roundingResidual.assetCode}
            </strong>
          ) : (
            <span>-</span>
          )}
        </p>
      ) : null}
    </div>
  );
}
