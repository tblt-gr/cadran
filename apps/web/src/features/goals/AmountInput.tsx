interface Props {
  'aria-label'?: string;
  disabled?: boolean;
  invalid: boolean;
  onChange: (value: string) => void;
  value: string;
}
/** Exact amount entry: its value stays a canonical decimal string until the API request. */
export function AmountInput({
  'aria-label': ariaLabel,
  disabled,
  invalid,
  onChange,
  value,
}: Props) {
  return (
    <input
      aria-invalid={invalid ? true : undefined}
      aria-label={ariaLabel}
      disabled={disabled}
      inputMode="decimal"
      onChange={(event) => onChange(event.target.value)}
      value={value}
    />
  );
}
