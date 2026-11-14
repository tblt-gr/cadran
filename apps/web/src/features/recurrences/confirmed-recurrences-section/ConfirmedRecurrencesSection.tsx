import type { Recurrence } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { RecurrenceCard } from '@/features/recurrences/recurrence-card/RecurrenceCard';
import styles from '@/features/recurrences/RecurrencesPage.module.css';

interface ConfirmedRecurrencesSectionProps {
  archivePending: boolean;
  onArchive: (recurrence: Recurrence) => void;
  onEdit: (recurrence: Recurrence) => void;
  onSelect: (recurrence: Recurrence) => void;
  recurrences: Recurrence[];
}

/** The confirmed, non-archived recurrences. The page header holds the archived toggle. */
export function ConfirmedRecurrencesSection({
  archivePending,
  onArchive,
  onEdit,
  onSelect,
  recurrences,
}: ConfirmedRecurrencesSectionProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="confirmed-recurrences-title" className={styles.section}>
      <div className={styles.sectionHeading}>
        <h3 id="confirmed-recurrences-title">{t('recurrences.confirmed.title')}</h3>
      </div>
      {recurrences.length === 0 ? (
        <div className={`card ${styles.empty}`}>
          <h4>{t('recurrences.confirmed.emptyTitle')}</h4>
          <p>{t('recurrences.confirmed.emptyDescription')}</p>
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
                      id: 'edit',
                      icon: 'edit',
                      label: t('recurrences.confirmed.editFor', { label: recurrence.label }),
                      text: t('recurrences.confirmed.edit'),
                      onSelect: () => onEdit(recurrence),
                    },
                    {
                      id: 'archive',
                      icon: 'archive',
                      disabled: archivePending,
                      label: t('recurrences.confirmed.archiveFor', { label: recurrence.label }),
                      text: t('recurrences.confirmed.archive'),
                      onSelect: () => onArchive(recurrence),
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
