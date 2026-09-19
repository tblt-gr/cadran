import type {
  ClosePeriodRequest,
  PeriodClosingBlocker,
  PeriodClosingCondition,
} from '@cadran/api-client';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
  periodClosureErrorKind,
  type PeriodClosureErrorKind,
} from '@/features/closures/periodClosureError';
import styles from './ClosePeriodForm.module.css';

interface ClosePeriodFormProps {
  blockers: PeriodClosingBlocker[];
  error: unknown;
  failed: boolean;
  onBack: () => void;
  onSubmit: (body: ClosePeriodRequest) => void;
  pending: boolean;
}

/**
 * Closes a month. Each blocking condition needs its own named confirmation and
 * reason; there is no single "force" switch, and no amount or label is shown.
 */
export function ClosePeriodForm({
  blockers,
  error,
  failed,
  onBack,
  onSubmit,
  pending,
}: ClosePeriodFormProps) {
  const { t } = useTranslation();
  const backButton = useRef<HTMLButtonElement>(null);
  const [confirmed, setConfirmed] = useState<Set<PeriodClosingCondition>>(new Set());
  const [reasons, setReasons] = useState<Partial<Record<PeriodClosingCondition, string>>>({});
  const ready = blockers.every(
    ({ condition }) => confirmed.has(condition) && (reasons[condition] ?? '').trim() !== '',
  );
  const errorKind: PeriodClosureErrorKind | null = failed ? periodClosureErrorKind(error) : null;

  useEffect(() => {
    backButton.current?.focus();
  }, []);

  function toggle(condition: PeriodClosingCondition, checked: boolean) {
    const next = new Set(confirmed);
    if (checked) {
      next.add(condition);
    } else {
      next.delete(condition);
    }
    setConfirmed(next);
  }

  function submit(event: FormEvent) {
    event.preventDefault();
    if (!ready) return;
    const overrides: NonNullable<ClosePeriodRequest['overrides']> = {};
    for (const { condition } of blockers) {
      overrides[condition] = (reasons[condition] ?? '').trim();
    }
    onSubmit({ overrides });
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      {blockers.length === 0 ? (
        <p className={styles.note}>{t('closures.close.noBlockers')}</p>
      ) : (
        <>
          <p className={styles.note}>{t('closures.close.blockersIntro')}</p>
          {blockers.map(({ condition, count }) => (
            <fieldset className={styles.blocker} key={condition}>
              <legend className="sr-only">
                {t(`closures.conditions.${condition}.confirm`, { count })}
              </legend>
              <label className={styles.confirm}>
                <input
                  checked={confirmed.has(condition)}
                  onChange={(event) => toggle(condition, event.target.checked)}
                  type="checkbox"
                />
                <span>{t(`closures.conditions.${condition}.confirm`, { count })}</span>
              </label>
              {confirmed.has(condition) ? (
                <label className={styles.reason}>
                  <span>{t(`closures.conditions.${condition}.reason`)}</span>
                  <input
                    maxLength={200}
                    onChange={(event) =>
                      setReasons({ ...reasons, [condition]: event.target.value })
                    }
                    type="text"
                    value={reasons[condition] ?? ''}
                  />
                </label>
              ) : null}
            </fieldset>
          ))}
        </>
      )}
      {errorKind ? (
        <p className={styles.alert} role="alert">
          {t(`closures.errors.${errorKind}`)}
        </p>
      ) : null}
      <div className={styles.actions}>
        <button className="secondary-action" onClick={onBack} ref={backButton} type="button">
          {t('closures.back')}
        </button>
        <button className="primary-action" disabled={!ready || pending} type="submit">
          {t('closures.close.submit')}
        </button>
      </div>
    </form>
  );
}
