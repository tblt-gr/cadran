import { useTranslation } from 'react-i18next';
import styles from './ProductModelPagination.module.css';

interface ProductModelPaginationProps {
  onPageChange: (page: number) => void;
  page: number;
  totalPages: number;
}

export function ProductModelPagination({
  onPageChange,
  page,
  totalPages,
}: ProductModelPaginationProps) {
  const { t } = useTranslation();

  return (
    <nav className={styles.pagination} aria-label={t('productModels.pagination.label')}>
      <button
        className="secondary-action"
        disabled={page === 1}
        onClick={() => onPageChange(page - 1)}
        type="button"
      >
        {t('productModels.pagination.previous')}
      </button>
      <span>{t('productModels.pagination.position', { page, total: totalPages })}</span>
      <button
        className="secondary-action"
        disabled={page >= totalPages}
        onClick={() => onPageChange(page + 1)}
        type="button"
      >
        {t('productModels.pagination.next')}
      </button>
    </nav>
  );
}
