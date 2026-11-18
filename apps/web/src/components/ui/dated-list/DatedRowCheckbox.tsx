interface DatedRowCheckboxProps {
  /** Accessible name, for instance "Select <label>". */
  label: string;
  checked: boolean;
  disabled?: boolean;
  onToggle: () => void;
}

/** The selection checkbox of a row, meant for the `leading` slot of `DatedRow`. */
export function DatedRowCheckbox({ checked, disabled, label, onToggle }: DatedRowCheckboxProps) {
  return (
    <label>
      <input
        aria-label={label}
        checked={checked}
        disabled={disabled}
        onChange={onToggle}
        type="checkbox"
      />
    </label>
  );
}
