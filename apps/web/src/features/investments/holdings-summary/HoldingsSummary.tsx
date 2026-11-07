import type { Holdings } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { holdingsDay, holdingsMoney } from '@/features/investments/holdingsFormat';
import styles from './HoldingsSummary.module.css';

function Card({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className={`card ${styles.card}`}>
      <h3>{label}</h3>
      {children}
    </div>
  );
}

/** Totals of every holding, each with its own reason when it cannot be calculated. */
export function HoldingsSummary({ holdings }: { holdings: Holdings | null }) {
  const { t } = useTranslation();
  const money = (value: string | null) =>
    value === null ? (
      <EmptyValue label={t('investments.summary.notCalculable')} />
    ) : (
      holdingsMoney(value, holdings!.assetCode)
    );
  const totals = holdings?.totals;
  const mixed = totals?.reason === 'MIXED_ASSETS';
  const mixedDates = holdings?.warnings.some((warning) => warning.code === 'MIXED_VALUATION_DATES');

  return (
    <section aria-label={t('investments.summary.label')} className={styles.summary}>
      <div className={styles.cards}>
        <Card label={t('investments.summary.securities')}>
          <p className={styles.value}>
            {totals ? money(totals.securitiesValue) : <SkeletonBlock className={styles.block} />}
          </p>
        </Card>
        <Card label={t('investments.summary.gain')}>
          <p className={styles.value}>
            {!totals ? (
              <SkeletonBlock className={styles.block} />
            ) : totals.unrealizedGain !== null ? (
              <UnrealizedGain
                assetCode={holdings!.assetCode ?? ''}
                performance={totals.performance}
                value={totals.unrealizedGain}
              />
            ) : totals.reason === 'UNKNOWN_LOT_COST' ? (
              t('investments.table.unknownCost')
            ) : (
              <EmptyValue label={t('investments.table.gainNotCalculable')} />
            )}
          </p>
        </Card>
        <Card label={t('investments.summary.cost')}>
          <p className={styles.value}>
            {totals ? money(totals.costBasis) : <SkeletonBlock className={styles.block} />}
          </p>
        </Card>
        <Card label={t('investments.summary.cash')}>
          <p className={styles.value}>
            {totals ? money(totals.cash) : <SkeletonBlock className={styles.block} />}
          </p>
        </Card>
      </div>
      {mixed ? <p className={styles.note}>{t('investments.summary.mixedAssets')}</p> : null}
      <p className={styles.note}>
        {!holdings ? (
          <SkeletonBlock className={styles.dateBlock} />
        ) : holdings.valuedTo === null ? null : mixedDates ? (
          t('investments.summary.valuedBetween', {
            from: holdingsDay(holdings.valuedFrom!),
            to: holdingsDay(holdings.valuedTo),
          })
        ) : (
          t('investments.summary.valuedOn', { date: holdingsDay(holdings.valuedTo) })
        )}
      </p>
    </section>
  );
}
