import { FormField } from './FormField';

/** A decimal typed as text: it never goes through Number. */
export function DecimalInput({
  disabled,
  error,
  hint,
  label,
  onChange,
  value,
  wide,
}: {
  disabled?: boolean;
  error?: string;
  hint?: string;
  label: string;
  onChange: (value: string) => void;
  value: string;
  wide?: boolean;
}) {
  return (
    <FormField error={error} hint={hint} label={label} wide={wide}>
      <input
        autoComplete="off"
        disabled={disabled}
        inputMode="decimal"
        onChange={(event) => onChange(event.target.value)}
        value={value}
      />
    </FormField>
  );
}
