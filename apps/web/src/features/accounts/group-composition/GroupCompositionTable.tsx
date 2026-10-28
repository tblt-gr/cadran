import type { AccountGroupComposition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatAmount } from '@/lib/decimal';
import type { CompositionEntry, CompositionView } from './compositionEntries';
import styles from './GroupComposition.module.css';

interface GroupCompositionTableProps {
  /** Null while the composition is being read: only the values are blocks. */
  composition: AccountGroupComposition | null;
  entries: CompositionEntry[] | null;
  view: CompositionView;
}

const PLACEHOLDER_ROWS = 3;

/** Label, exact amount and server share of every entry: the chart's tabular alternative. */
export function GroupCompositionTable({ composition, entries, view }: GroupCompositionTableProps) {
  const { i18n, t } = useTranslation();

  return (
    <div
      className={styles.scroll}
      role="region"
      aria-label={t(`accounts.composition.caption.${view}`)}
      tabIndex={0}
    >
      <table>
        <caption className="sr-only">{t(`accounts.composition.caption.${view}`)}</caption>
        <thead>
          <tr>
            <th scope="col">{t('accounts.composition.columns.label')}</th>
            <th scope="col">{t('accounts.composition.columns.amount')}</th>
            <th scope="col">{t('accounts.composition.columns.share')}</th>
          </tr>
        </thead>
        <tbody>
          {(entries ?? Array.from({ length: PLACEHOLDER_ROWS }, () => null)).map((entry, index) => (
            <tr key={entry?.key ?? index}>
              <th scope="row">
                {entry === null ? (
                  <SkeletonBlock className={styles.labelSkeleton} />
                ) : (
                  <span className={styles.entry}>
                    <GroupMarker color={entry.color} />
                    <span>{entry.label}</span>
                    {entry.institution ? (
                      <small className={styles.institution}>{entry.institution}</small>
                    ) : null}
                  </span>
                )}
              </th>
              <td data-label={t('accounts.composition.columns.amount')}>
                {entry === null ? (
                  <SkeletonBlock className={styles.valueSkeleton} />
                ) : entry.value === null ? (
                  <EmptyValue
                    label={t('states.notCalculable.label')}
                    reason={
                      entry.share.reason
                        ? t(`accountGroups.share.reasons.${entry.share.reason}`)
                        : null
                    }
                  />
                ) : (
                  formatAmount(
                    (entry.value.display ?? entry.value).value,
                    (entry.value.display ?? entry.value).assetCode,
                    i18n.language,
                  )
                )}
              </td>
              <td data-label={t('accounts.composition.columns.share')}>
                {entry === null ? (
                  <SkeletonBlock className={styles.valueSkeleton} />
                ) : (
                  <ShareCell share={entry.share} />
                )}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <th scope="row">{t('accounts.composition.columns.total')}</th>
            <td data-label={t('accounts.composition.columns.amount')}>
              {composition === null ? (
                <SkeletonBlock className={styles.valueSkeleton} />
              ) : (
                <NetWorthFigure amount={composition.total} reason={composition.totalReason} />
              )}
            </td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
