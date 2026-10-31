import { useId, useRef, useState, type FocusEvent, type KeyboardEvent } from 'react';
import styles from './MultiSelect.module.css';

export function MultiSelect<T extends string>({
  options,
  value,
  onChange,
  label,
  emptyLabel = 'Tous les types',
}: {
  options: readonly { value: T; label: string }[];
  value: readonly T[];
  onChange: (value: T[]) => void;
  label: string;
  emptyLabel?: string;
}) {
  const labelId = useId();
  const summaryId = useId();
  const listId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const current =
    value.length === 0
      ? emptyLabel
      : value.length === 1
        ? (options.find((option) => option.value === value[0])?.label ?? value[0])
        : `${value.length} types`;

  function toggle(type: T) {
    onChange(value.includes(type) ? value.filter((item) => item !== type) : [...value, type]);
  }

  function onBlur(event: FocusEvent<HTMLDivElement>) {
    if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape' && open) {
      event.preventDefault();
      setOpen(false);
      trigger.current?.focus();
    }
  }

  return (
    <div className={styles.field} onBlur={onBlur} onKeyDown={onKeyDown}>
      <span id={labelId}>{label}</span>
      <button
        aria-controls={listId}
        aria-expanded={open}
        aria-haspopup="listbox"
        aria-labelledby={`${labelId} ${summaryId}`}
        className={styles.trigger}
        onClick={() => setOpen((currentOpen) => !currentOpen)}
        ref={trigger}
        type="button"
      >
        <span id={summaryId}>{current}</span>
      </button>
      {open ? (
        <ul
          aria-labelledby={labelId}
          aria-multiselectable="true"
          className={styles.list}
          id={listId}
          role="listbox"
        >
          {options.map(({ value: type, label: optionLabel }) => {
            const selected = value.includes(type);
            return (
              <li key={type} role="presentation">
                <button
                  aria-selected={selected}
                  onClick={() => toggle(type)}
                  role="option"
                  type="button"
                >
                  <input
                    aria-hidden="true"
                    checked={selected}
                    readOnly
                    tabIndex={-1}
                    type="checkbox"
                  />
                  <span>{optionLabel}</span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
