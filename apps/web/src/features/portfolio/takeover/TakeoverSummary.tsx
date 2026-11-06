import type { PortfolioTakeoverPreview } from '@cadran/api-client';
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
  const figure = (value: string | null | undefined) =>
    value ? (
      <strong>{formatAmount(value, assetCode, 'fr')}</strong>
    ) : pending ? (
      <strong>
        <SkeletonBlock className={styles.figure} />
      </strong>
    ) : (
      <strong>
        <EmptyValue label="Non calculable" />
      </strong>
    );
  return (
    <div
      aria-live="polite"
      className={styles.summary}
      role="group"
      aria-label="Résumé de la reprise"
    >
      <div className={styles.readout}>
        <span>Titres</span>
        {figure(preview?.securitiesValue)}
      </div>
      <div className={styles.readout}>
        <span>Espèces</span>
        {figure(preview?.cash)}
      </div>
      <div className={styles.readout}>
        <span>Total</span>
        {figure(preview?.totalValue)}
      </div>
      {failed ? <p className={styles.hint}>Aperçu indisponible.</p> : null}
    </div>
  );
}
