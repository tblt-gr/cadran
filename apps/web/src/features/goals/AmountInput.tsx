interface Props {
  invalid: boolean;
  onChange: (value: string) => void;
  value: string;
}
/** Exact amount entry: its value stays a canonical decimal string until the API request. */
export function AmountInput({ invalid, onChange, value }: Props) {
  return (
    <input
      aria-invalid={invalid ? true : undefined}
      inputMode="decimal"
      onChange={(event) => onChange(event.target.value)}
      value={value}
    />
  );
}
