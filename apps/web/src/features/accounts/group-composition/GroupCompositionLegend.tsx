import type { AccountGroupComposition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { groupColor } from '@/features/accounts/group-color';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatAmount } from '@/lib/decimal';
import { formatSharePercent } from '@/lib/formatSharePercent';
import type { CompositionEntry, CompositionView } from './compositionEntries';
import styles from './GroupComposition.module.css';

interface GroupCompositionLegendProps {
  /** Null while the composition is being read: only the values are blocks. */
  composition: AccountGroupComposition | null;
  entries: CompositionEntry[] | null;
  view: CompositionView;
}

const PLACEHOLDER_ROWS = 3;

/** Label, exact amount, server share and bar of every entry: the chart's textual alternative. */
export function GroupCompositionLegend({
  composition,
  entries,
  view,
}: GroupCompositionLegendProps) {
  const { i18n, t } = useTranslation();
  const notCalculable = t('states.notCalculable.label');

  const items =
    entries === null
      ? Array.from({ length: PLACEHOLDER_ROWS }, (_, index) => ({
          amount: <SkeletonBlock className={styles.valueSkeleton} />,
          barPercent: null,
          key: String(index),
          label: <SkeletonBlock className={styles.labelSkeleton} />,
          share: <SkeletonBlock className={styles.valueSkeleton} />,
        }))
      : entries.map((entry) => {
          const shown = entry.value === null ? null : (entry.value.display ?? entry.value);
          return {
            amount:
              shown === null ? (
                <EmptyValue
                  label={notCalculable}
                  reason={
                    entry.share.reason
                      ? t(`accountGroups.share.reasons.${entry.share.reason}`)
                      : null
                  }
                />
              ) : (
                formatAmount(shown.value, shown.assetCode, i18n.language)
              ),
            barPercent: entry.share.percentDisplay,
            color: groupColor(entry.color),
            key: entry.key,
            label: (
              <>
                <GroupMarker color={entry.color} />
                <span>{entry.label}</span>
                {entry.institution ? (
                  <small className={styles.institution}>{entry.institution}</small>
                ) : null}
              </>
            ),
            share:
              entry.share.percentDisplay === null ? (
                <EmptyValue label={notCalculable} />
              ) : (
                formatSharePercent(entry.share.percentDisplay, { fractionDigits: 2 })
              ),
          };
        });

  return (
    <div className={styles.legendBlock}>
      <AllocationLegend items={items} label={t(`accounts.composition.caption.${view}`)} />
      <p className={styles.total}>
        <span>{t('accounts.composition.columns.total')}</span>
        {composition === null ? (
          <SkeletonBlock className={styles.valueSkeleton} />
        ) : (
          <strong className="money" data-visual-mask>
            <NetWorthFigure amount={composition.total} reason={composition.totalReason} />
          </strong>
        )}
      </p>
    </div>
  );
}
