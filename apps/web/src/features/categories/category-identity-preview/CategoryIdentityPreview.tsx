import { useTranslation } from 'react-i18next';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import styles from './CategoryIdentityPreview.module.css';

interface CategoryIdentityPreviewProps {
  color: string;
  icon: string;
  label: string;
}

export function CategoryIdentityPreview({ color, icon, label }: CategoryIdentityPreviewProps) {
  const { t } = useTranslation();
  const artwork = categoryIcon(icon);
  const colorLabel =
    canonicalCategoryColor(color) ??
    t(color ? 'categories.identity.invalidColor' : 'categories.identity.noColor');
  const iconLabel = artwork
    ? t(`categories.identity.icons.${artwork.key}`)
    : t(icon ? 'categories.identity.unknownPreview' : 'categories.identity.noIcon');

  return (
    <div aria-label={t('categories.identity.preview')} className={styles.preview} role="status">
      <span className={styles.heading}>{t('categories.identity.preview')}</span>
      <CategoryIdentity
        color={color}
        icon={icon}
        label={label || t('categories.identity.emptyLabel')}
      />
      <small>
        {colorLabel} · {iconLabel}
      </small>
    </div>
  );
}
