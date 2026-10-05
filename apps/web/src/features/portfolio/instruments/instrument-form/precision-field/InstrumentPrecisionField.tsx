import {
  PRECISION_MESSAGE,
  validPrecision,
} from '@/features/portfolio/instruments/instrument-form/instrumentOptions';

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
  return (
    <label>
      <span>{label}</span>
      <input
        disabled={locked}
        inputMode="numeric"
        onChange={(e) => onChange(e.target.value)}
        value={value}
      />
      {showErrors && !validPrecision(value) ? <small>{PRECISION_MESSAGE}</small> : null}
      {locked ? <small>Figé après la première utilisation</small> : null}
    </label>
  );
}
