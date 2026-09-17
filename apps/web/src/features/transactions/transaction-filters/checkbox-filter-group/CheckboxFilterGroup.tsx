import styles from './CheckboxFilterGroup.module.css';

interface CheckboxFilterGroupProps<T extends string> {
  labelFor: (value: T) => string;
  legend: string;
  onChange: (next: T[]) => void;
  options: readonly T[];
  value: readonly T[];
}

/**
 * One fieldset of independent checkboxes toggling entries of a multi-valued filter — state,
 * nature, analytic axis or source share this exact shape, so the filter bar renders each from
 * this single component instead of four near-identical fieldsets.
 */
export function CheckboxFilterGroup<T extends string>({
  labelFor,
  legend,
  onChange,
  options,
  value,
}: CheckboxFilterGroupProps<T>) {
  function toggle(option: T) {
    onChange(
      value.includes(option)
        ? value.filter((candidate) => candidate !== option)
        : [...value, option],
    );
  }

  return (
    <fieldset className={styles.field}>
      <legend>{legend}</legend>
      {options.map((option) => (
        <label className={styles.checkbox} key={option}>
          <input checked={value.includes(option)} onChange={() => toggle(option)} type="checkbox" />
          <span>{labelFor(option)}</span>
        </label>
      ))}
    </fieldset>
  );
}
