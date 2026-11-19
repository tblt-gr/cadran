import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { PortfolioValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { lineQualityLabel } from '@/features/portfolio/valuations/valuationLabels';
import styles from './ValuationDetailModal.module.css';

/** Frozen lines; a TOTAL valuation keeps quantities but states that its prices are not detailed. */
export function ValuationPositionsTable({ valuation }: { valuation: PortfolioValuation | null }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const detailed = valuation?.detail === 'POSITIONS';
  const notDetailed = <span>{t('portfolio.valuations.lineQuality.NOT_DETAILED')}</span>;

  return (
    <div
      className={styles.scroll}
      role="region"
      aria-label={t('portfolio.valuations.detailModal.valuedPositions')}
      tabIndex={0}
    >
      <table
        aria-label={t('portfolio.valuations.detailModal.valuedPositions')}
        className={styles.positions}
      >
        <thead>
          <tr>
            <th scope="col">{t('portfolio.valuations.positions.instrument')}</th>
            <th className={styles.number} scope="col">
              {t('portfolio.positions.quantity')}
            </th>
            <th className={styles.number} scope="col">
              {t('portfolio.valuations.positions.price')}
            </th>
            <th scope="col">{t('portfolio.valuations.positions.priceDate')}</th>
            <th className={styles.number} scope="col">
              {t('portfolio.valuations.positions.age')}
            </th>
            <th className={styles.number} scope="col">
              {t('portfolio.positions.detail.value')}
            </th>
            <th scope="col">{t('portfolio.valuations.positions.quality')}</th>
          </tr>
        </thead>
        {valuation ? (
          <tbody>
            {valuation.positions.map((line) => (
              <tr key={line.instrument.id}>
                <th scope="row">
                  {line.instrument.name ? (
                    <InstrumentLink
                      instrument={{ id: line.instrument.id, name: line.instrument.name }}
                    />
                  ) : (
                    line.instrument.id
                  )}
                </th>
                <td className={styles.number}>{formatDecimal(line.quantity, locale)}</td>
                <td className={styles.number}>
                  {!detailed ? (
                    notDetailed
                  ) : line.unitPrice ? (
                    formatDecimal(line.unitPrice, locale)
                  ) : (
                    <EmptyValue label={t('portfolio.valuations.positions.unknownPrice')} />
                  )}
                </td>
                <td>
                  {!detailed ? (
                    notDetailed
                  ) : line.priceDate ? (
                    formatCalendarNumericDay(line.priceDate)
                  ) : (
                    <EmptyValue label={t('portfolio.valuations.positions.unknownPrice')} />
                  )}
                </td>
                <td className={styles.number}>
                  {!detailed ? (
                    notDetailed
                  ) : line.priceAgeDays === null ? (
                    <EmptyValue label={t('portfolio.valuations.positions.unknownPrice')} />
                  ) : (
                    t('portfolio.valuations.positions.days', { count: line.priceAgeDays })
                  )}
                </td>
                <td className={styles.number}>
                  {line.marketValue ? (
                    `${formatDecimal(line.marketValue.value, locale)} ${line.marketValue.assetCode}`
                  ) : (
                    <EmptyValue label={t('portfolio.valuations.common.notCalculable')} />
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
