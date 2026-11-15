import type { Category, CategoryType } from '@cadran/api-client';
import { useId, type Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { CategorySwatch } from '@/features/categories/category-swatch/CategorySwatch';
import { CategoryOptionList } from './category-option-list/CategoryOptionList';
import { useCategoryCombobox } from './useCategoryCombobox';
import styles from './CategoryPicker.module.css';

interface CategoryPickerProps {
  /** Label of the entry that selects no category. Omitting it makes the choice mandatory. */
  emptyOptionLabel?: string;
  /** Validation message of the host, announced with the field. */
  error?: string | null;
  /** Kept out of the choices, typically the category the operation acts on. */
  excludeId?: string;
  /** Narrows the offered choices beyond type and archival, for a host with its own eligibility rule. */
  isEligible?: (category: Category) => boolean;
  label: string;
  /** Keeps the label for assistive technologies only, in a row whose columns speak for it. */
  labelHidden?: boolean;
  /**
   * Receives the chosen category as the search returned it, so the host can read its
   * type or default axes; `null` for "no category" and for a category known only by id.
   */
  onChange: (categoryId: string, category: Category | null) => void;
  /** Restrict the choices to categories that can still take a child. */
  parentEligible?: boolean;
  placeholder?: string;
  /**
   * Adds a last "create" entry handing the typed label to the host, which owns the
   * creation flow and selects the result through `value` and `selectedLabel`.
   */
  onCreateRequest?: (label: string) => void;
  ref?: Ref<HTMLInputElement>;
  /** Stored colour of a value selected from outside, so its pill matches the options. */
  selectedColor?: string | null;
  /** Stored icon key of a value selected from outside, so its pill matches the options. */
  selectedIcon?: string | null;
  /** Visible label of a value selected from outside: an edited record or a category just created. */
  selectedLabel?: string | null;
  /**
   * Restricts the choices to one type. Without it both types are offered, grouped,
   * with `preferredType` first - the host then validates the pairing itself.
   */
  type?: CategoryType;
  preferredType?: CategoryType;
  value: string;
}

/**
 * Picks one active category through a bounded server search.
 *
 * A single combobox rather than a search box wired to a separate select: the
 * workspace can hold more categories than any list should render at once, so
 * the choices are a search result - and a control where you type in one place
 * and choose in another does not read as one decision.
 */
export function CategoryPicker({
  emptyOptionLabel,
  error,
  excludeId,
  isEligible,
  label,
  labelHidden = false,
  onChange,
  onCreateRequest,
  parentEligible = false,
  placeholder,
  preferredType,
  ref,
  selectedColor,
  selectedIcon,
  selectedLabel,
  type,
  value,
}: CategoryPickerProps) {
  const { t } = useTranslation();
  const inputId = useId();
  const statusId = useId();
  const errorId = useId();
  const combobox = useCategoryCombobox({
    emptyOptionLabel,
    excludeId,
    isEligible,
    onChange,
    onCreateRequest,
    parentEligible,
    preferredType,
    selectedColor,
    selectedIcon,
    selectedLabel,
    type,
    value,
  });
  const { candidatesFailed, choices, expanded, hasSelection, status } = combobox;

  const describedBy = [error ? errorId : null, status === null ? null : statusId]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={styles.picker}>
      <label className={labelHidden ? 'sr-only' : undefined} htmlFor={inputId}>
        {label}
      </label>
      {/* The field stays a neutral input. A selection puts the category's tag inside
          it (coloured mark and the editable label in one flex row), so the text
          can never run under the glyph. The wrapper is always rendered: swapping it
          for another element on selection would remount the input and drop focus. */}
      <div className={styles.control} data-invalid={error ? 'true' : undefined}>
        <span className={hasSelection ? `${styles.chip} ${styles.selected}` : styles.chip}>
          {hasSelection ? (
            <CategorySwatch color={combobox.selectedFill} icon={combobox.selectedIconKey} />
          ) : null}
          <input
            {...combobox.inputProps}
            aria-describedby={describedBy || undefined}
            aria-invalid={error ? true : undefined}
            id={inputId}
            placeholder={placeholder ?? t('categories.picker.placeholder')}
            ref={ref}
          />
        </span>

        {expanded && choices.length > 0 ? (
          <CategoryOptionList {...combobox.listProps} label={label} />
        ) : null}
      </div>

      {error ? (
        <small className={styles.error} id={errorId}>
          {error}
        </small>
      ) : null}

      {status === null ? null : (
        <small
          className={candidatesFailed ? undefined : styles.hint}
          id={statusId}
          role={candidatesFailed ? 'alert' : 'status'}
        >
          {status}
        </small>
      )}
    </div>
  );
}
