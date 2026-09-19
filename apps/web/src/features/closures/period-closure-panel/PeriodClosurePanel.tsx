import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { useSession } from '@/features/auth/useSession';
import { ClosePeriodModal } from '@/features/closures/close-period-modal/ClosePeriodModal';
import { ReopenPeriodModal } from '@/features/closures/reopen-period-modal/ReopenPeriodModal';
import { usePeriodClosure } from '@/features/closures/usePeriodClosure';
import styles from './PeriodClosurePanel.module.css';

interface PeriodClosurePanelProps {
  /** `YYYY-MM`; defaults to the last calendar month, the latest that can have ended. */
  initialPeriod?: string;
}

function previousMonth(): string {
  const now = new Date();
  const date = new Date(now.getFullYear(), now.getMonth() - 1, 1);

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

type Dialog = 'close' | 'reopen' | null;

/**
 * Shows whether a month is closed and, for the workspace owner, closes or
 * reopens it. The server decides what blocks a closing and whether a month has
 * ended; this panel only relays those facts.
 */
export function PeriodClosurePanel({ initialPeriod }: PeriodClosurePanelProps) {
  const { i18n, t } = useTranslation();
  const [period, setPeriod] = useState(initialPeriod ?? previousMonth());
  const [dialog, setDialog] = useState<Dialog>(null);
  const session = useSession();
  const owner = session.data?.workspace?.role === 'OWNER';
  const { close, reopen, status } = usePeriodClosure(period, () => setDialog(null));
  const label = new Intl.DateTimeFormat(i18n.language, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${period}-01T12:00:00Z`));

  function open(next: Exclude<Dialog, null>) {
    close.reset();
    reopen.reset();
    setDialog(next);
  }

  const data = status.data;

  return (
    <section aria-labelledby="period-closure-title" className={`card ${styles.panel}`}>
      <div className={styles.heading}>
        <h3 id="period-closure-title">{t('closures.title')}</h3>
        <label className={styles.month}>
          <span>{t('closures.month')}</span>
          <input
            onChange={(event) => event.target.value && setPeriod(event.target.value)}
            type="month"
            value={period}
          />
        </label>
      </div>

      {status.isPending ? (
        <p className={styles.state} role="status">
          {t('closures.loading')}
        </p>
      ) : status.isError || data === undefined ? (
        <div className={styles.alert} role="alert">
          <p>{t('closures.errors.load')}</p>
          <button className="secondary-action" onClick={() => void status.refetch()} type="button">
            {t('foundation.retry')}
          </button>
        </div>
      ) : (
        <>
          <div className={styles.summary}>
            {data.closed ? (
              <StatusBadge icon="alert" tone="warning">
                {t('closures.closed')}
              </StatusBadge>
            ) : (
              <StatusBadge tone="info">{t('closures.open')}</StatusBadge>
            )}
            {!data.closed && !data.ended ? (
              <span className={styles.state}>{t('closures.notEnded')}</span>
            ) : null}
            {!data.closed && data.blockers.length > 0 ? (
              <span className={styles.state}>
                {t('closures.blockersCount', { count: data.blockers.length })}
              </span>
            ) : null}
          </div>

          {owner ? (
            data.closed && data.closure ? (
              <button className="secondary-action" onClick={() => open('reopen')} type="button">
                {t('closures.reopen.action')}
              </button>
            ) : data.ended ? (
              <button className="primary-action" onClick={() => open('close')} type="button">
                {t('closures.close.action')}
              </button>
            ) : null
          ) : session.isSuccess ? (
            <p className={styles.state}>{t('closures.ownerOnly')}</p>
          ) : null}

          {dialog === 'close' ? (
            <ClosePeriodModal
              blockers={data.blockers}
              close={() => setDialog(null)}
              error={close.error}
              failed={close.isError}
              label={label}
              onSubmit={(body) => close.mutate(body)}
              pending={close.isPending}
            />
          ) : null}
          {dialog === 'reopen' && data.closure ? (
            <ReopenPeriodModal
              close={() => setDialog(null)}
              error={reopen.error}
              failed={reopen.isError}
              label={label}
              onSubmit={(reason) => reopen.mutate({ reason, version: data.closure?.version ?? 0 })}
              pending={reopen.isPending}
            />
          ) : null}
        </>
      )}
    </section>
  );
}
