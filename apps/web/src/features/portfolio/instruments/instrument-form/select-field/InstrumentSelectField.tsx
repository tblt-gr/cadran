export function InstrumentSelectField({
  label,
  options,
  onChange,
  value,
  nullable = false,
}: {
  label: string;
  options: readonly (readonly [string, string])[];
  onChange: (value: string) => void;
  value: string;
  nullable?: boolean;
}) {
  return (
    <label>
      <span>{label}</span>
      <select onChange={(e) => onChange(e.target.value)} value={value}>
        {nullable ? <option value="">Non renseignée</option> : null}
        {options.map(([id, text]) => (
          <option key={id} value={id}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
}
