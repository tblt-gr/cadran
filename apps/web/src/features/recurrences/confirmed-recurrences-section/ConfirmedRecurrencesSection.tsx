import type { Recurrence } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RecurrenceCard } from '@/features/recurrences/recurrence-card/RecurrenceCard';
import styles from '@/features/recurrences/RecurrencesPage.module.css';

interface ConfirmedRecurrencesSectionProps {
  archivePending: boolean;
  onArchive: (recurrence: Recurrence) => void;
  onEdit: (recurrence: Recurrence) => void;
  onSelect: (recurrence: Recurrence) => void;
  onToggleArchived: () => void;
  recurrences: Recurrence[];
  showArchived: boolean;
}

/** The confirmed, non-archived recurrences, with the toggle that reveals the archived ones. */
export function ConfirmedRecurrencesSection({
  archivePending,
  onArchive,
  onEdit,
  onSelect,
  onToggleArchived,
  recurrences,
  showArchived,
}: ConfirmedRecurrencesSectionProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="confirmed-recurrences-title" className={styles.section}>
      <div className={styles.sectionHeading}>
        <div>
          <p>{t('recurrences.confirmed.eyebrow')}</p>
          <h3 id="confirmed-recurrences-title">{t('recurrences.confirmed.title')}</h3>
        </div>
        <button
          aria-controls="archived-recurrences"
          aria-expanded={showArchived}
          className="secondary-action"
          onClick={onToggleArchived}
          type="button"
        >
          {t(
            showArchived
              ? 'recurrences.confirmed.hideArchived'
              : 'recurrences.confirmed.showArchived',
          )}
        </button>
      </div>
      {recurrences.length === 0 ? (
        <div className={`card ${styles.empty}`}>
          <h4>{t('recurrences.confirmed.emptyTitle')}</h4>
          <p>{t('recurrences.confirmed.emptyDescription')}</p>
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
                    onClick={() => onEdit(recurrence)}
                    type="button"
                  >
                    {t('recurrences.confirmed.edit')}
                  </button>
                  <button
                    className="secondary-action"
                    disabled={archivePending}
                    onClick={() => onArchive(recurrence)}
                    type="button"
                  >
                    {t('recurrences.confirmed.archive')}
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
