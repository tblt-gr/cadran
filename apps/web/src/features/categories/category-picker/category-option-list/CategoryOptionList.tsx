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
  return (
    <ul aria-label={label} className={styles.list} id={id} role="listbox">
      {choices.map((choice, index) => (
        <li
          aria-selected={choice.id === value}
          className={[
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
}
