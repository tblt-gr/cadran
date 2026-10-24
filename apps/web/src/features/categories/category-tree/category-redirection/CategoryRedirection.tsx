import type { CategoryReplacement } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './CategoryRedirection.module.css';

interface CategoryRedirectionProps {
  replacement: CategoryReplacement | null;
}

/**
 * Where a category sends its classifications. A merge redirects the whole
 * history and a replacement only from a date, so the two never read alike, and
 * neither is carried by colour alone.
 */
export function CategoryRedirection({ replacement }: CategoryRedirectionProps) {
  const { t, i18n } = useTranslation();

  if (replacement === null) {
    return <span className={styles.none}>{t('categories.list.noRedirection')}</span>;
  }

  const target = replacement.targetLabel ?? t('categories.list.unavailableTarget');

  return (
    <span className={styles.redirection}>
      {replacement.kind === 'MERGE'
        ? t('categories.list.mergedInto', { target })
        : t('categories.list.replacedFrom', {
            date: new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium' }).format(
              new Date(`${replacement.effectiveFrom ?? ''}T00:00:00Z`),
            ),
            target,
          })}
    </span>
  );
}
