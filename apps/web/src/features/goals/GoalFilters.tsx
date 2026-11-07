import type { GoalPeriodicity } from '@cadran/api-client';
import { useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import styles from './GoalFilters.module.css';

interface Props {
  /** Null keeps both periodicities. */
  periodicity: GoalPeriodicity | null;
  onPeriodicityChange: (periodicity: GoalPeriodicity | null) => void;
  /** False keeps the goals still running, true adds the ended ones. */
  includeEnded: boolean;
  onIncludeEndedChange: (includeEnded: boolean) => void;
}

/**
 * Only the goals still running show by default; the other periodicity and the ended goals sit
 * behind one header button that carries the number of filters changed from that default.
 */
export function GoalFilters({
  periodicity,
  includeEnded,
  onPeriodicityChange,
  onIncludeEndedChange,
}: Props) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const countId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const activeCount = Number(includeEnded) + Number(periodicity !== null);
  return (
    <>
      <HeaderActions>
        <button
          aria-describedby={activeCount > 0 ? countId : undefined}
          aria-expanded={open}
          aria-haspopup="dialog"
          aria-label={t('goals.filtersOpen')}
          className={`icon-button ${styles.trigger}`}
          onClick={() => setOpen(true)}
          ref={trigger}
          title={t('goals.filtersOpen')}
          type="button"
        >
          <Icon name="filter" />
          {activeCount > 0 ? (
            <>
              <span aria-hidden="true" className={styles.badge}>
                {activeCount}
              </span>
              <span className="sr-only" id={countId}>
                {t('goals.filtersActive', { count: activeCount })}
              </span>
            </>
          ) : null}
        </button>
      </HeaderActions>
      {open ? (
        <Modal
          intent="settings"
          close={() => setOpen(false)}
          returnFocus={trigger}
          title={t('goals.filtersTitle')}
        >
          <div className={styles.fields}>
            <label>
              <span>{t('goals.periodicity')}</span>
              <select
                onChange={(event) =>
                  onPeriodicityChange(
                    event.target.value === '' ? null : (event.target.value as GoalPeriodicity),
                  )
                }
                value={periodicity ?? ''}
              >
                <option value="">{t('goals.allPeriodicities')}</option>
                <option value="MONTHLY">{t('goals.periodicities.MONTHLY')}</option>
                <option value="YEARLY">{t('goals.periodicities.YEARLY')}</option>
              </select>
            </label>
            <fieldset className={styles.choices}>
              <legend>{t('goals.scopeFilter')}</legend>
              {[false, true].map((choice) => (
                <label className={styles.check} key={String(choice)}>
                  <input
                    checked={includeEnded === choice}
                    name="goals-scope"
                    onChange={() => onIncludeEndedChange(choice)}
                    type="radio"
                  />
                  <span>{t(choice ? 'goals.allGoals' : 'goals.currentGoals')}</span>
                </label>
              ))}
            </fieldset>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
