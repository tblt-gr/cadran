import type { PortfolioOperationPreview } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatDecimal, isZeroDecimal } from '@/lib/decimal';
import styles from '@/features/portfolio/portfolio-account/operations/operation-form/OperationForm.module.css';

export function SwitchOperationPreview({
  pending,
  preview,
}: {
  pending: boolean;
  preview?: PortfolioOperationPreview;
}) {
  const { i18n } = useTranslation();
  const imbalance = preview?.difference && !isZeroDecimal(preview.difference.value);
  return (
    <>
      {(['expectedIncomingAmount', 'difference'] as const).map((field) => (
        <div className={styles.previewRow} key={field}>
          <span>{field === 'difference' ? 'Écart' : 'À réinvestir'}</span>
          {preview?.[field] ? (
            <strong>
              {formatDecimal(preview[field].value, i18n.language)} {preview[field].assetCode}
            </strong>
          ) : pending ? (
            <SkeletonBlock className={styles.previewValue} />
          ) : (
            <span>-</span>
          )}
        </div>
      ))}
      {imbalance ? (
        <StatusBadge tone="warning">
          Arbitrage déséquilibré : ajustez les supports ou les frais.
        </StatusBadge>
      ) : null}
      <div className={styles.previewRow}>
        <span>Barème (informatif)</span>
        {pending && !preview ? (
          <SkeletonBlock className={styles.previewValue} />
        ) : preview?.applicableSwitchFeeRate != null ||
          preview?.applicableSwitchFeeFixed != null ? (
          <strong>
            {preview.applicableSwitchFeeRate != null
              ? `${formatDecimal(preview.applicableSwitchFeeRate, i18n.language)} %`
              : null}
            {preview.applicableSwitchFeeRate != null && preview.applicableSwitchFeeFixed != null
              ? ' + '
              : null}
            {preview.applicableSwitchFeeFixed != null
              ? `${formatDecimal(preview.applicableSwitchFeeFixed.value, i18n.language)} ${preview.applicableSwitchFeeFixed.assetCode}`
              : null}
          </strong>
        ) : (
          <span>Non renseigné</span>
        )}
      </div>
    </>
  );
}
