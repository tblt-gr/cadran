import type { PortfolioOperationPreview, PortfolioOperationType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatDecimal, isZeroDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import styles from './OperationForm.module.css';

/** The read-only figures the server computed: cash effect and, for buys and sells, the residual. */
export function OperationPreviewLine({
  type,
  pending,
  preview,
  showResidual,
}: {
  type?: PortfolioOperationType;
  pending: boolean;
  preview?: PortfolioOperationPreview;
  showResidual: boolean;
}) {
  const { i18n } = useTranslation();
  return (
    <div aria-live="polite" className={styles.preview}>
      <div className={styles.previewRow}>
        <span>{type === 'PREMIUM' ? 'Reste en liquidités' : 'Effet sur les espèces'}</span>{' '}
        {preview ? (
          <strong>
            <CashAmount amount={preview.cashAmount} />
          </strong>
        ) : pending ? (
          <SkeletonBlock className={styles.previewValue} />
        ) : (
          <span>-</span>
        )}
      </div>
      {type === 'PARTIAL_SURRENDER' || type === 'FULL_SURRENDER' ? (
        <div className={styles.previewRow}>
          <span>Net versé</span>
          {preview?.netPaidAmount ? (
            <strong>
              {formatDecimal(preview.netPaidAmount.value, i18n.language)}{' '}
              {preview.netPaidAmount.assetCode}
            </strong>
          ) : pending ? (
            <SkeletonBlock className={styles.previewValue} />
          ) : (
            <span>-</span>
          )}
        </div>
      ) : null}
      {type === 'FULL_SURRENDER' ? (
        <div className={styles.previewRow}>
          <span>Montant brut attendu</span>
          {preview?.expectedGrossAmount ? (
            <strong>
              {formatDecimal(preview.expectedGrossAmount.value, i18n.language)}{' '}
              {preview.expectedGrossAmount.assetCode}
            </strong>
          ) : pending ? (
            <SkeletonBlock className={styles.previewValue} />
          ) : (
            <span>-</span>
          )}
        </div>
      ) : null}
      {showResidual ? (
        <div className={styles.previewRow}>
          <span>Écart d’arrondi</span>{' '}
          {preview?.roundingResidual ? (
            <strong>
              {formatDecimal(preview.roundingResidual.value, i18n.language)}{' '}
              {preview.roundingResidual.assetCode}
            </strong>
          ) : (
            <span>-</span>
          )}
        </div>
      ) : null}
      {(preview?.legs ?? [])
        .filter((leg) => leg.roundingResidual && !isZeroDecimal(leg.roundingResidual.value))
        .map((leg) => (
          <div className={styles.previewRow} key={leg.index}>
            <span>Écart d’arrondi du support {leg.index}</span>
            <strong>
              {formatDecimal(leg.roundingResidual!.value, i18n.language)}{' '}
              {leg.roundingResidual!.assetCode}
            </strong>
          </div>
        ))}
    </div>
  );
}
