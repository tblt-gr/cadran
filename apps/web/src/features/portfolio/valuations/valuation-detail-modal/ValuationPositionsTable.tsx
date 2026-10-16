import type { PortfolioValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { lineQualityLabel } from '@/features/portfolio/valuations/valuationLabels';
import styles from './ValuationDetailModal.module.css';

/** Frozen lines; a TOTAL valuation keeps quantities but states that its prices are not detailed. */
export function ValuationPositionsTable({ valuation }: { valuation: PortfolioValuation | null }) {
  const { i18n } = useTranslation();
  const locale = i18n.language;
  const detailed = valuation?.detail === 'POSITIONS';
  const notDetailed = <span>Non détaillé</span>;

  return (
    <div className={styles.scroll} role="region" aria-label="Positions valorisées" tabIndex={0}>
      <table aria-label="Positions valorisées" className={styles.positions}>
        <thead>
          <tr>
            <th scope="col">Instrument</th>
            <th className={styles.number} scope="col">
              Quantité
            </th>
            <th className={styles.number} scope="col">
              Prix
            </th>
            <th scope="col">Date du prix</th>
            <th className={styles.number} scope="col">
              Âge
            </th>
            <th className={styles.number} scope="col">
              Valeur
            </th>
            <th scope="col">Qualité</th>
          </tr>
        </thead>
        {valuation ? (
          <tbody>
            {valuation.positions.map((line) => (
              <tr key={line.instrument.id}>
                <th scope="row">{line.instrument.name ?? line.instrument.id}</th>
                <td className={styles.number}>{formatDecimal(line.quantity, locale)}</td>
                <td className={styles.number}>
                  {!detailed ? (
                    notDetailed
                  ) : line.unitPrice ? (
                    formatDecimal(line.unitPrice, locale)
                  ) : (
                    <EmptyValue label="Prix inconnu" />
                  )}
                </td>
                <td>
                  {!detailed ? (
                    notDetailed
                  ) : line.priceDate ? (
                    formatCalendarNumericDay(line.priceDate)
                  ) : (
                    <EmptyValue label="Prix inconnu" />
                  )}
                </td>
                <td className={styles.number}>
                  {!detailed ? (
                    notDetailed
                  ) : line.priceAgeDays === null ? (
                    <EmptyValue label="Prix inconnu" />
                  ) : (
                    `${line.priceAgeDays} j`
                  )}
                </td>
                <td className={styles.number}>
                  {line.marketValue ? (
                    `${formatDecimal(line.marketValue.value, locale)} ${line.marketValue.assetCode}`
                  ) : (
                    <EmptyValue label="Non calculable" />
                  )}
                </td>
                <td>{lineQualityLabel[line.priceQuality]}</td>
              </tr>
            ))}
          </tbody>
        ) : (
          <SkeletonTableRows columns={7} rows={2} />
        )}
      </table>
    </div>
  );
}
