import { useTranslation } from 'react-i18next';
import styles from './RulePagination.module.css';

interface RulePaginationProps {
  onPageChange: (page: number) => void;
  page: number;
  pending?: boolean;
  totalPages: number;
}

export function RulePagination({
  onPageChange,
  page,
  pending = false,
  totalPages,
}: RulePaginationProps) {
  const { t } = useTranslation();

  return (
    <nav className={styles.pagination} aria-label={t('categorizationRules.pagination.label')}>
      <button
        aria-disabled={pending || undefined}
        className="secondary-action"
        disabled={page === 1}
        onClick={() => onPageChange(page - 1)}
        type="button"
      >
        {t('categorizationRules.pagination.previous')}
      </button>
      <span aria-live="polite">
        {t('categorizationRules.pagination.position', { page, total: totalPages })}
      </span>
      <button
        aria-disabled={pending || undefined}
        className="secondary-action"
        disabled={page >= totalPages}
        onClick={() => onPageChange(page + 1)}
        type="button"
      >
        {t('categorizationRules.pagination.next')}
      </button>
    </nav>
  );
}
