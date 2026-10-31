import { GhostSelect } from '@/components/ui/ghost-select/GhostSelect';

/** The same quiet year selector as the budget toolbar. */
export function YearNavigator({
  year,
  bounds,
  onChange,
}: {
  year: number;
  bounds: { from: number; to: number; years: number[] } | null;
  onChange: (year: number) => void;
}) {
  const years = bounds ? [...bounds.years].sort((left, right) => right - left) : [year];
  return (
    <GhostSelect
      aria-label="Année"
      disabled={!bounds}
      value={year}
      onChange={(event) => onChange(Number(event.target.value))}
    >
      {years.map((value) => (
        <option key={value} value={value}>
          {value}
        </option>
      ))}
    </GhostSelect>
  );
}
