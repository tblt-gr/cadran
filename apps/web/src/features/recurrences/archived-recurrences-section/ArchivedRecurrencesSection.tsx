import type { Recurrence } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RecurrenceCard } from '@/features/recurrences/recurrence-card/RecurrenceCard';
import styles from '@/features/recurrences/RecurrencesPage.module.css';

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
        <div>
          <p>{t('recurrences.confirmed.eyebrow')}</p>
          <h3 id="archived-recurrences-title">{t('recurrences.confirmed.archived')}</h3>
        </div>
      </div>
      {isPending ? (
        <div className={`card ${styles.state}`} role="status">
          <h4>{t('recurrences.loading')}</h4>
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
        <div className={styles.schedules}>
          {recurrences.map((recurrence) => (
            <RecurrenceCard
              actions={
                <>
                  <button
                    className="secondary-action"
                    onClick={() => onSelect(recurrence)}
                    type="button"
                  >
                    {t('recurrences.confirmed.occurrences')}
                  </button>
                  <button
                    className="secondary-action"
                    disabled={restorePending}
                    onClick={() => onRestore(recurrence)}
                    type="button"
                  >
                    {t('recurrences.confirmed.restore')}
                  </button>
                </>
              }
              key={recurrence.id}
              recurrence={recurrence}
            />
          ))}
        </div>
      )}
    </section>
  );
}
