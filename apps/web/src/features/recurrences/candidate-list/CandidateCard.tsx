import type { RecurrenceCandidate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './CandidateCard.module.css';

interface CandidateCardProps {
  candidate: RecurrenceCandidate;
  onConfirm: (candidate: RecurrenceCandidate) => void;
  onDismiss: (candidate: RecurrenceCandidate) => void;
  pending: boolean;
}

export function CandidateCard({ candidate, onConfirm, onDismiss, pending }: CandidateCardProps) {
  const { i18n, t } = useTranslation();
  const confidence = candidate.confidence
    ? t(`recurrences.confidence.${candidate.confidence}`)
    : t('recurrences.confidence.notDeterminable');
  return (
    <article className={`card ${styles.card}`}>
      <div className={styles.heading}>
        <div>
          <h3>{candidate.counterparty}</h3>
          <p>
            {t(`recurrences.intervals.${candidate.intervalKind}`)} ·{' '}
            {t('recurrences.candidates.observations', { count: candidate.occurrenceCount })}
          </p>
        </div>
        <StatusBadge
          tone={
            candidate.confidence === 'HIGH'
              ? 'positive'
              : candidate.confidence === 'LOW'
                ? 'warning'
                : 'info'
          }
        >
          {confidence}
        </StatusBadge>
      </div>
      <dl className={styles.details}>
        <div>
          <dt>{t('recurrences.fields.amount')}</dt>
          <dd>
            {formatAmount(
              candidate.medianAmount.value,
              candidate.medianAmount.assetCode,
              i18n.language,
            )}
          </dd>
        </div>
        <div>
          <dt>{t('recurrences.fields.tolerance')}</dt>
          <dd>
            {formatAmount(candidate.tolerance.value, candidate.tolerance.assetCode, i18n.language)}
          </dd>
        </div>
        <div>
          <dt>{t('recurrences.candidates.period')}</dt>
          <dd>
            {formatCalendarDay(candidate.firstSeenOn, i18n.language)} —{' '}
            {formatCalendarDay(candidate.lastSeenOn, i18n.language)}
          </dd>
        </div>
      </dl>
      {candidate.confidenceReason ? (
        <p className={styles.reason}>{t('recurrences.confidence.reason')}</p>
      ) : null}
      <div className={styles.actions}>
        <button
          className="secondary-action"
          disabled={pending}
          onClick={() => onDismiss(candidate)}
          type="button"
        >
          {t('recurrences.candidates.dismiss')}
        </button>
        <button
          className="primary-action"
          disabled={pending}
          onClick={() => onConfirm(candidate)}
          type="button"
        >
          {t('recurrences.candidates.confirm')}
        </button>
      </div>
    </article>
  );
}
