import type { PortfolioTakeoverPreview } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import styles from './PortfolioTakeoverForm.module.css';

/** Titres, Espèces and Total as the server computed them; never summed in the browser. */
export function TakeoverSummary({
  assetCode,
  failed,
  pending,
  preview,
}: {
  assetCode: string;
  failed: boolean;
  pending: boolean;
  preview: PortfolioTakeoverPreview | undefined;
}) {
  const { t, i18n } = useTranslation();
  const figure = (value: string | null | undefined) =>
    value ? (
      <strong>{formatAmount(value, assetCode, i18n.language)}</strong>
    ) : pending ? (
      <strong>
        <SkeletonBlock className={styles.figure} />
      </strong>
    ) : (
      <strong>
        <EmptyValue label={t('states.notCalculable.label')} />
      </strong>
    );
  return (
    <div
      aria-live="polite"
      className={styles.summary}
      role="group"
      aria-label={t('portfolio.takeover.summaryLabel')}
    >
      <div className={styles.readout}>
        <span>{t('portfolio.takeover.securities')}</span>
        {figure(preview?.securitiesValue)}
      </div>
      <div className={styles.readout}>
        <span>{t('portfolio.takeover.cash')}</span>
        {figure(preview?.cash)}
      </div>
      <div className={styles.readout}>
        <span>{t('portfolio.takeover.total')}</span>
        {figure(preview?.totalValue)}
      </div>
      {failed ? <p className={styles.hint}>{t('portfolio.takeover.previewUnavailable')}</p> : null}
    </div>
  );
}
