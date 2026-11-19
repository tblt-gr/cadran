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
  const { t, i18n } = useTranslation();
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
        menu={<ActionMenu disabled label={t('portfolio.valuations.row.actions')} items={[]} />}
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
  const completeness =
    valuation.completeness === 'COMPLETE'
      ? t('portfolio.valuations.common.complete')
      : t('portfolio.valuations.common.partial');
  const alertCue =
    alerts.length > 0 ? (
      <span
        aria-label={t('portfolio.valuations.row.alertsLabel', { alerts: alerts.join(', ') })}
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
          label={t('portfolio.valuations.row.actionsFor', { date })}
          items={[
            {
              id: 'detail',
              icon: 'info',
              label: t('portfolio.valuations.row.detailOf', { date }),
              text: t('portfolio.valuations.row.detail'),
              onSelect: () => onDetail(valuation),
            },
            {
              id: 'replace',
              icon: 'edit',
              label: t('portfolio.valuations.row.replaceOf', { date }),
              text: t('portfolio.valuations.row.replace'),
              onSelect: () => onReplace(valuation),
            },
            {
              id: 'withdraw',
              icon: 'archive',
              label: t('portfolio.valuations.row.withdrawOf', { date }),
              text: t('portfolio.valuations.row.withdraw'),
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
            {[
              completeness,
              quality
                ? t('portfolio.valuations.row.priceQuality', { quality: quality.toLowerCase() })
                : null,
            ]
              .filter(Boolean)
              .join(' · ')}
            {alertCue}
          </span>
        </small>
      }
      onOpen={() => onDetail(valuation)}
      title={
        <button
          aria-label={t('portfolio.valuations.row.detailOfMode', {
            date,
            mode: entryModeLabel[valuation.detail],
          })}
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
