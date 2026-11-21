import { areAmountsHidden } from '@/lib/amountPrivacy';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import type { PortfolioOperationPreview } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatExactAmount, formatDecimal, isZeroDecimal } from '@/lib/decimal';
import styles from '@/features/portfolio/portfolio-account/operations/operation-form/OperationForm.module.css';

export function SwitchOperationPreview({
  pending,
  preview,
}: {
  pending: boolean;
  preview?: PortfolioOperationPreview;
}) {
  const { i18n, t } = useTranslation();
  const imbalance =
    !areAmountsHidden() && preview?.difference && !isZeroDecimal(preview.difference.value);
  return (
    <>
      {(['expectedIncomingAmount', 'difference'] as const).map((field) => (
        <div className={styles.previewRow} key={field}>
          <span>
            {field === 'difference'
              ? t('portfolio.account.form.switch.difference')
              : t('portfolio.account.form.switch.toReinvest')}
          </span>
          {preview?.[field] ? (
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview[field].value,
                  preview[field].assetCode,
                  i18n.language,
                )}
              />
            </strong>
          ) : pending ? (
            <SkeletonBlock className={styles.previewValue} />
          ) : (
            <span>-</span>
          )}
        </div>
      ))}
      {imbalance ? (
        <StatusBadge tone="warning">{t('portfolio.account.form.switch.unbalanced')}</StatusBadge>
      ) : null}
      <div className={styles.previewRow}>
        <span>{t('portfolio.account.form.switch.feeScale')}</span>
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
            {preview.applicableSwitchFeeFixed != null ? (
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview.applicableSwitchFeeFixed.value,
                  preview.applicableSwitchFeeFixed.assetCode,
                  i18n.language,
                )}
              />
            ) : null}
          </strong>
        ) : (
          <span>{t('portfolio.lifeInsurance.notProvided')}</span>
        )}
      </div>
    </>
  );
}
