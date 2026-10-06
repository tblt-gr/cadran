import type { PortfolioOperationType } from '@cadran/api-client';
import { useId, useRef, useState, type FocusEvent, type KeyboardEvent } from 'react';
import {
  operationTypeLabel,
  operationTypes,
  allowedOperationTypes,
} from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import styles from './OperationTypeSelect.module.css';

function summary(types: readonly PortfolioOperationType[]): string {
  if (types.length === 0) return 'Tous les types';
  if (types.length === 1) return operationTypeLabel(types[0]!);
  return `${types.length} types`;
}

export function OperationTypeSelect({
  onChange,
  accountKind,
  value,
}: {
  accountKind?: string;
  onChange: (types: PortfolioOperationType[]) => void;
  value: readonly PortfolioOperationType[];
}) {
  const labelId = useId();
  const summaryId = useId();
  const listId = useId();
  const trigger = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const current = summary(value);

  function toggle(type: PortfolioOperationType) {
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
      <span id={labelId}>Types d’opération</span>
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
          {(accountKind ? allowedOperationTypes(accountKind) : operationTypes).map((type) => {
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
                  <span>{operationTypeLabel(type)}</span>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
