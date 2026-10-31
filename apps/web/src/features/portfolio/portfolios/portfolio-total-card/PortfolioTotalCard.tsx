import type { PortfolioOverviewList } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import {
  UnrealizedGain,
  type GainReason,
} from '@/features/portfolio/portfolios/unrealized-gain/UnrealizedGain';
import styles from './PortfolioTotalCard.module.css';

type Total = PortfolioOverviewList['total'];

function unvaluedNote(count: number): string | null {
  if (count === 0) return null;
  return count === 1 ? '1 compte non valorisé' : `${count} comptes non valorisés`;
}

function Figure({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className={styles.figure}>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

/** Total value, unrealized gain and performance across the shown accounts; `null` while loading. */
export function PortfolioTotalCard({ total }: { total: Total | null }) {
  const note = total === null ? null : unvaluedNote(total.unvaluedAccounts);
  const mixed = total?.reason === 'MIXED_ASSETS';
  const gainReason: GainReason =
    total?.reason === 'INCOMPLETE_GAIN' ||
    total?.reason === 'NO_VALUATION' ||
    total?.reason === 'MIXED_ASSETS'
      ? total.reason
      : null;

  return (
    <section className={`card ${styles.card}`} aria-labelledby="portfolio-total-title">
      <h3 id="portfolio-total-title">Total investissements</h3>
      <dl className={styles.figures}>
        <Figure label="Valeur totale">
          {total === null ? (
            <SkeletonBlock className={styles.block} />
          ) : total.totalValue === null || total.assetCode === null ? (
            <EmptyValue label={mixed ? 'Actifs différents' : 'Aucune valorisation'} />
          ) : (
            <span className={styles.value}>
              {formatAmount(total.totalValue, total.assetCode, 'fr')}
            </span>
          )}
        </Figure>
        <Figure label="Plus-value latente">
          {total === null ? (
            <SkeletonBlock className={styles.block} />
          ) : (
            <UnrealizedGain
              assetCode={total.assetCode ?? ''}
              performance={total.performance}
              reason={gainReason}
              value={total.unrealizedGain}
            />
          )}
        </Figure>
      </dl>
      {total === null ? (
        <SkeletonBlock className={styles.noteBlock} />
      ) : (
        <>
          {total.oldestValuedOn === null ? null : (
            <p className={styles.note}>
              Valorisations au {formatCalendarDay(total.oldestValuedOn, 'fr')}
            </p>
          )}
          {note === null ? null : <p className={styles.note}>{note}</p>}
        </>
      )}
    </section>
  );
}
