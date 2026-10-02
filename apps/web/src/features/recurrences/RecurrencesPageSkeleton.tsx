import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus, SkeletonTitle } from '@/components/ui/skeleton/Skeleton';
import cardStyles from './candidate-list/CandidateCard.module.css';
import scheduleStyles from './recurrence-card/RecurrenceCard.module.css';
import styles from './RecurrencesPage.module.css';

function CandidateCardSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-hidden="true" className={`card ${cardStyles.card}`}>
      <div className={cardStyles.heading}>
        <div>
          <SkeletonTitle />
          <SkeletonBlock className={styles.skeletonLine} />
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
        <button className="primary-action" disabled type="button">
          {t('recurrences.candidates.confirm')}
        </button>
      </div>
    </div>
  );
}

function ScheduleCardSkeleton() {
  const { t } = useTranslation();
  return (
    <div aria-hidden="true" className={`card ${scheduleStyles.schedule}`}>
      <div>
        <SkeletonTitle />
        <SkeletonBlock className={styles.skeletonLine} />
        <SkeletonBlock className={styles.skeletonLine} />
      </div>
      <div className={scheduleStyles.scheduleActions}>
        <button className="secondary-action" disabled type="button">
          {t('recurrences.confirmed.occurrences')}
        </button>
        <button className="secondary-action" disabled type="button">
          {t('recurrences.confirmed.edit')}
        </button>
        <button className="secondary-action" disabled type="button">
          {t('recurrences.confirmed.archive')}
        </button>
      </div>
    </div>
  );
}

/** Static headings and buttons are real: they show at once, only the recurrences are pending. */
export function RecurrencesPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.section} data-testid="recurrences-skeleton">
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
          <ScheduleCardSkeleton />
          <ScheduleCardSkeleton />
        </div>
      </section>
    </div>
  );
}
