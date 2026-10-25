import { useTranslation } from 'react-i18next';
import styles from './DeleteChoice.module.css';

export type DeleteChoiceValue = 'keep' | 'transfer';

const CHOICES: DeleteChoiceValue[] = ['transfer', 'keep'];

interface DeleteChoiceProps {
  onChange: (choice: DeleteChoiceValue) => void;
  value: DeleteChoiceValue | null;
}

/** What happens to the transactions already classified under the deleted category. */
export function DeleteChoice({ onChange, value }: DeleteChoiceProps) {
  const { t } = useTranslation();

  return (
    <fieldset className={styles.choices}>
      <legend>{t('categories.lifecycle.DELETE.legend')}</legend>
      {CHOICES.map((choice) => (
        <label className={styles.choice} key={choice}>
          <input
            checked={value === choice}
            name="category-delete-choice"
            onChange={() => onChange(choice)}
            type="radio"
            value={choice}
          />
          <span>
            {t(`categories.lifecycle.DELETE.${choice}.label`)}
            <small>{t(`categories.lifecycle.DELETE.${choice}.hint`)}</small>
          </span>
        </label>
      ))}
    </fieldset>
  );
}
