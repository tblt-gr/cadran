import type { RecurrenceCandidate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CandidateCard } from '@/features/recurrences/candidate-list/CandidateCard';
import styles from '@/features/recurrences/RecurrencesPage.module.css';

interface CandidatesSectionProps {
  candidates: RecurrenceCandidate[];
  dismissPending: boolean;
  onConfirm: (candidate: RecurrenceCandidate) => void;
  onDismiss: (candidate: RecurrenceCandidate) => void;
  partial: boolean;
}

/** The detected-candidates section: one card per proposal, or its own empty state. */
export function CandidatesSection({
  candidates,
  dismissPending,
  onConfirm,
  onDismiss,
  partial,
}: CandidatesSectionProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="recurrence-candidates-title" className={styles.section}>
      <div className={styles.sectionHeading}>
        <div>
          <p>{t('recurrences.candidates.eyebrow')}</p>
          <h3 id="recurrence-candidates-title">{t('recurrences.candidates.title')}</h3>
        </div>
        {partial ? (
          <span className={styles.partial}>{t('recurrences.candidates.partial')}</span>
        ) : null}
      </div>
      {candidates.length > 0 ? (
        <div className={styles.candidates}>
          {candidates.map((candidate) => (
            <CandidateCard
              candidate={candidate}
              key={candidate.fingerprint}
              onConfirm={onConfirm}
              onDismiss={onDismiss}
              pending={dismissPending}
            />
          ))}
        </div>
      ) : (
        <div className={`card ${styles.empty}`}>
          <h4>{t('recurrences.candidates.emptyTitle')}</h4>
          <p>{t('recurrences.candidates.emptyDescription')}</p>
        </div>
      )}
    </section>
  );
}
