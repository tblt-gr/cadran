import type { Category, CategoryType } from '@cadran/api-client';
import { useId, useRef, useState, type KeyboardEvent, type Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { CategorySwatch } from '@/features/categories/category-swatch/CategorySwatch';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { CategoryOptionList } from './category-option-list/CategoryOptionList';
import { optionId, type CategoryChoice } from './categoryChoice';
import { useCategoryCandidates } from './useCategoryCandidates';
import styles from './CategoryPicker.module.css';

/** The "no category" entry, which carries an empty identifier. */
const NONE = '';

interface CategoryPickerProps {
  /** Label of the entry that selects no category. Omitting it makes the choice mandatory. */
  emptyOptionLabel?: string;
  /** Validation message of the host, announced with the field. */
  error?: string | null;
  /** Kept out of the choices, typically the category the operation acts on. */
  excludeId?: string;
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
   * with `preferredType` first — the host then validates the pairing itself.
   */
  type?: CategoryType;
  preferredType?: CategoryType;
  value: string;
}

const TYPE_ORDER: CategoryType[] = ['EXPENSE', 'INCOME'];

const CREATE_ID = 'create-category';

/**
 * Picks one active category through a bounded server search.
 *
 * A single combobox rather than a search box wired to a separate select: the
 * workspace can hold more categories than any list should render at once, so
 * the choices are a search result — and a control where you type in one place
 * and choose in another does not read as one decision.
 */
export function CategoryPicker({
  emptyOptionLabel,
  error,
  excludeId,
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
  const listId = useId();
  const statusId = useId();
  const errorId = useId();
  const blurTimeout = useRef<number | undefined>(undefined);
  // Set when the host takes over for a creation: focus coming back from its dialog
  // must not reopen the list, where one Enter would clear the fresh selection.
  const skipFocusOpen = useRef(false);

  const initialChoice =
    value !== NONE && selectedLabel
      ? {
          color: selectedColor,
          icon: selectedIcon,
          id: value,
          kind: 'category' as const,
          label: selectedLabel,
        }
      : null;
  const [query, setQuery] = useState(initialChoice?.label ?? '');
  const [expanded, setExpanded] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const [chosen, setChosen] = useState<CategoryChoice | null>(initialChoice);

  const debouncedSearch = useDebouncedValue(query.trim());
  const candidates = useCategoryCandidates(type, parentEligible, debouncedSearch);
  // The selection is known from one of three places, in order of freshness: the
  // current search result, the choice just made here, or what the host handed
  // over for a record it is editing.
  const identity =
    candidates.data?.items.find((category) => category.id === value) ??
    (chosen?.id === value ? chosen : undefined) ??
    (value === NONE
      ? undefined
      : { color: selectedColor, icon: selectedIcon, label: selectedLabel ?? '' });

  const typeRank = (candidate: Category): number =>
    candidate.type === preferredType ? -1 : TYPE_ORDER.indexOf(candidate.type);
  const matches: CategoryChoice[] = (candidates.data?.items ?? [])
    .filter((candidate: Category) => candidate.id !== excludeId && candidate.archivedAt === null)
    // Stable, so the server order is kept inside each type.
    .toSorted((first, second) => (type ? 0 : typeRank(first) - typeRank(second)))
    .map((candidate: Category) => ({
      id: candidate.id,
      kind: 'category' as const,
      label: candidate.label,
      color: candidate.color,
      icon: candidate.icon,
      type: type ? undefined : candidate.type,
    }));
  const categoryChoices: CategoryChoice[] =
    undefined === emptyOptionLabel
      ? matches
      : [{ id: NONE, kind: 'category', label: emptyOptionLabel }, ...matches];
  const hasSelection = chosen !== null || value !== NONE;
  // Once a category is selected the field shows its label, not a search: offering to
  // create it again would only meet the sibling-uniqueness refusal.
  const createLabel = hasSelection ? '' : query.trim();
  const createChoice: CategoryChoice = {
    id: CREATE_ID,
    kind: 'create',
    label: t(createLabel ? 'categories.picker.createNamed' : 'categories.picker.create', {
      label: createLabel,
    }),
  };
  const choices = onCreateRequest ? [...categoryChoices, createChoice] : categoryChoices;
  // A new search key restarts the query; keeping the previous page visible avoids the
  // list flickering empty on every keystroke.
  const loading = undefined === candidates.data && !candidates.isError;
  const active = choices[Math.min(activeIndex, Math.max(choices.length - 1, 0))];

  function choose(choice: CategoryChoice) {
    if (choice.kind === 'create') {
      setExpanded(false);
      setActiveIndex(0);
      skipFocusOpen.current = true;
      onCreateRequest?.(createLabel);
      return;
    }

    setChosen(choice.id === NONE ? null : choice);
    setQuery(choice.id === NONE ? '' : choice.label);
    setExpanded(false);
    setActiveIndex(0);
    onChange(
      choice.id,
      candidates.data?.items.find((category) => category.id === choice.id) ?? null,
    );
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape' && expanded) {
      event.preventDefault();
      event.stopPropagation();
      setExpanded(false);
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!expanded) {
        setExpanded(true);
        return;
      }

      const step = event.key === 'ArrowDown' ? 1 : -1;
      setActiveIndex((current) => {
        const next = current + step;
        return next < 0 ? choices.length - 1 : next >= choices.length ? 0 : next;
      });
      return;
    }

    if (event.key === 'Enter' && expanded && active) {
      event.preventDefault();
      choose(active);
    }
  }

  const searching = expanded && !hasSelection;
  const status = !searching
    ? null
    : loading
      ? t('categories.picker.loading')
      : candidates.isError
        ? t('categories.picker.error')
        : matches.length === 0
          ? t(debouncedSearch ? 'categories.picker.empty' : 'categories.picker.noneAvailable')
          : debouncedSearch
            ? t('categories.picker.matches', { count: matches.length })
            : null;

  const describedBy = [error ? errorId : null, status === null ? null : statusId]
    .filter(Boolean)
    .join(' ');

  return (
    <div className={styles.picker}>
      <label className={labelHidden ? 'sr-only' : undefined} htmlFor={inputId}>
        {label}
      </label>
      {/* The selection shows as the field text, led by its colour and glyph inside
          the control: a pill beside the field would change its height and push
          every neighbouring field out of line. */}
      <div className={styles.control}>
        {value !== NONE ? (
          <span className={styles.swatch}>
            <CategorySwatch color={identity?.color} icon={identity?.icon} />
          </span>
        ) : null}
        <input
          aria-activedescendant={expanded && active ? optionId(listId, active) : undefined}
          aria-autocomplete="list"
          aria-controls={listId}
          aria-describedby={describedBy || undefined}
          aria-expanded={expanded}
          aria-invalid={error ? true : undefined}
          autoComplete="off"
          className={value !== NONE ? styles.withSwatch : undefined}
          id={inputId}
          maxLength={80}
          onBlur={() => {
            // A pointer selection lands after the blur, so the list stays open long
            // enough for the click on it to register.
            blurTimeout.current = window.setTimeout(() => setExpanded(false), 120);
          }}
          onChange={(event) => {
            setQuery(event.target.value);
            setExpanded(true);
            setActiveIndex(0);
            if (chosen !== null || value !== NONE) {
              setChosen(null);
              onChange(NONE, null);
            }
          }}
          onFocus={() => {
            window.clearTimeout(blurTimeout.current);
            if (skipFocusOpen.current) {
              skipFocusOpen.current = false;
              return;
            }
            setExpanded(true);
          }}
          onKeyDown={onKeyDown}
          onMouseDown={(event) => {
            // A pointer on the field asks for the list, even when it already has focus.
            skipFocusOpen.current = false;
            if (document.activeElement === event.currentTarget) setExpanded(true);
          }}
          placeholder={placeholder ?? t('categories.picker.placeholder')}
          ref={ref}
          role="combobox"
          type="text"
          value={value !== NONE ? (identity?.label ?? selectedLabel ?? query) : query}
        />

        {expanded && choices.length > 0 ? (
          <CategoryOptionList
            activeIndex={activeIndex}
            choices={choices}
            id={listId}
            label={label}
            onActivate={setActiveIndex}
            onChoose={choose}
            value={value}
          />
        ) : null}
      </div>

      {error ? (
        <small className={styles.error} id={errorId}>
          {error}
        </small>
      ) : null}

      {status === null ? null : (
        <small
          className={candidates.isError ? undefined : styles.hint}
          id={statusId}
          role={candidates.isError ? 'alert' : 'status'}
        >
          {status}
        </small>
      )}
    </div>
  );
}
