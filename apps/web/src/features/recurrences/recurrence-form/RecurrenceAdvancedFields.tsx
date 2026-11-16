import { useTranslation } from 'react-i18next';
import type { FormValues } from './recurrenceFormValues';

interface RecurrenceAdvancedFieldsProps {
  amountTolerance: string;
  counterparty: string;
  toleranceInvalid: boolean;
  onChange: <K extends keyof FormValues>(field: K, value: FormValues[K]) => void;
}

/** The optional matching fields kept in the collapsed advanced section. */
export function RecurrenceAdvancedFields({
  amountTolerance,
  counterparty,
  toleranceInvalid,
  onChange,
}: RecurrenceAdvancedFieldsProps) {
  const { t } = useTranslation();

  return (
    <>
      <label>
        <span>{t('recurrences.fields.counterparty')}</span>
        <input
          onChange={(event) => onChange('counterparty', event.target.value)}
          value={counterparty}
        />
      </label>
      <label>
        <span>{t('recurrences.fields.tolerance')}</span>
        <input
          aria-invalid={toleranceInvalid ? true : undefined}
          autoComplete="off"
          inputMode="decimal"
          onChange={(event) => onChange('amountTolerance', event.target.value)}
          spellCheck={false}
          value={amountTolerance}
        />
        {toleranceInvalid ? <small>{t('recurrences.validation.tolerance')}</small> : null}
      </label>
    </>
  );
}
