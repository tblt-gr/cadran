import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import styles from './CategoryColorPicker.module.css';

/**
 * Where the native control starts when a category goes from colourless to
 * coloured. A plain neutral grey, not a suggestion: the product has no opinion
 * on which colour belongs to which category, so no palette is offered anywhere.
 */
const NEUTRAL_START = '#808080';

interface CategoryColorPickerProps {
  onChange: (value: string) => void;
  value: string;
}

export function CategoryColorPicker({ onChange, value }: CategoryColorPickerProps) {
  const { t } = useTranslation();
  const id = useId();
  const canonical = canonicalCategoryColor(value);
  const invalid = value !== '' && canonical === null;

  return (
    <fieldset className={styles.picker}>
      <legend>{t('categories.fields.color')}</legend>

      <label className={styles.toggle}>
        <input
          checked={value !== ''}
          onChange={(event) => onChange(event.target.checked ? NEUTRAL_START : '')}
          type="checkbox"
        />
        <span>{t('categories.identity.enableColor')}</span>
      </label>

      {value === '' ? (
        <p className={styles.hint}>{t('categories.identity.noColor')}</p>
      ) : (
        <div className={styles.custom}>
          <label>
            <span>{t('categories.identity.customColor')}</span>
            <input
              className={styles.swatch}
              onChange={(event) => onChange(event.target.value.toUpperCase())}
              type="color"
              // A half-typed hex keeps the swatch on the last usable colour
              // instead of snapping to black while the owner is still typing.
              value={canonical ?? NEUTRAL_START}
            />
          </label>
          <label>
            <span>{t('categories.identity.hex')}</span>
            <input
              aria-describedby={`${id}-help`}
              aria-invalid={invalid ? true : undefined}
              autoComplete="off"
              maxLength={7}
              onChange={(event) => onChange(event.target.value.toUpperCase())}
              placeholder="#RRGGBB"
              spellCheck={false}
              value={value}
            />
          </label>
        </div>
      )}

      <p className={invalid ? styles.error : styles.hint} id={`${id}-help`}>
        {t(invalid ? 'categories.validation.color' : 'categories.identity.colorHelp')}
      </p>
    </fieldset>
  );
}
