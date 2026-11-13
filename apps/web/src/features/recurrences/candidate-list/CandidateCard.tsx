import type { RecurrenceCandidate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { RecurrenceAmount } from '@/features/recurrences/recurrence-row/RecurrenceAmount';
import { RecurrenceRow } from '@/features/recurrences/recurrence-row/RecurrenceRow';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';

interface CandidateCardProps {
  candidate: RecurrenceCandidate;
  onConfirm: (candidate: RecurrenceCandidate) => void;
  onDismiss: (candidate: RecurrenceCandidate) => void;
  pending: boolean;
}

/** One detected proposal as a line; confirming or ignoring it sits in the row menu. */
export function CandidateCard({ candidate, onConfirm, onDismiss, pending }: CandidateCardProps) {
  const { i18n, t } = useTranslation();
  const { counterparty } = candidate;
  const confidence = candidate.confidence
    ? t(`recurrences.confidence.${candidate.confidence}`)
    : t('recurrences.confidence.notDeterminable');
  return (
    <RecurrenceRow
      actions={
        <ActionMenu
          disabled={pending}
          items={[
            {
              id: 'confirm',
              icon: 'check-circle',
              label: t('recurrences.candidates.confirmFor', { label: counterparty }),
              text: t('recurrences.candidates.confirm'),
              onSelect: () => onConfirm(candidate),
            },
            {
              id: 'dismiss',
              icon: 'close',
              label: t('recurrences.candidates.dismissFor', { label: counterparty }),
              text: t('recurrences.candidates.dismiss'),
              onSelect: () => onDismiss(candidate),
            },
          ]}
          label={t('recurrences.actionsFor', { label: counterparty })}
        />
      }
      aside={
        <>
          <span>
            <RecurrenceAmount
              assetCode={candidate.medianAmount.assetCode}
              value={candidate.medianAmount.value}
            />{' '}
            <span
              aria-label={t('recurrences.fields.tolerance')}
              title={t('recurrences.fields.tolerance')}
            >
              ±{' '}
              {formatAmount(
                candidate.tolerance.value,
                candidate.tolerance.assetCode,
                i18n.language,
              )}
            </span>
          </span>
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
        </>
      }
      meta={
        <>
          {t(`recurrences.intervals.${candidate.intervalKind}`)} ·{' '}
          {t('recurrences.candidates.observations', { count: candidate.occurrenceCount })} ·{' '}
          {formatCalendarDay(candidate.firstSeenOn, i18n.language)} -{' '}
          {formatCalendarDay(candidate.lastSeenOn, i18n.language)}
        </>
      }
      note={candidate.confidenceReason ? t('recurrences.confidence.reason') : null}
      title={counterparty}
    />
  );
}
