import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import styles from './PerformanceColumns.module.css';
import { useState } from 'react';
import { lineColumns } from '@/features/portfolio/performance/performance-tab/lineColumns';

export function usePerformanceColumns(accountId: string) {
  const key = `cadran:performance-columns:v1:${accountId}`;
  const [selected, setSelected] = useState<string[]>(() => {
    try {
      const stored: unknown = JSON.parse(localStorage.getItem(key) ?? 'null');
      if (Array.isArray(stored)) {
        const valid = lineColumns
          .filter((column) => stored.includes(column.id))
          .map((column) => column.id);
        if (valid.length) return valid;
      }
    } catch {
      /* Browser storage can be unavailable. */
    }
    return lineColumns.map((column) => column.id);
  });
  return {
    selected,
    change: (next: string[]) => {
      setSelected(next);
      try {
        localStorage.setItem(key, JSON.stringify(next));
      } catch {
        /* Keep the selection for this session. */
      }
    },
  };
}

export function PerformanceColumns({
  selected,
  onChange,
  disabled = false,
}: {
  selected: string[];
  onChange: (next: string[]) => void;
  disabled?: boolean;
}) {
  return (
    <div className={styles.picker}>
      <ActionMenu
        label="Colonnes affichées"
        triggerIcon="columns"
        triggerVariant="framed"
        disabled={disabled}
        items={lineColumns.map((column) => ({
          id: column.id,
          label: column.label,
          text: column.label,
          icon: 'columns',
          checked: selected.includes(column.id),
          disabled: selected.length === 1 && selected.includes(column.id),
          onSelect: () =>
            onChange(
              selected.includes(column.id)
                ? selected.filter((id) => id !== column.id)
                : [...selected, column.id],
            ),
        }))}
      />
    </div>
  );
}
