import type { Recurrence } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { RecurrenceCard } from '@/features/recurrences/recurrence-card/RecurrenceCard';
import styles from '@/features/recurrences/RecurrencesPage.module.css';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { ScheduleCardSkeleton } from '@/features/recurrences/RecurrencesPageSkeleton';

interface ArchivedRecurrencesSectionProps {
  isError: boolean;
  isPending: boolean;
  onRestore: (recurrence: Recurrence) => void;
  onRetry: () => void;
  onSelect: (recurrence: Recurrence) => void;
  recurrences: Recurrence[];
  restorePending: boolean;
}

/**
 * The archived recurrences, fetched only once the caller reveals them: they have their own
 * loading, error and empty states rather than blocking the confirmed section above them.
 */
export function ArchivedRecurrencesSection({
  isError,
  isPending,
  onRestore,
  onRetry,
  onSelect,
  recurrences,
  restorePending,
}: ArchivedRecurrencesSectionProps) {
  const { t } = useTranslation();

  return (
    <section
      aria-labelledby="archived-recurrences-title"
      className={styles.section}
      id="archived-recurrences"
    >
      <div className={styles.sectionHeading}>
        <h3 id="archived-recurrences-title">{t('recurrences.confirmed.archived')}</h3>
      </div>
      {isPending ? (
        <div aria-busy="true" className={styles.skeletonContents}>
          <SkeletonStatus>{t('recurrences.loading')}</SkeletonStatus>
          <ul className={`card ${styles.list}`}>
            <ScheduleCardSkeleton />
            <ScheduleCardSkeleton />
          </ul>
        </div>
      ) : isError ? (
        <div className={`card ${styles.state}`} role="alert">
          <h4>{t('recurrences.error.title')}</h4>
          <button className="secondary-action" onClick={onRetry} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : recurrences.length === 0 ? (
        <div className={`card ${styles.empty}`}>
          <h4>{t('recurrences.confirmed.emptyArchivedTitle')}</h4>
          <p>{t('recurrences.confirmed.emptyArchivedDescription')}</p>
        </div>
      ) : (
        <ul className={`card ${styles.list}`}>
          {recurrences.map((recurrence) => (
            <RecurrenceCard
              actions={
                <ActionMenu
                  items={[
                    {
                      id: 'occurrences',
                      icon: 'calendar',
                      label: t('recurrences.confirmed.occurrencesFor', { label: recurrence.label }),
                      text: t('recurrences.confirmed.occurrences'),
                      onSelect: () => onSelect(recurrence),
                    },
                    {
                      id: 'restore',
                      icon: 'repeat',
                      disabled: restorePending,
                      label: t('recurrences.confirmed.restoreFor', { label: recurrence.label }),
                      text: t('recurrences.confirmed.restore'),
                      onSelect: () => onRestore(recurrence),
                    },
                  ]}
                  label={t('recurrences.actionsFor', { label: recurrence.label })}
                />
              }
              key={recurrence.id}
              recurrence={recurrence}
            />
          ))}
        </ul>
      )}
    </section>
  );
}
