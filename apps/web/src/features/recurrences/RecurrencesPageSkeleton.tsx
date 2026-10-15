import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import cardStyles from './candidate-list/CandidateCard.module.css';
import scheduleStyles from './recurrence-card/RecurrenceCard.module.css';
import styles from './RecurrencesPage.module.css';

function CandidateCardSkeleton() {
  const { t } = useTranslation();
  return (
    <article aria-hidden="true" className={`card ${cardStyles.card}`}>
      <div className={cardStyles.heading}>
        <div>
          <SkeletonTitle />
          <p>
            <SkeletonBlock className={styles.skeletonLine} />
          </p>
        </div>
        <SkeletonBlock className={styles.skeletonBadge} />
      </div>
      <dl className={cardStyles.details}>
        {(
          [
            'recurrences.fields.amount',
            'recurrences.fields.tolerance',
            'recurrences.candidates.period',
          ] as const
        ).map((key) => (
          <div key={key}>
            <dt>{t(key)}</dt>
            <dd>
              <SkeletonBlock className={styles.skeletonLine} />
            </dd>
          </div>
        ))}
      </dl>
      <div className={cardStyles.actions}>
        <button className="secondary-action" disabled type="button">
          {t('recurrences.candidates.dismiss')}
        </button>
        <button className="secondary-action" disabled type="button">
          {t('recurrences.candidates.confirm')}
        </button>
      </div>
    </article>
  );
}

/** Mirrors RecurrenceCard: the actions are the list's own, real and disabled. */
export function ScheduleCardSkeleton({ actions }: { actions: string[] }) {
  return (
    <article aria-hidden="true" className={`card ${scheduleStyles.schedule}`}>
      <div>
        <SkeletonTitle />
        <p>
          <SkeletonBlock className={styles.skeletonLine} />
        </p>
        <small>
          <SkeletonBlock className={styles.skeletonLine} />
        </small>
      </div>
      <div className={scheduleStyles.scheduleActions}>
        {actions.map((action) => (
          <button className="secondary-action" disabled key={action} type="button">
            {action}
          </button>
        ))}
      </div>
    </article>
  );
}

/**
 * Mirrors CandidatesSection and ConfirmedRecurrencesSection element for
 * element: headings and buttons are real, only the recurrences are pending.
 */
export function RecurrencesPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const confirmedActions = [
    t('recurrences.confirmed.occurrences'),
    t('recurrences.confirmed.edit'),
    t('recurrences.confirmed.archive'),
  ];
  return (
    <div aria-busy="true" className={styles.skeletonContents} data-testid="recurrences-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section className={styles.section}>
        <div className={styles.sectionHeading}>
          <div>
            <p>{t('recurrences.candidates.eyebrow')}</p>
            <h3>{t('recurrences.candidates.title')}</h3>
          </div>
        </div>
        <div className={styles.candidates}>
          <CandidateCardSkeleton />
          <CandidateCardSkeleton />
        </div>
      </section>
      <section className={styles.section}>
        <div className={styles.sectionHeading}>
          <div>
            <p>{t('recurrences.confirmed.eyebrow')}</p>
            <h3>{t('recurrences.confirmed.title')}</h3>
          </div>
          <button className="secondary-action" disabled type="button">
            {t('recurrences.confirmed.showArchived')}
          </button>
        </div>
        <div className={styles.schedules}>
          <ScheduleCardSkeleton actions={confirmedActions} />
          <ScheduleCardSkeleton actions={confirmedActions} />
        </div>
      </section>
    </div>
  );
}
