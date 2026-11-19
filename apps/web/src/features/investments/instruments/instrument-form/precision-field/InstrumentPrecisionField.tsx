import { useTranslation } from 'react-i18next';
import { validPrecision } from '@/features/investments/instruments/instrument-form/instrumentOptions';

export function InstrumentPrecisionField({
  label,
  locked,
  onChange,
  showErrors,
  value,
}: {
  label: string;
  locked: boolean;
  onChange: (value: string) => void;
  showErrors: boolean;
  value: string;
}) {
  const { t } = useTranslation();
  return (
    <label>
      <span>{label}</span>
      <input
        disabled={locked}
        inputMode="numeric"
        onChange={(e) => onChange(e.target.value)}
        value={value}
      />
      {showErrors && !validPrecision(value) ? (
        <small>{t('investments.instruments.precisionInvalid')}</small>
      ) : null}
      {locked ? <small>{t('investments.instruments.locked')}</small> : null}
    </label>
  );
}
