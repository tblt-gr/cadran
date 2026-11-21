import { areAmountsHidden } from '@/lib/amountPrivacy';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import type { PortfolioOperationPreview, PortfolioOperationType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatExactAmount, isZeroDecimal } from '@/lib/decimal';
import { SwitchOperationPreview } from './switch-operation-preview/SwitchOperationPreview';
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
  const { i18n, t } = useTranslation();
  return (
    <div aria-live="polite" className={styles.preview}>
      <div className={styles.previewRow}>
        <span>
          {type === 'PREMIUM'
            ? t('portfolio.account.form.preview.remainsCash')
            : t('portfolio.account.form.preview.cashEffect')}
        </span>{' '}
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
      {type === 'EURO_FUND_INTEREST' || type === 'PROFIT_SHARING' ? (
        <div className={styles.previewRow}>
          <span>{t('portfolio.account.form.preview.netCredited')}</span>
          {preview?.netCreditedAmount ? (
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview.netCreditedAmount.value,
                  preview.netCreditedAmount.assetCode,
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
      ) : null}
      {type === 'PARTIAL_SURRENDER' || type === 'FULL_SURRENDER' ? (
        <div className={styles.previewRow}>
          <span>{t('portfolio.account.form.preview.netPaid')}</span>
          {preview?.netPaidAmount ? (
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview.netPaidAmount.value,
                  preview.netPaidAmount.assetCode,
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
      ) : null}
      {type === 'FULL_SURRENDER' ? (
        <div className={styles.previewRow}>
          <span>{t('portfolio.account.form.preview.expectedGross')}</span>
          {preview?.expectedGrossAmount ? (
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview.expectedGrossAmount.value,
                  preview.expectedGrossAmount.assetCode,
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
      ) : null}
      {type === 'SWITCH' ? <SwitchOperationPreview pending={pending} preview={preview} /> : null}
      {showResidual ? (
        <div className={styles.previewRow}>
          <span>{t('portfolio.account.form.preview.rounding')}</span>{' '}
          {preview?.roundingResidual ? (
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  preview.roundingResidual.value,
                  preview.roundingResidual.assetCode,
                  i18n.language,
                )}
              />
            </strong>
          ) : (
            <span>-</span>
          )}
        </div>
      ) : null}
      {(preview?.legs ?? [])
        .filter(
          (leg) =>
            leg.roundingResidual &&
            (areAmountsHidden() || !isZeroDecimal(leg.roundingResidual.value)),
        )
        .map((leg) => (
          <div className={styles.previewRow} key={leg.index}>
            <span>{t('portfolio.account.form.preview.legRounding', { index: leg.index })}</span>
            <strong>
              <MoneyValue
                wrapGroups={false}
                value={formatExactAmount(
                  leg.roundingResidual!.value,
                  leg.roundingResidual!.assetCode,
                  i18n.language,
                )}
              />
            </strong>
          </div>
        ))}
    </div>
  );
}
