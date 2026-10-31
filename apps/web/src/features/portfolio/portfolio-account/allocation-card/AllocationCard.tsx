import type { PortfolioOverviewAllocation } from '@cadran/api-client';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { useState } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import {
  compareDecimals,
  decimalRatioForGeometry,
  formatAmount,
  formatCalendarNumericDay,
} from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import type { AllocationBy } from '@/features/portfolio/usePortfolioOverview';
import { reasonLabels } from '@/features/portfolio/portfolio-account/overviewLabels';
import {
  instrumentClasses,
  instrumentRegions,
} from '@/features/portfolio/instruments/instrument-form/instrumentOptions';
import styles from './AllocationCard.module.css';
const choices: { value: AllocationBy; label: string }[] = [
  { value: 'INSTRUMENT', label: 'Instrument' },
  { value: 'ASSET_CLASS', label: 'Classe d’actifs' },
  { value: 'REGION', label: 'Région' },
  { value: 'CURRENCY', label: 'Devise' },
];
/** The server groups by code; classes and regions read with the names used on the instruments. */
const groupNames: Partial<Record<AllocationBy, ReadonlyMap<string, string>>> = {
  ASSET_CLASS: new Map(instrumentClasses),
  REGION: new Map(instrumentRegions),
};
export function AllocationCard({
  allocation,
  title = 'Répartition',
  by,
  onByChange,
  disabled = false,
  showFallbackNotice = true,
}: {
  allocation: PortfolioOverviewAllocation | null;
  title?: string;
  by: AllocationBy;
  onByChange: (by: AllocationBy) => void;
  disabled?: boolean;
  showFallbackNotice?: boolean;
}) {
  const money = (value: string | null) =>
    value === null ? 'Non calculable' : formatAmount(value, allocation!.assetCode, 'fr');
  const [tableOpen, setTableOpen] = useState(false);
  const label = (key: string, text: string) =>
    key === 'UNSPECIFIED'
      ? 'Non renseignée'
      : ((allocation && groupNames[allocation.by]?.get(key)) ?? text);
  // A signed allocation stays useful in the table but cannot be represented by a donut.
  const signedShares =
    allocation &&
    [...allocation.items, ...allocation.slices].some(
      (item) =>
        item.share !== null &&
        (compareDecimals(item.share, '0') < 0 || compareDecimals(item.share, '1') > 0),
    );
  const atCost = allocation?.basis === 'COST';
  // Without a chart the table is the only reading of the allocation, so it stays open.
  const charted = !allocation || (allocation.slices.length > 0 && !signedShares);
  const table = (
    <div
      className={styles.tableScroll}
      role="region"
      aria-label="Répartition détaillée"
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">Répartition détaillée, toutes les lignes</caption>
        <thead>
          <tr>
            <th scope="col">Libellé</th>
            <th scope="col">Valeur</th>
            <th scope="col">Part</th>
          </tr>
        </thead>
        {!allocation ? (
          <SkeletonTableRows columns={3} rows={3} />
        ) : (
          <tbody>
            {allocation.items.map((item) => (
              <tr key={item.key}>
                <th scope="row">{label(item.key, item.label)}</th>
                <td className="money">{money(item.value)}</td>
                <td className="money">
                  {item.share === null ? 'Non calculable' : formatRatioPercentage(item.share, 'fr')}
                </td>
              </tr>
            ))}
          </tbody>
        )}
      </table>
    </div>
  );
  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby="portfolio-allocation-title"
      aria-busy={!allocation}
      data-testid={!allocation ? 'portfolio-allocation-skeleton' : undefined}
    >
      <div className={styles.header}>
        <h3 id="portfolio-allocation-title">{title}</h3>
        <div className={styles.valuationDate}>
          <span>Valorisation retenue :</span>
          {!allocation ? (
            <SkeletonBlock className={styles.dateSkeleton} />
          ) : allocation.valuation ? (
            <time dateTime={allocation.valuation.valuedOn}>
              {formatCalendarNumericDay(allocation.valuation.valuedOn)}
            </time>
          ) : (
            <span>aucune à cette date</span>
          )}
          {atCost ? <span>· au prix de revient</span> : null}
        </div>
      </div>
      {showFallbackNotice && allocation ? (
        <ValuationFallbackNotice
          selected={allocation.valuation}
          newerPartial={allocation.newerPartialValuation}
        />
      ) : null}
      <SegmentedControl
        disabled={disabled}
        label="Regrouper la répartition"
        onChange={onByChange}
        options={choices}
        value={by}
      />
      {!allocation ? (
        <SkeletonBlock className={styles.plotSkeleton} />
      ) : allocation.slices.length === 0 && !signedShares ? (
        <p className={styles.note} role="status">
          {allocation.reason === 'PARTIAL_VALUATION'
            ? 'Valorisation partielle : la répartition n’est pas calculable'
            : reasonLabels[allocation.reason ?? 'NON_POSITIVE_TOTAL']}
        </p>
      ) : signedShares ? (
        <p className={styles.note} role="status">
          Graphique indisponible : la répartition contient des parts négatives ou supérieures à 100
          %. Consultez les valeurs signées du tableau.
        </p>
      ) : (
        <>
          {atCost ? (
            <p className={styles.note}>
              Valorisation saisie en total : la valeur de chaque ligne n’est pas connue, la
              répartition suit le prix de revient des titres détenus.
            </p>
          ) : null}
          <div className={styles.plot}>
            <DonutPlot
              label="Répartition du portefeuille"
              totalText={null}
              slices={allocation.slices.map((item) => ({
                key: item.key,
                caption: label(item.key, item.label),
                amountText: money(item.value),
                size: decimalRatioForGeometry(item.share!, '1'),
              }))}
            />
            <AllocationLegend
              label="Légende de la répartition"
              items={allocation.slices.map((item) => ({
                key: item.key,
                label: label(item.key, item.label),
                amount: money(item.value),
                share: formatRatioPercentage(item.share!, 'fr'),
                barPercent: ratioToPercent(item.share!),
              }))}
            />
          </div>
        </>
      )}
      {allocation && allocation.items.length === 0 ? null : charted ? (
        <Disclosure
          meta={
            allocation
              ? `${allocation.items.length} ${allocation.items.length > 1 ? 'lignes' : 'ligne'}`
              : undefined
          }
          onToggle={setTableOpen}
          open={tableOpen}
          title="Toutes les lignes"
        >
          {table}
        </Disclosure>
      ) : (
        table
      )}
    </section>
  );
}
