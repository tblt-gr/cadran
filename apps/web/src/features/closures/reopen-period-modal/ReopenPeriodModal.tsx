import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { periodClosureErrorKind } from '@/features/closures/periodClosureError';
import styles from './ReopenPeriodModal.module.css';

interface ReopenPeriodModalProps {
  close: () => void;
  error: unknown;
  failed: boolean;
  label: string;
  onSubmit: (reason: string) => void;
  pending: boolean;
}

/** Reopens a closed month; the reason is mandatory and goes to the audit trail. */
export function ReopenPeriodModal({
  close,
  error,
  failed,
  label,
  onSubmit,
  pending,
}: ReopenPeriodModalProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  const ready = reason.trim() !== '';

  function submit(event: FormEvent) {
    event.preventDefault();
    if (ready) onSubmit(reason.trim());
  }

  return (
    <Modal
      close={close}
      eyebrow={t('closures.eyebrow')}
      title={t('closures.reopen.title', { month: label })}
    >
      <form className={styles.form} onSubmit={submit}>
        <p className={styles.note}>{t('closures.reopen.intro')}</p>
        <label className={styles.reason}>
          <span>{t('closures.reopen.reason')}</span>
          <input
            maxLength={200}
            onChange={(event) => setReason(event.target.value)}
            type="text"
            value={reason}
          />
        </label>
        {failed ? (
          <p className={styles.alert} role="alert">
            {t(`closures.errors.${periodClosureErrorKind(error)}`)}
          </p>
        ) : null}
        <div className={styles.actions}>
          <button className="secondary-action" onClick={close} type="button">
            {t('closures.cancel')}
          </button>
          <button className="primary-action" disabled={!ready || pending} type="submit">
            {t('closures.reopen.submit')}
          </button>
        </div>
      </form>
    </Modal>
  );
}
