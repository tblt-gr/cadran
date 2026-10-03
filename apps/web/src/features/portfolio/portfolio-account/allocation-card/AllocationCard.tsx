import type { PortfolioOverviewAllocation } from '@cadran/api-client';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { compareDecimals, decimalRatioForGeometry, formatAmount } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import type { AllocationBy } from '@/features/portfolio/usePortfolioOverview';
import { reasonLabels } from '@/features/portfolio/portfolio-account/overviewLabels';
import styles from './AllocationCard.module.css';
const choices: { value: AllocationBy; label: string }[] = [
  { value: 'INSTRUMENT', label: 'Instrument' },
  { value: 'ASSET_CLASS', label: 'Classe d’actifs' },
  { value: 'REGION', label: 'Région' },
  { value: 'CURRENCY', label: 'Devise' },
];
export function AllocationCard({
  allocation,
  by,
  onByChange,
  disabled = false,
}: {
  allocation: PortfolioOverviewAllocation | null;
  by: AllocationBy;
  onByChange: (by: AllocationBy) => void;
  disabled?: boolean;
}) {
  const money = (value: string | null) =>
    value === null ? 'Non calculable' : formatAmount(value, allocation!.assetCode, 'fr');
  const label = (key: string, text: string) => (key === 'UNSPECIFIED' ? 'Non renseignée' : text);
  // A signed allocation stays useful in the table but cannot be represented by a donut.
  const signedShares =
    allocation &&
    [...allocation.items, ...allocation.slices].some(
      (item) =>
        item.share !== null &&
        (compareDecimals(item.share, '0') < 0 || compareDecimals(item.share, '1') > 0),
    );
  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby="portfolio-allocation-title"
      aria-busy={!allocation}
      data-testid={!allocation ? 'portfolio-allocation-skeleton' : undefined}
    >
      <h3 id="portfolio-allocation-title">Répartition</h3>
      <div className={styles.controls} role="group" aria-label="Regrouper la répartition">
        {choices.map((choice) => (
          <button
            aria-pressed={by === choice.value}
            className="secondary-action"
            disabled={disabled}
            key={choice.value}
            type="button"
            onClick={() => onByChange(choice.value)}
          >
            {choice.label}
          </button>
        ))}
      </div>
      {!allocation ? (
        <SkeletonBlock className={styles.plotSkeleton} />
      ) : allocation.reason ? (
        <p role="status">
          {allocation.reason === 'PARTIAL_VALUATION'
            ? 'Valorisation partielle : la répartition n’est pas calculable'
            : reasonLabels[allocation.reason]}
        </p>
      ) : signedShares ? (
        <p role="status">
          Graphique indisponible : la répartition contient des parts négatives ou supérieures à 100
          %. Consultez les valeurs signées du tableau.
        </p>
      ) : (
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
      )}
      <div className={styles.tableScroll}>
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
                    {item.share === null
                      ? 'Non calculable'
                      : formatRatioPercentage(item.share, 'fr')}
                  </td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
    </section>
  );
}
