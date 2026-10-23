import { Fragment, useId } from 'react';
import styles from './SegmentedControl.module.css';

interface SegmentedControlProps<T extends string> {
  /** Accessible name of the group, for instance "Période". */
  label: string;
  disabled?: boolean;
  onChange: (value: T) => void;
  options: readonly { value: T; label: string; description?: string }[];
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
  const id = useId();
  return (
    <div aria-label={label} className={styles.track} role="group">
      {options.map((option, index) => {
        const descriptionId = `${id}-${index}`;
        return (
          <Fragment key={option.value}>
            <button
              aria-describedby={option.description ? descriptionId : undefined}
              aria-pressed={value === option.value}
              className={styles.segment}
              disabled={disabled}
              onClick={() => onChange(option.value)}
              title={option.description}
              type="button"
            >
              {option.label}
            </button>
            {option.description ? (
              <span className="sr-only" id={descriptionId}>
                {option.description}
              </span>
            ) : null}
          </Fragment>
        );
      })}
    </div>
  );
}
