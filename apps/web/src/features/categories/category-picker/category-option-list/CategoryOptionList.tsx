import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import {
  optionId,
  type CategoryChoice,
} from '@/features/categories/category-picker/categoryChoice';
import styles from './CategoryOptionList.module.css';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';

interface CategoryOptionListProps {
  activeIndex: number;
  choices: CategoryChoice[];
  id: string;
  label: string;
  onActivate: (index: number) => void;
  onChoose: (choice: CategoryChoice) => void;
  value: string;
}

interface Section {
  choices: Array<{ choice: CategoryChoice; index: number }>;
  type?: CategoryType;
}

/** Consecutive choices of one type form a labelled group; untyped entries stay ungrouped. */
function sections(choices: CategoryChoice[]): Section[] {
  const result: Section[] = [];
  choices.forEach((choice, index) => {
    const last = result.at(-1);
    if (last !== undefined && last.type === choice.type) {
      last.choices.push({ choice, index });
    } else {
      result.push({ choices: [{ choice, index }], type: choice.type });
    }
  });

  return result;
}

/** The listbox of the category combobox; focus stays on the combobox input. */
export function CategoryOptionList({
  activeIndex,
  choices,
  id,
  label,
  onActivate,
  onChoose,
  value,
}: CategoryOptionListProps) {
  const { t } = useTranslation();

  return (
    <div aria-label={label} className={styles.list} id={id} role="listbox">
      {sections(choices).map((section, position) => {
        const headingId = `${id}-group-${position}`;

        return (
          <ul
            aria-labelledby={section.type ? headingId : undefined}
            key={section.type ?? `plain-${position}`}
            role={section.type ? 'group' : 'presentation'}
          >
            {section.type ? (
              <li className={styles.group} id={headingId} role="presentation">
                {t(`categories.picker.groups.${section.type}`)}
              </li>
            ) : null}
            {section.choices.map(({ choice, index }) => (
              <li
                aria-selected={choice.id === value}
                className={[
                  styles.option,
                  choice.kind === 'create' ? styles.create : null,
                  index === activeIndex ? styles.active : null,
                ]
                  .filter(Boolean)
                  .join(' ')}
                id={optionId(id, choice)}
                key={choice.id || 'none'}
                onMouseDown={(event) => {
                  // Keeps focus on the combobox so the blur timer never races the choice.
                  event.preventDefault();
                  onChoose(choice);
                }}
                onMouseEnter={() => onActivate(index)}
                role="option"
              >
                {choice.kind === 'category' ? (
                  <CategoryIdentity color={choice.color} icon={choice.icon} label={choice.label} />
                ) : (
                  choice.label
                )}
              </li>
            ))}
          </ul>
        );
      })}
    </div>
  );
}
