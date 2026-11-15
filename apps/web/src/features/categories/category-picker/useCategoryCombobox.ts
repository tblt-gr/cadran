import type { Category, CategoryType } from '@cadran/api-client';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type ChangeEvent,
  type KeyboardEvent,
  type MouseEvent,
} from 'react';
import { useTranslation } from 'react-i18next';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { optionId, type CategoryChoice } from './categoryChoice';
import { useCategoryCandidates } from './useCategoryCandidates';

/** The "no category" entry, which carries an empty identifier. */
const NONE = '';

const TYPE_ORDER: CategoryType[] = ['EXPENSE', 'INCOME'];

const CREATE_ID = 'create-category';

interface CategoryComboboxOptions {
  emptyOptionLabel?: string;
  excludeId?: string;
  isEligible?: (category: Category) => boolean;
  onChange: (categoryId: string, category: Category | null) => void;
  onCreateRequest?: (label: string) => void;
  parentEligible: boolean;
  preferredType?: CategoryType;
  selectedColor?: string | null;
  selectedIcon?: string | null;
  selectedLabel?: string | null;
  type?: CategoryType;
  value: string;
}

/**
 * State and keyboard behaviour of the category combobox: the typed query, the open list, the
 * active option, the bounded server search and the choices it yields.
 */
export function useCategoryCombobox({
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
}: CategoryComboboxOptions) {
  const { t } = useTranslation();
  const listId = useId();
  const blurTimeout = useRef<number | undefined>(undefined);
  useEffect(() => () => window.clearTimeout(blurTimeout.current), []);
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
  const [query, setQuery] = useState('');
  const searchStartedSelected = useRef(value !== NONE);
  const [expanded, setExpanded] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const [chosen, setChosen] = useState<CategoryChoice | null>(initialChoice);

  const debouncedSearch = useDebouncedValue(query.trim());
  const candidates = useCategoryCandidates(type, parentEligible, debouncedSearch);
  // The selection is known from one of three places, in order of freshness: the
  // current search result, the choice just made here, or what the host handed
  // over for a record it is editing.
  const listed = candidates.data?.items.find((category) => category.id === value);
  const identity =
    listed ??
    (chosen?.id === value ? chosen : undefined) ??
    (value === NONE
      ? undefined
      : { color: selectedColor, icon: selectedIcon, label: selectedLabel ?? '' });

  const hasSelection = !expanded && value !== NONE;
  const selectedText = expanded
    ? query
    : value !== NONE
      ? (identity?.label ?? selectedLabel ?? '')
      : query;
  const selectedFill =
    value === NONE
      ? null
      : canonicalCategoryColor(listed ? listed.effectiveColor : identity?.color);
  const selectedIconKey = listed ? listed.effectiveIcon : identity?.icon;

  const typeRank = (candidate: Category): number =>
    candidate.type === preferredType ? -1 : TYPE_ORDER.indexOf(candidate.type);
  const matches: CategoryChoice[] = (candidates.data?.items ?? [])
    .filter(
      (candidate: Category) =>
        candidate.id !== excludeId &&
        candidate.archivedAt === null &&
        (isEligible?.(candidate) ?? true),
    )
    // Stable, so the server order is kept inside each type.
    .toSorted((first, second) => (type ? 0 : typeRank(first) - typeRank(second)))
    .map((candidate: Category) => ({
      id: candidate.id,
      kind: 'category' as const,
      label: candidate.label,
      color: candidate.effectiveColor,
      icon: candidate.effectiveIcon,
      type: type ? undefined : candidate.type,
    }));
  const categoryChoices: CategoryChoice[] =
    undefined === emptyOptionLabel
      ? matches
      : [{ id: NONE, kind: 'category', label: emptyOptionLabel }, ...matches];
  const createLabel = expanded ? query.trim() : '';
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

  function openSearch() {
    if (!expanded) {
      searchStartedSelected.current = value !== NONE;
      if (value !== NONE) setQuery('');
      setActiveIndex(0);
      setExpanded(true);
    }
  }

  function dismissSearch() {
    setExpanded(false);
    if (searchStartedSelected.current) setQuery('');
  }

  function choose(choice: CategoryChoice) {
    if (choice.kind === 'create') {
      dismissSearch();
      setActiveIndex(0);
      skipFocusOpen.current = true;
      onCreateRequest?.(createLabel);
      return;
    }

    setChosen(choice.id === NONE ? null : choice);
    setQuery('');
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
      dismissSearch();
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!expanded) {
        openSearch();
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

  const status = !expanded
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

  /** Props the input takes as they are; the host adds its id, label wiring and placeholder. */
  const inputProps = {
    'aria-activedescendant': expanded && active ? optionId(listId, active) : undefined,
    'aria-autocomplete': 'list' as const,
    'aria-controls': listId,
    'aria-expanded': expanded,
    autoComplete: 'off',
    maxLength: 80,
    onBlur: () => {
      // A pointer selection lands after the blur, so the list stays open long
      // enough for the click on it to register.
      blurTimeout.current = window.setTimeout(dismissSearch, 120);
    },
    onChange: (event: ChangeEvent<HTMLInputElement>) => {
      if (!expanded) searchStartedSelected.current = value !== NONE;
      setQuery(event.target.value);
      setExpanded(true);
      setActiveIndex(0);
    },
    onFocus: () => {
      window.clearTimeout(blurTimeout.current);
      if (skipFocusOpen.current) {
        skipFocusOpen.current = false;
        return;
      }
      openSearch();
    },
    onKeyDown,
    onMouseDown: (event: MouseEvent<HTMLInputElement>) => {
      // A pointer on the field asks for the list, even when it already has focus.
      skipFocusOpen.current = false;
      if (document.activeElement === event.currentTarget) openSearch();
    },
    role: 'combobox' as const,
    style: hasSelection ? { width: `${selectedText.length + 1}ch` } : undefined,
    type: 'text' as const,
    value: selectedText,
  };

  return {
    candidatesFailed: candidates.isError,
    choices,
    expanded,
    hasSelection,
    inputProps,
    listProps: {
      activeIndex,
      choices,
      id: listId,
      onActivate: setActiveIndex,
      onChoose: choose,
      value,
    },
    selectedFill,
    selectedIconKey,
    status,
  };
}
