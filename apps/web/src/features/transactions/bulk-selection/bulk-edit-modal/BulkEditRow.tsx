import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { BulkEditField } from './bulkEditChanges';
import styles from './BulkEditModal.module.css';

interface BulkEditRowProps {
  checked: boolean;
  children: (disabled: boolean) => ReactNode;
  field: BulkEditField;
  full?: boolean;
  /** Why the row cannot be ticked; the control is replaced by this text. */
  lockedHint?: string | null;
  onTick: (on: boolean) => void;
}

/** A ticked row enables its control; an unticked one is shown but inert and never sent. */
export function BulkEditRow({
  checked,
  children,
  field,
  full = false,
  lockedHint = null,
  onTick,
}: BulkEditRowProps) {
  const { t } = useTranslation();
  return (
    <div className={styles.row} data-span={full || field === 'label' ? 'full' : undefined}>
      <label className={styles.tick}>
        <input
          checked={checked}
          disabled={lockedHint !== null}
          onChange={(event) => onTick(event.target.checked)}
          type="checkbox"
        />
        {t(`transactions.bulk.edit.${field}`)}
      </label>
      {lockedHint === null ? children(!checked) : <p className={styles.note}>{lockedHint}</p>}
    </div>
  );
}
