import { useTranslation } from 'react-i18next';
import styles from './AccountPagination.module.css';

interface AccountPaginationProps {
  onPageChange: (page: number) => void;
  page: number;
  totalPages: number;
}

export function AccountPagination({ onPageChange, page, totalPages }: AccountPaginationProps) {
  const { t } = useTranslation();

  return (
    <nav className={styles.pagination} aria-label={t('accounts.pagination.label')}>
      <button
        className="secondary-action"
        disabled={page === 1}
        onClick={() => onPageChange(page - 1)}
        type="button"
      >
        {t('accounts.pagination.previous')}
      </button>
      <span>{t('accounts.pagination.position', { page, total: totalPages })}</span>
      <button
        className="secondary-action"
        disabled={page >= totalPages}
        onClick={() => onPageChange(page + 1)}
        type="button"
      >
        {t('accounts.pagination.next')}
      </button>
    </nav>
  );
}
