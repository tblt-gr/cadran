import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './FilterPanelHeading.module.css';

interface FilterPanelHeadingProps {
  /** Controls placed beside the title, such as the close button of the mobile sheet. */
  actions?: ReactNode;
  disabled: boolean;
  /** Set where filters apply and the chip row is out of sight: offers `Tout effacer`. */
  onReset?: () => void;
  titleId: string;
}

export function FilterPanelHeading({
  actions,
  disabled,
  onReset,
  titleId,
}: FilterPanelHeadingProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.heading}>
      <h2 id={titleId}>{t('transactions.filters.panelTitle')}</h2>
      {onReset ? (
        <button
          aria-label={t('transactions.filters.reset')}
          className="icon-button"
          disabled={disabled}
          onClick={onReset}
          title={t('transactions.filters.reset')}
          type="button"
        >
          <Icon name="delete" size={16} />
        </button>
      ) : null}
      {actions}
    </div>
  );
}
