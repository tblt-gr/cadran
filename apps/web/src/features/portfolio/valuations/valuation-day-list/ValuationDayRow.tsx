import type { PortfolioValuationSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  entryModeLabel,
  flagLabel,
  summaryQualityLabel,
} from '@/features/portfolio/valuations/valuationLabels';
import styles from './ValuationDayRow.module.css';

export interface ValuationRowActions {
  onDetail: (valuation: PortfolioValuationSummary) => void;
  onReplace: (valuation: PortfolioValuationSummary) => void;
  onWithdraw: (valuation: PortfolioValuationSummary) => void;
}

interface ValuationDayRowProps extends ValuationRowActions {
  /** Null while loading: the row keeps its final structure and shows blocks for the values. */
  valuation: PortfolioValuationSummary | null;
}

/** Entry mode, quality badges and alert cue on the left, the total value on the right. */
export function ValuationDayRow({
  onDetail,
  onReplace,
  onWithdraw,
  valuation,
}: ValuationDayRowProps) {
  const { i18n } = useTranslation();
  if (valuation === null)
    return (
      <DatedRow
        meta={
          <small>
            <span className={styles.metaLine}>
              <SkeletonBlock />
            </span>
          </small>
        }
        menuOnPhone
        menu={<ActionMenu disabled label="Actions de la valorisation" items={[]} />}
        title={
          <button className={styles.open} disabled type="button">
            <SkeletonBlock />
          </button>
        }
        value={<SkeletonBlock />}
      />
    );

  const date = formatCalendarNumericDay(valuation.valuedOn);
  const quality = summaryQualityLabel[valuation.priceQuality];
  const alerts = valuation.flags.map((flag) => flagLabel[flag]);
  const completeness = valuation.completeness === 'COMPLETE' ? 'Complète' : 'Partielle';
  const alertCue =
    alerts.length > 0 ? (
      <span
        aria-label={`Alertes : ${alerts.join(', ')}`}
        className={styles.alert}
        role="img"
        title={alerts.join(', ')}
      >
        <Icon name="alert" size={14} />
      </span>
    ) : null;
  return (
    <DatedRow
      dataAttributes={{ 'data-valuation-id': valuation.id }}
      menuOnPhone
      menu={
        <ActionMenu
          label={`Actions pour la valorisation du ${date}`}
          items={[
            {
              id: 'detail',
              icon: 'info',
              label: `Détail de la valorisation du ${date}`,
              text: 'Détail',
              onSelect: () => onDetail(valuation),
            },
            {
              id: 'replace',
              icon: 'edit',
              label: `Remplacer la valorisation du ${date}`,
              text: 'Remplacer',
              onSelect: () => onReplace(valuation),
            },
            {
              id: 'withdraw',
              icon: 'archive',
              label: `Retirer la valorisation du ${date}`,
              text: 'Retirer',
              onSelect: () => onWithdraw(valuation),
            },
          ]}
        />
      }
      meta={
        <small>
          <span className={styles.metaLine}>
            {valuation.completeness === 'COMPLETE' ? null : (
              <span className={styles.alert}>
                <Icon name="alert" size={12} />
              </span>
            )}
            {[completeness, quality ? `Prix ${quality.toLowerCase()}` : null]
              .filter(Boolean)
              .join(' · ')}
            {alertCue}
          </span>
        </small>
      }
      onOpen={() => onDetail(valuation)}
      title={
        <button
          aria-label={`Détail de la valorisation du ${date}, ${entryModeLabel[valuation.detail]}`}
          className={styles.open}
          onClick={() => onDetail(valuation)}
          type="button"
        >
          {entryModeLabel[valuation.detail]}
        </button>
      }
      value={
        valuation.totalValue ? (
          <MoneyValue
            value={formatAmount(
              valuation.totalValue.value,
              valuation.totalValue.assetCode,
              i18n.language,
            )}
          />
        ) : null
      }
    />
  );
}
