import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import type { GoalPeriodicity } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './GoalsPage.module.css';

interface Props {
  /** Editing a goal keeps its periodicity, start and end: only the value and the label change. */
  locked: boolean;
  periodicity: GoalPeriodicity;
  onPeriodicityChange: (periodicity: GoalPeriodicity) => void;
  start: string;
  onStartChange: (start: string) => void;
  /** Empty while the goal has no end. */
  end: string;
  noEnd: boolean;
  onEndChange: (end: string) => void;
  onNoEndChange: (noEnd: boolean) => void;
  /** The end equals the start: the one-off shortcut is checked. */
  single: boolean;
  onSingleChange: (single: boolean) => void;
  startValid: boolean;
  endValid: boolean;
  /** Id of the form-level validation message, linked to a field only once it is flagged. */
  validationId: string;
  showErrors: boolean;
}

/** Periodicity, first period and optional last period of a goal. */
export function GoalPeriodFields({
  locked,
  periodicity,
  onPeriodicityChange,
  start,
  onStartChange,
  end,
  noEnd,
  onEndChange,
  onNoEndChange,
  single,
  onSingleChange,
  startValid,
  endValid,
  validationId,
  showErrors,
}: Props) {
  const { t } = useTranslation();
  const monthly = periodicity === 'MONTHLY';
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  const periodInput = (
    value: string,
    onChange: (next: string) => void,
    valid: boolean,
    disabled: boolean,
  ) => (
    <input
      aria-describedby={flag(valid) ? validationId : undefined}
      aria-invalid={flag(valid)}
      disabled={disabled}
      inputMode={monthly ? undefined : 'numeric'}
      maxLength={monthly ? undefined : 4}
      onChange={(event) => onChange(event.target.value)}
      type={monthly ? 'month' : 'text'}
      value={value}
    />
  );
  return (
    <>
      <fieldset className={styles.radios} disabled={locked}>
        <legend>{t('goals.periodicity')}</legend>
        {(['MONTHLY', 'YEARLY'] as GoalPeriodicity[]).map((choice) => (
          <label key={choice}>
            <input
              checked={periodicity === choice}
              name="periodicity"
              onChange={() => onPeriodicityChange(choice)}
              type="radio"
            />
            <span>{t(`goals.periodicities.${choice}`)}</span>
          </label>
        ))}
      </fieldset>
      <FormGrid>
        <label>
          <span>{t('goals.start')}</span>
          {periodInput(start, onStartChange, startValid, locked)}
        </label>
        <label>
          <span>{t('goals.end')}</span>
          {periodInput(end, onEndChange, endValid, locked || noEnd)}
        </label>
      </FormGrid>
      <fieldset className={styles.radios} disabled={locked}>
        <legend className="sr-only">{t('goals.endChoices')}</legend>
        <label>
          <input
            checked={noEnd}
            onChange={(event) => onNoEndChange(event.target.checked)}
            type="checkbox"
          />
          <span>{t('goals.noEnd')}</span>
        </label>
        <label>
          <input
            checked={single}
            onChange={(event) => onSingleChange(event.target.checked)}
            type="checkbox"
          />
          <span>{t(monthly ? 'goals.singleMonth' : 'goals.singleYear')}</span>
        </label>
      </fieldset>
    </>
  );
}
