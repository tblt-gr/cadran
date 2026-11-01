import type { AccountOverviewComposition } from '@cadran/api-client';
import { Suspense, useId } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import chartStyles from '@/features/accounts/group-composition/GroupComposition.module.css';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatAmount } from '@/lib/decimal';
import { lazyComponent } from '@/lib/lazyComponent';
import { ColorSwatch } from './ColorSwatch';
import { shadeWithinGroups } from './sliceColors';
import styles from './NetWorthCompositionCard.module.css';

// Keeps the donut out of the accounts route chunk: the table and the figures render first.
const GroupCompositionChart = lazyComponent(
  () => import('@/features/accounts/group-composition/GroupCompositionChart'),
  'GroupCompositionChart',
);

interface NetWorthCompositionViewProps {
  /** Null while the composition is being read: the title and headers stay, only values are blocks. */
  composition: AccountOverviewComposition | null;
}

const PLACEHOLDER_ROWS = 3;

/** Donut (or why there is none) beside the table of every account: slices and rows share one source. */
export function NetWorthCompositionView({ composition }: NetWorthCompositionViewProps) {
  const { i18n, t } = useTranslation();
  const headingId = useId();
  const colors =
    composition === null
      ? []
      : shadeWithinGroups(
          composition.accounts.map((account) => ({
            color: account.color,
            groupId: account.primaryGroupId,
          })),
        );
  const entries =
    composition === null
      ? null
      : composition.accounts.map((account, index) => ({
          color: colors[index] ?? null,
          institution: account.institution,
          key: account.id,
          label: account.label,
          share: account.share,
          value: account.value,
        }));

  return (
    <section aria-labelledby={headingId} className={`card ${styles.card}`}>
      <h2 className={styles.title} id={headingId}>
        {t('accounts.overviewComposition.title')}
      </h2>
      <div className={styles.body}>
        <Suspense
          fallback={
            <div className={chartStyles.plot}>
              <SkeletonBlock className={chartStyles.plotSkeleton} />
            </div>
          }
        >
          <GroupCompositionChart
            entries={entries}
            label={t('accounts.overviewComposition.chart')}
            reasonText={
              composition?.chartReason
                ? t(`accounts.overviewComposition.chartReasons.${composition.chartReason}`)
                : null
            }
            total={composition?.total ?? null}
          />
        </Suspense>
        <div className={styles.tableScroll}>
          <table className={styles.table}>
            <caption className="sr-only">{t('accounts.overviewComposition.title')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('accounts.overviewComposition.columns.account')}</th>
                <th scope="col">{t('accounts.overviewComposition.columns.amount')}</th>
                <th scope="col">{t('accounts.overviewComposition.columns.share')}</th>
              </tr>
            </thead>
            <tbody aria-hidden={composition === null ? 'true' : undefined}>
              {composition === null
                ? Array.from({ length: PLACEHOLDER_ROWS }, (_, row) => (
                    <tr key={row}>
                      <th scope="row">
                        <span className={styles.account}>
                          <SkeletonBlock className={styles.swatch} />
                          <span>
                            <SkeletonBlock className={styles.labelSkeleton} />
                          </span>
                        </span>
                      </th>
                      <td>
                        <SkeletonBlock className={styles.valueSkeleton} />
                      </td>
                      <td>
                        <SkeletonBlock className={styles.valueSkeleton} />
                      </td>
                    </tr>
                  ))
                : composition.accounts.map((account, index) => {
                    const shown =
                      account.value === null ? null : (account.value.display ?? account.value);
                    return (
                      <tr key={account.id}>
                        <th scope="row">
                          <span className={styles.account}>
                            <ColorSwatch color={colors[index] ?? null} />
                            <span>{account.label}</span>
                          </span>
                          {account.institution ? <small>{account.institution}</small> : null}
                        </th>
                        <td className="money" data-visual-mask>
                          {shown === null ? (
                            <EmptyValue label={t('states.notCalculable.label')} />
                          ) : (
                            formatAmount(shown.value, shown.assetCode, i18n.language)
                          )}
                        </td>
                        <td>
                          <ShareCell share={account.share} />
                        </td>
                      </tr>
                    );
                  })}
            </tbody>
            <tfoot>
              <tr>
                <th scope="row">{t('accounts.overviewComposition.columns.total')}</th>
                <td className="money" data-visual-mask>
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
      </div>
    </section>
  );
}
