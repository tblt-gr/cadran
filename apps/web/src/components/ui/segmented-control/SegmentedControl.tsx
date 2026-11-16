import { Fragment, useId } from 'react';
import styles from './SegmentedControl.module.css';

interface SegmentedControlProps<T extends string> {
  /** Accessible name of the group, for instance "Période". */
  label: string;
  /** Shrinks the drawn segments on narrow screens; the touch target keeps its minimum. */
  compact?: boolean;
  disabled?: boolean;
  onChange: (value: T) => void;
  options: readonly { value: T; label: string; description?: string; disabled?: boolean }[];
  value: T;
}

/** One-of-n toggle on a single track: a view switch, never a form submission. */
export function SegmentedControl<T extends string>({
  label,
  compact = false,
  disabled = false,
  onChange,
  options,
  value,
}: SegmentedControlProps<T>) {
  const id = useId();
  return (
    <div
      aria-label={label}
      className={compact ? `${styles.track} ${styles.compact}` : styles.track}
      role="group"
    >
      {options.map((option, index) => {
        const descriptionId = `${id}-${index}`;
        return (
          <Fragment key={option.value}>
            <button
              aria-describedby={option.description ? descriptionId : undefined}
              aria-pressed={value === option.value}
              className={styles.segment}
              disabled={disabled || option.disabled}
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
