import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CATEGORY_ICONS, categoryIcon } from '@/features/categories/category-icons';
import styles from './CategoryIconPicker.module.css';

interface CategoryIconPickerProps {
  onChange: (value: string) => void;
  value: string;
}

function normalize(value: string) {
  return (
    value
      .normalize('NFKD')
      .replace(/\p{M}/gu, '')
      .toLocaleLowerCase('fr')
      // NFKD leaves the French ligatures intact, so someone typing "coeur"
      // would never reach "Cœur".
      .replace(/œ/g, 'oe')
      .replace(/æ/g, 'ae')
      .trim()
  );
}

/**
 * A flat grid over the whole catalogue. Glyphs carry no category meaning, so
 * there are no sections and no suggestions: any icon can be chosen for any
 * category.
 *
 * The cells are native radios, which is what makes the grid keyboard-operable
 * and its selected state announced without a single custom key handler. Each
 * glyph is decorative; the cell's accessible name is the icon's French name.
 */
export function CategoryIconPicker({ onChange, value }: CategoryIconPickerProps) {
  const { t } = useTranslation();
  const id = useId();
  const [search, setSearch] = useState('');
  const query = normalize(search);
  const icons = CATEGORY_ICONS.filter(
    (icon) =>
      normalize(t(`categories.identity.icons.${icon.key}`)).includes(query) ||
      icon.key.includes(query),
  );
  const unknown = value !== '' && !categoryIcon(value);

  return (
    <fieldset className={styles.picker}>
      <legend>{t('categories.fields.icon')}</legend>
      <label className={styles.search}>
        <span>{t('categories.identity.searchIcons')}</span>
        <input
          autoComplete="off"
          maxLength={80}
          onChange={(event) => setSearch(event.target.value)}
          type="search"
          value={search}
        />
      </label>
      {unknown ? <p className={styles.hint}>{t('categories.identity.unknownIcon')}</p> : null}

      <div className={styles.catalogue}>
        <label className={`${styles.cell} ${styles.clear}`}>
          <input
            checked={value === ''}
            className="sr-only"
            name={id}
            onChange={() => onChange('')}
            type="radio"
          />
          <span className={styles.box}>{t('categories.identity.noIcon')}</span>
        </label>

        {icons.map((icon) => (
          <label className={styles.cell} key={icon.key}>
            <input
              checked={value === icon.key}
              className="sr-only"
              name={id}
              onChange={() => onChange(icon.key)}
              type="radio"
            />
            <span className={styles.box}>
              <svg
                aria-hidden="true"
                className={styles.glyph}
                focusable="false"
                viewBox="0 0 24 24"
              >
                <path d={icon.path} />
              </svg>
            </span>
            <span className="sr-only">{t(`categories.identity.icons.${icon.key}`)}</span>
          </label>
        ))}
      </div>

      <p className={styles.hint} role="status">
        {icons.length === 0
          ? t('categories.identity.noMatches')
          : t('categories.identity.iconMatches', { count: icons.length })}
      </p>
    </fieldset>
  );
}
