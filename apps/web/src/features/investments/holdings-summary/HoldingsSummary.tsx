import type { Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { holdingsDay, holdingsMoney } from '@/features/investments/holdingsFormat';
import { NotComputable } from '@/features/investments/explain-info/NotComputable';
import { PartialFigure } from '@/features/investments/known-cost-line/PartialFigure';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import styles from './HoldingsSummary.module.css';

/** Known-cost figures replace a missing exact one, never an exact one. */
function showsPartial(exact: string | null | undefined, knownCost: unknown): boolean {
  return exact === null && Boolean(knownCost);
}

/**
 * The four totals in one panel, like the portfolio indicators, each with its own reason when it
 * cannot be calculated. The valuation date closes the panel.
 */
export function HoldingsSummary({ holdings }: { holdings: Holdings | null }) {
  const { t } = useTranslation();
  const assetCode = holdings?.assetCode ?? null;
  const money = (value: string | null) =>
    value === null ? (
      <EmptyValue label={t('investments.summary.notCalculable')} />
    ) : (
      holdingsMoney(value, assetCode)
    );
  const totals = holdings?.totals;
  const knownCost = totals?.knownCost ?? null;
  const partialGain = showsPartial(totals?.unrealizedGain, knownCost);
  const partialCost = showsPartial(totals?.costBasis, knownCost);
  const mixed = totals?.reason === 'MIXED_ASSETS';
  const mixedDates = holdings?.warnings.some((warning) => warning.code === 'MIXED_VALUATION_DATES');
  const gainValue = !totals ? null : totals.unrealizedGain !== null ? (
    <UnrealizedGain
      assetCode={assetCode ?? ''}
      performance={totals.performance}
      value={totals.unrealizedGain}
    />
  ) : knownCost ? (
    <UnrealizedGain
      assetCode={assetCode ?? ''}
      performance={knownCost.performance}
      value={knownCost.unrealizedGain}
    />
  ) : (
    <NotComputable
      figure={t('investments.summary.gain')}
      reason={t(
        totals.reason === 'UNKNOWN_LOT_COST'
          ? 'investments.table.unknownCost'
          : totals.reason === 'MIXED_ASSETS'
            ? 'investments.summary.mixedAssets'
            : 'investments.table.gainNotCalculable',
      )}
    />
  );
  const costValue = !totals ? null : totals.costBasis !== null ? (
    money(totals.costBasis)
  ) : knownCost ? (
    money(knownCost.costBasis)
  ) : (
    <NotComputable
      figure={t('investments.summary.cost')}
      reason={t(
        totals.reason === 'UNKNOWN_LOT_COST'
          ? 'investments.notComputable.costUnknown'
          : totals.reason === 'MIXED_ASSETS'
            ? 'investments.summary.mixedAssets'
            : 'investments.summary.notCalculable',
      )}
    />
  );
  const partialInfo = (figureLabel: string) =>
    knownCost ? (
      <PartialFigure
        assetCode={assetCode}
        figureLabel={figureLabel}
        figures={knownCost}
        placement="corner"
      />
    ) : null;

  return (
    <section
      aria-busy={!holdings}
      aria-label={t('investments.summary.label')}
      className={`card ${styles.summary}`}
    >
      <div className={styles.strip}>
        <KpiFigure
          label={t('investments.summary.securities')}
          size="lead"
          value={totals ? money(totals.securitiesValue) : null}
        />
        <KpiFigure
          extra={partialGain ? partialInfo(t('investments.summary.gain')) : null}
          label={t('investments.summary.gain')}
          reserveCorner={partialGain}
          size="lead"
          value={gainValue}
        />
        <KpiFigure
          extra={partialCost ? partialInfo(t('investments.summary.cost')) : null}
          label={t('investments.summary.cost')}
          reserveCorner={partialCost}
          size="lead"
          value={costValue}
        />
        <KpiFigure
          label={t('investments.summary.cash')}
          size="lead"
          value={totals ? money(totals.cash) : null}
        />
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
