export function InstrumentSelectField({
  disabled,
  disabledOptions,
  label,
  options,
  onChange,
  value,
  nullable = false,
}: {
  disabled?: boolean;
  disabledOptions?: readonly string[];
  label: string;
  options: readonly (readonly [string, string])[];
  onChange: (value: string) => void;
  value: string;
  nullable?: boolean;
}) {
  return (
    <label>
      <span>{label}</span>
      <select disabled={disabled} onChange={(e) => onChange(e.target.value)} value={value}>
        {nullable ? <option value="">Non renseignée</option> : null}
        {options.map(([id, text]) => (
          <option disabled={disabledOptions?.includes(id)} key={id} value={id}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
}
