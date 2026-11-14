import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import rowStyles from './recurrence-row/RecurrenceRow.module.css';
import styles from './RecurrencesPage.module.css';

/** Mirrors RecurrenceRow: title, meta, one figure block and the row menu trigger. */
export function ScheduleCardSkeleton() {
  return (
    <li aria-hidden="true" className={rowStyles.row}>
      <div className={rowStyles.main}>
        <h4 className={rowStyles.title}>
          <SkeletonTitle />
        </h4>
        <p className={rowStyles.meta}>
          <SkeletonBlock className={styles.skeletonLine} />
        </p>
      </div>
      <div className={rowStyles.aside}>
        <SkeletonBlock className={styles.skeletonBadge} />
      </div>
      <div className={rowStyles.actions}>
        <SkeletonBlock className={styles.skeletonBadge} />
      </div>
    </li>
  );
}

/**
 * Mirrors CandidatesSection and ConfirmedRecurrencesSection element for
 * element: headings and buttons are real, only the recurrences are pending.
 */
export function RecurrencesPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.skeletonContents} data-testid="recurrences-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section className={styles.section}>
        <div className={styles.sectionHeading}>
          <h3>{t('recurrences.candidates.title')}</h3>
        </div>
        <ul className={`card ${styles.list}`}>
          <ScheduleCardSkeleton />
          <ScheduleCardSkeleton />
        </ul>
      </section>
      <section className={styles.section}>
        <div className={styles.sectionHeading}>
          <h3>{t('recurrences.confirmed.title')}</h3>
        </div>
        <ul className={`card ${styles.list}`}>
          <ScheduleCardSkeleton />
          <ScheduleCardSkeleton />
        </ul>
      </section>
    </div>
  );
}
