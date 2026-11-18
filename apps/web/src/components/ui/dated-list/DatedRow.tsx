import type { MouseEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import styles from './DatedList.module.css';

/** A control inside a row keeps its own action: a click on one never opens the detail. */
const OWN_CONTROLS = 'button, a, input, label, select, [data-actions]';

interface DatedRowState {
  /**
   * `muted` greys a planned, withdrawn or voided row, `pending` a row not booked yet. Both work
   * by text colour, never by opacity, so contrast stays AA.
   */
  tone: 'muted' | 'pending';
  /** Text cue shown after the title (an icon with an accessible name, a badge): colour alone never says it. */
  cue: ReactNode;
}

export interface DatedRowProps {
  /** Selection checkbox or category mark, before the title. */
  leading?: ReactNode;
  /** First line, usually the label button that opens the detail. */
  title: ReactNode;
  state?: DatedRowState;
  /** Small secondary lines under the title (icons, badges, notes), shown on every width. */
  meta?: ReactNode;
  /** `DatedCell` elements: the second line on a tablet, extra columns on a computer. Hidden on a phone. */
  cells?: ReactNode;
  /**
   * Main figure on the right, display only. `null` means it cannot be computed: the shared empty
   * value shows, never zero. While loading, pass a skeleton block.
   */
  value: ReactNode;
  /** One extra figure beside the value, shown from 768 px. */
  aside?: ReactNode;
  /** Three-dot menu after the value. Hidden on a phone, where the detail dialog carries its actions. */
  menu?: ReactNode;
  /** Opens the caller's detail dialog from anywhere on the row except its own controls. */
  onOpen?: () => void;
  className?: string;
  /** `data-*` attributes forwarded to the row, for the caller's selectors. */
  dataAttributes?: Record<`data-${string}`, string | boolean | undefined>;
}

/** One row of a dated list: label and meta on the left, value, aside and menu on the right. */
export function DatedRow({
  aside,
  cells,
  className,
  dataAttributes,
  leading,
  menu,
  meta,
  onOpen,
  state,
  title,
  value,
}: DatedRowProps) {
  const { t } = useTranslation();
  const open = (event: MouseEvent<HTMLLIElement>) => {
    if (!onOpen) return;
    if ((event.target as HTMLElement).closest(OWN_CONTROLS)) return;
    onOpen();
  };

  return (
    <li
      {...dataAttributes}
      className={[styles.row, state ? styles[state.tone] : '', className].filter(Boolean).join(' ')}
      data-aside={aside === undefined ? undefined : true}
      data-clickable={onOpen ? true : undefined}
      data-leading={leading === undefined ? undefined : true}
      onClick={open}
    >
      {leading === undefined ? null : <div className={styles.leading}>{leading}</div>}
      <div className={styles.primary}>
        <div className={styles.titleLine}>
          {title}
          {state?.cue}
        </div>
        {meta}
      </div>
      <span className={styles.value}>
        {value === null ? <EmptyValue label={t('states.notCalculable.label')} /> : value}
      </span>
      {aside === undefined ? null : <span className={styles.aside}>{aside}</span>}
      {cells === undefined ? null : <div className={styles.cells}>{cells}</div>}
      {menu === undefined ? null : <div className={styles.actions}>{menu}</div>}
    </li>
  );
}
