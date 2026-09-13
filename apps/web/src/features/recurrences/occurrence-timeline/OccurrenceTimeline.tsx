import { listRecurrenceOccurrences, type Recurrence } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { authApiOptions } from '@/features/auth/apiOptions';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './OccurrenceTimeline.module.css';

interface OccurrenceTimelineProps { recurrence: Recurrence; }

export function OccurrenceTimeline({ recurrence }: OccurrenceTimelineProps) {
  const { i18n, t } = useTranslation();
  const occurrences = useQuery({
    queryKey: ['recurrence-occurrences', recurrence.id],
    queryFn: async ({ signal }) => {
      const result = await listRecurrenceOccurrences({ ...authApiOptions(), path: { id: recurrence.id }, signal });
      if (!result.response?.ok || !result.data) throw new Error('occurrences');
      return result.data;
    },
    retry: false,
  });
  if (occurrences.isPending) return <section aria-busy="true" className={`card ${styles.state}`} role="status"><h3>{t('recurrences.occurrences.loading')}</h3></section>;
  if (occurrences.isError) return <section className={`card ${styles.state}`} role="alert"><h3>{t('recurrences.occurrences.error')}</h3><button className="secondary-action" onClick={() => void occurrences.refetch()} type="button">{t('foundation.retry')}</button></section>;
  if (occurrences.data.items.length === 0) return <section className={`card ${styles.state}`}><h3>{t('recurrences.occurrences.empty')}</h3></section>;

  return <section aria-labelledby={`occurrences-${recurrence.id}`} className={`card ${styles.panel}`}>
    <div className={styles.heading}><div><p>{t('recurrences.occurrences.eyebrow')}</p><h3 id={`occurrences-${recurrence.id}`}>{recurrence.label}</h3></div><span>{t('recurrences.occurrences.readOnly')}</span></div>
    <div className={styles.tableScroll}><table><caption className="sr-only">{t('recurrences.occurrences.caption', { label: recurrence.label })}</caption><thead><tr><th scope="col">{t('recurrences.occurrences.date')}</th><th scope="col">{t('recurrences.fields.amount')}</th><th scope="col">{t('recurrences.occurrences.status')}</th><th scope="col">{t('recurrences.occurrences.match')}</th></tr></thead><tbody>{occurrences.data.items.map((occurrence) => <tr key={occurrence.id}><td data-label={t('recurrences.occurrences.date')}>{formatCalendarDay(occurrence.expectedOn, i18n.language)}</td><td data-label={t('recurrences.fields.amount')}>{formatAmount(occurrence.expectedAmount.value, occurrence.expectedAmount.assetCode, i18n.language)}</td><td data-label={t('recurrences.occurrences.status')}><StatusBadge tone={occurrence.status === 'RECEIVED' ? 'positive' : occurrence.status === 'LATE' ? 'negative' : 'info'}>{t(`recurrences.statuses.${occurrence.status}`)}</StatusBadge></td><td data-label={t('recurrences.occurrences.match')}>{occurrence.matchedTransactionId ? t('recurrences.occurrences.matched') : t('recurrences.occurrences.unmatched')}</td></tr>)}</tbody></table></div>
  </section>;
}
