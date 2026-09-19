import { useEffect, useRef, useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { useSession } from '@/features/auth/useSession';
import { ClosePeriodForm } from '@/features/closures/close-period-form/ClosePeriodForm';
import { ReopenPeriodForm } from '@/features/closures/reopen-period-form/ReopenPeriodForm';
import { usePeriodClosure } from '@/features/closures/usePeriodClosure';
import styles from './PeriodClosurePanel.module.css';

interface PeriodClosurePanelProps {
  close: () => void;
  /** `YYYY-MM`; defaults to the last calendar month, the latest that can have ended. */
  initialPeriod?: string;
  returnFocus?: RefObject<HTMLElement | null>;
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
export function PeriodClosurePanel({
  close: closeModal,
  initialPeriod,
  returnFocus,
}: PeriodClosurePanelProps) {
  const { i18n, t } = useTranslation();
  const [period, setPeriod] = useState(initialPeriod ?? previousMonth());
  const [dialog, setDialog] = useState<Dialog>(null);
  const monthInput = useRef<HTMLInputElement>(null);
  const overviewAction = useRef<HTMLButtonElement>(null);
  const previousDialog = useRef<Dialog>(null);
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
  const title =
    dialog === 'close'
      ? t('closures.close.title', { month: label })
      : dialog === 'reopen'
        ? t('closures.reopen.title', { month: label })
        : t('closures.title');

  useEffect(() => {
    const previous = previousDialog.current;
    previousDialog.current = dialog;
    if (previous === null || dialog !== null) return;

    const focusFrame = window.requestAnimationFrame(() => {
      (overviewAction.current ?? monthInput.current)?.focus();
    });
    return () => window.cancelAnimationFrame(focusFrame);
  }, [dialog]);

  return (
    <Modal
      close={closeModal}
      eyebrow={t('closures.eyebrow')}
      returnFocus={returnFocus}
      title={title}
    >
      {dialog === 'close' && data ? (
        <ClosePeriodForm
          blockers={data.blockers}
          error={close.error}
          failed={close.isError}
          onBack={() => setDialog(null)}
          onSubmit={(body) => close.mutate(body)}
          pending={close.isPending}
        />
      ) : dialog === 'reopen' && data?.closure ? (
        <ReopenPeriodForm
          error={reopen.error}
          failed={reopen.isError}
          onBack={() => setDialog(null)}
          onSubmit={(reason) => reopen.mutate({ reason, version: data.closure?.version ?? 0 })}
          pending={reopen.isPending}
        />
      ) : (
        <div className={styles.panel}>
          <div className={styles.heading}>
            <label className={styles.month}>
              <span>{t('closures.month')}</span>
              <input
                data-autofocus
                onChange={(event) => event.target.value && setPeriod(event.target.value)}
                ref={monthInput}
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
              <button
                className="secondary-action"
                onClick={() => void status.refetch()}
                type="button"
              >
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
                  <button
                    className={`secondary-action ${styles.action}`}
                    onClick={() => open('reopen')}
                    ref={overviewAction}
                    type="button"
                  >
                    {t('closures.reopen.action')}
                  </button>
                ) : data.ended ? (
                  <button
                    className={`primary-action ${styles.action}`}
                    onClick={() => open('close')}
                    ref={overviewAction}
                    type="button"
                  >
                    {t('closures.close.action')}
                  </button>
                ) : null
              ) : session.isSuccess ? (
                <p className={styles.state}>{t('closures.ownerOnly')}</p>
              ) : null}
            </>
          )}
        </div>
      )}
    </Modal>
  );
}
