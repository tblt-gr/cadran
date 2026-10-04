import styles from './SegmentedControl.module.css';

interface SegmentedControlProps<T extends string> {
  /** Accessible name of the group, for instance "Période". */
  label: string;
  disabled?: boolean;
  onChange: (value: T) => void;
  options: readonly { value: T; label: string }[];
  value: T;
}

/** One-of-n toggle on a single track: a view switch, never a form submission. */
export function SegmentedControl<T extends string>({
  label,
  disabled = false,
  onChange,
  options,
  value,
}: SegmentedControlProps<T>) {
  return (
    <div aria-label={label} className={styles.track} role="group">
      {options.map((option) => (
        <button
          aria-pressed={value === option.value}
          className={styles.segment}
          disabled={disabled}
          key={option.value}
          onClick={() => onChange(option.value)}
          type="button"
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}
