import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './GoalsPage.module.css';

export type GoalListState = 'active' | 'closed';
interface Props {
  year: number;
  state: GoalListState;
  onYearChange: (year: number) => void;
  onStateChange: (state: GoalListState) => void;
}
/** The year only reaches the query once it is a plausible four-digit year; until then it is flagged. */
export function GoalFilters({ year, state, onYearChange, onStateChange }: Props) {
  const { t } = useTranslation();
  const [yearText, setYearText] = useState(String(year));
  return (
    <section className={`card ${styles.toolbar}`} aria-label={t('goals.filters')}>
      <label>
        <span>{t('goals.year')}</span>
        <input
          aria-invalid={yearText !== String(year) ? true : undefined}
          inputMode="numeric"
          maxLength={4}
          onChange={(event) => {
            setYearText(event.target.value);
            if (/^\d{4}$/.test(event.target.value)) {
              const next = Number.parseInt(event.target.value, 10);
              if (next >= 1900 && next <= 2999) onYearChange(next);
            }
          }}
          value={yearText}
        />
      </label>
      <fieldset>
        <legend>{t('goals.state')}</legend>
        {(['active', 'closed'] as const).map((choice) => (
          <label key={choice}>
            <input
              checked={state === choice}
              name="goal-state"
              onChange={() => onStateChange(choice)}
              type="radio"
            />
            {t(`goals.${choice}`)}
          </label>
        ))}
      </fieldset>
    </section>
  );
}
