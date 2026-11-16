import type { RecurrenceIntervalKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { INTERVALS, type FormValues } from './recurrenceFormValues';

interface RecurrenceScheduleFieldsProps {
  dayInvalid: boolean;
  dayOfPeriod: string;
  intervalKind: RecurrenceIntervalKind;
  onChange: <K extends keyof FormValues>(field: K, value: FormValues[K]) => void;
}

/** The interval and the day of that interval a recurrence falls on. */
export function RecurrenceScheduleFields({
  dayInvalid,
  dayOfPeriod,
  intervalKind,
  onChange,
}: RecurrenceScheduleFieldsProps) {
  const { t } = useTranslation();

  return (
    <>
      <label>
        <span>{t('recurrences.fields.interval')}</span>
        <select
          onChange={(event) =>
            onChange('intervalKind', event.target.value as RecurrenceIntervalKind)
          }
          value={intervalKind}
        >
          {INTERVALS.map((interval) => (
            <option key={interval} value={interval}>
              {t(`recurrences.intervals.${interval}`)}
            </option>
          ))}
        </select>
      </label>
      <label>
        <span>{t('recurrences.fields.day')}</span>
        <input
          aria-invalid={dayInvalid ? true : undefined}
          inputMode="numeric"
          max={intervalKind === 'WEEKLY' ? 7 : 31}
          min={1}
          onChange={(event) => onChange('dayOfPeriod', event.target.value)}
          type="number"
          value={dayOfPeriod}
        />
        {dayInvalid ? <small>{t('recurrences.validation.day')}</small> : null}
      </label>
    </>
  );
}
