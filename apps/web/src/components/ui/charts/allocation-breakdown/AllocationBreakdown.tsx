import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './AllocationBreakdown.module.css';

export interface AllocationBreakdownSlice {
  key: string;
  label: ReactNode;
  /** Plain text of the label, for the donut caption. */
  caption: string;
  amountText: string;
  /** The server's share, already formatted; null when it cannot be computed. */
  shareText: string | null;
  /** Geometry only; null keeps the row out of the donut. */
  size: number | null;
  /** A neutral fill for a slice that stands for no category. */
  fill?: string;
}

export type AllocationBreakdownStatus =
  { kind: 'ready' } | { kind: 'notCalculable'; message: string };

export interface AllocationBreakdownProps<C extends string> {
  title: string;
  titleId: string;
  headingLevel?: 2 | 3 | 4;
  criteria: readonly { value: C; label: string }[];
  criterion: C;
  onCriterionChange: (criterion: C) => void;
  disabled?: boolean;
  /** `null` while loading. */
  slices: readonly AllocationBreakdownSlice[] | null;
  totalText: string | null;
  donutLabel: string;
  notice?: ReactNode;
  headerExtra?: ReactNode;
  status?: AllocationBreakdownStatus;
  /** Negative shares left out of the donut; the table still lists them. */
  ignoredCount?: number;
}

/** The panel shared by every allocation: criterion control, donut and always-visible slice table. */
export function AllocationBreakdown<C extends string>({
  title,
  titleId,
  headingLevel = 3,
  criteria,
  criterion,
  onCriterionChange,
  disabled = false,
  slices,
  totalText,
  donutLabel,
  notice,
  headerExtra,
  status = { kind: 'ready' },
  ignoredCount = 0,
}: AllocationBreakdownProps<C>) {
  const { t } = useTranslation();
  const Heading = `h${headingLevel}` as const;
  const loading = slices === null;
  const plotted = (slices ?? []).filter((slice) => slice.size !== null);

  return (
    <section aria-busy={loading} aria-labelledby={titleId} className={`card ${styles.card}`}>
      <div className={styles.header}>
        <Heading id={titleId}>{title}</Heading>
        {headerExtra}
      </div>
      {notice}
      <SegmentedControl
        disabled={disabled || loading}
        label={t('allocation.criterion')}
        onChange={onCriterionChange}
        options={criteria}
        value={criterion}
      />
      <div className={styles.body}>
        {loading ? (
          <SkeletonBlock className={styles.plotSkeleton} />
        ) : status.kind === 'notCalculable' ? (
          <p className={styles.note} role="status">
            {status.message}
          </p>
        ) : plotted.length > 0 ? (
          <div className={styles.plotColumn}>
            <DonutPlot
              label={donutLabel}
              slices={plotted.map((slice) => ({
                key: slice.key,
                caption: slice.caption,
                amountText: slice.amountText,
                size: slice.size!,
                fill: slice.fill,
              }))}
              totalText={totalText}
            />
            {ignoredCount > 0 ? (
              <p className={styles.note} role="status">
                {t('allocation.ignored', { count: ignoredCount })}
              </p>
            ) : null}
          </div>
        ) : ignoredCount > 0 ? (
          <p className={styles.note} role="status">
            {t('allocation.signed')}
          </p>
        ) : null}
        {/* Nothing to list: the status above says so, an empty table would only repeat its head. */}
        {!loading && slices.length === 0 ? null : (
          <div
            className={styles.tableScroll}
            role="region"
            aria-label={t('allocation.caption')}
            tabIndex={0}
          >
            <table>
              <caption className="sr-only">{t('allocation.caption')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('allocation.columns.label')}</th>
                  <th scope="col">{t('allocation.columns.amount')}</th>
                  <th scope="col">{t('allocation.columns.share')}</th>
                </tr>
              </thead>
              {loading ? (
                <SkeletonTableRows columns={3} rows={3} />
              ) : (
                <tbody>
                  {slices.map((slice) => (
                    <tr key={slice.key}>
                      <th scope="row">{slice.label}</th>
                      <td className="money">{slice.amountText}</td>
                      <td className="money">
                        {slice.shareText === null ? (
                          <EmptyValue label={t('states.notCalculable.label')} />
                        ) : (
                          slice.shareText
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              )}
            </table>
          </div>
        )}
      </div>
    </section>
  );
}
