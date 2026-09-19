import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { periodClosureErrorKind } from '@/features/closures/periodClosureError';
import styles from './ReopenPeriodForm.module.css';

interface ReopenPeriodFormProps {
  error: unknown;
  failed: boolean;
  onBack: () => void;
  onSubmit: (reason: string) => void;
  pending: boolean;
}

/** Reopens a closed month; the reason is mandatory and goes to the audit trail. */
export function ReopenPeriodForm({
  error,
  failed,
  onBack,
  onSubmit,
  pending,
}: ReopenPeriodFormProps) {
  const { t } = useTranslation();
  const backButton = useRef<HTMLButtonElement>(null);
  const [reason, setReason] = useState('');
  const ready = reason.trim() !== '';

  useEffect(() => {
    backButton.current?.focus();
  }, []);

  function submit(event: FormEvent) {
    event.preventDefault();
    if (ready) onSubmit(reason.trim());
  }

  return (
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
        <button className="secondary-action" onClick={onBack} ref={backButton} type="button">
          {t('closures.back')}
        </button>
        <button className="primary-action" disabled={!ready || pending} type="submit">
          {t('closures.reopen.submit')}
        </button>
      </div>
    </form>
  );
}
