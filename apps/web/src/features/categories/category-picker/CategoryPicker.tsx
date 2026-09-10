import { listCategories, type Category, type CategoryType } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useId, useRef, useState, type KeyboardEvent, type Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { CategoryOptionList } from './category-option-list/CategoryOptionList';
import { optionId, type CategoryChoice } from './categoryChoice';
import styles from './CategoryPicker.module.css';

/** The "no category" entry, which carries an empty identifier. */
const NONE = '';

interface CategoryPickerProps {
  /** Label of the entry that selects no category. Omitting it makes the choice mandatory. */
  emptyOptionLabel?: string;
  /** Kept out of the choices, typically the category the operation acts on. */
  excludeId?: string;
  label: string;
  onChange: (categoryId: string) => void;
  /** Restrict the choices to categories that can still take a child. */
  parentEligible?: boolean;
  /**
   * Adds a last "create" entry handing the typed label to the host, which owns the
   * creation flow and selects the result through `value` and `selectedLabel`.
   */
  onCreateRequest?: (label: string) => void;
  ref?: Ref<HTMLInputElement>;
  /** Visible label of a value selected from outside: an edited record or a category just created. */
  selectedLabel?: string | null;
  type: CategoryType;
  value: string;
}

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
  excludeId,
  label,
  onChange,
  onCreateRequest,
  parentEligible = false,
  ref,
  selectedLabel,
  type,
  value,
}: CategoryPickerProps) {
  const { t } = useTranslation();
  const inputId = useId();
  const listId = useId();
  const statusId = useId();
  const blurTimeout = useRef<number | undefined>(undefined);
  // Set when the host takes over for a creation: focus coming back from its dialog
  // must not reopen the list, where one Enter would clear the fresh selection.
  const skipFocusOpen = useRef(false);

  const initialChoice =
    value !== NONE && selectedLabel
      ? { id: value, kind: 'category' as const, label: selectedLabel }
      : null;
  const [query, setQuery] = useState(initialChoice?.label ?? '');
  const [expanded, setExpanded] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const [chosen, setChosen] = useState<CategoryChoice | null>(initialChoice);

  const debouncedSearch = useDebouncedValue(query.trim());
  const candidates = useQuery({
    queryKey: ['category-candidates', type, parentEligible, debouncedSearch],
    queryFn: async ({ signal }) => {
      const result = await listCategories({
        ...authApiOptions(),
        query: {
          type,
          search: debouncedSearch || undefined,
          parentEligible,
          page: 1,
          perPage: 50,
        },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load category candidates.');
      }
      return result.data;
    },
    placeholderData: (previous) => previous,
    retry: false,
  });

  const matches: CategoryChoice[] = (candidates.data?.items ?? [])
    .filter((candidate: Category) => candidate.id !== excludeId && candidate.archivedAt === null)
    .map((candidate: Category) => ({
      id: candidate.id,
      kind: 'category' as const,
      label: candidate.label,
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
    onChange(choice.id);
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

  return (
    <div className={styles.picker}>
      <label htmlFor={inputId}>{label}</label>
      <input
        aria-activedescendant={expanded && active ? optionId(listId, active) : undefined}
        aria-autocomplete="list"
        aria-controls={listId}
        aria-describedby={status === null ? undefined : statusId}
        aria-expanded={expanded}
        autoComplete="off"
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
            onChange(NONE);
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
        placeholder={t('categories.picker.placeholder')}
        ref={ref}
        role="combobox"
        type="text"
        value={value !== NONE && selectedLabel ? selectedLabel : query}
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
