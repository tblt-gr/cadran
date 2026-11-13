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

/** The detected-candidates section: one line per proposal, nothing when there is none. */
export function CandidatesSection({
  candidates,
  dismissPending,
  onConfirm,
  onDismiss,
  partial,
}: CandidatesSectionProps) {
  const { t } = useTranslation();
  // Nothing detected: the section has nothing to say, so it is not drawn at all.
  if (candidates.length === 0) return null;

  return (
    <section aria-labelledby="recurrence-candidates-title" className={styles.section}>
      <div className={styles.sectionHeading}>
        <h3 id="recurrence-candidates-title">{t('recurrences.candidates.title')}</h3>
        {partial ? (
          <span className={styles.partial}>{t('recurrences.candidates.partial')}</span>
        ) : null}
      </div>
      <ul className={`card ${styles.list}`}>
        {candidates.map((candidate) => (
          <CandidateCard
            candidate={candidate}
            key={candidate.fingerprint}
            onConfirm={onConfirm}
            onDismiss={onDismiss}
            pending={dismissPending}
          />
        ))}
      </ul>
    </section>
  );
}
