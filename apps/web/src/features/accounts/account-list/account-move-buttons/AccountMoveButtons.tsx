import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './AccountMoveButtons.module.css';

interface AccountMoveButtonsProps {
  accountId: string;
  label: string;
  disabled: boolean;
  canMoveUp: boolean;
  canMoveDown: boolean;
  onMoveUp: () => void;
  onMoveDown: () => void;
}

/** The pair of reordering controls of one row, addressed by `data-move` to restore focus. */
export function AccountMoveButtons({
  accountId,
  label,
  disabled,
  canMoveUp,
  canMoveDown,
  onMoveUp,
  onMoveDown,
}: AccountMoveButtonsProps) {
  const { t } = useTranslation();

  return (
    <>
      <button
        aria-label={t('accounts.overview.moveUp', { label })}
        className={`icon-ghost ${styles.moveButton}`}
        data-move={`${accountId}:up`}
        disabled={disabled || !canMoveUp}
        onClick={onMoveUp}
        title={t('accounts.overview.moveUp', { label })}
        type="button"
      >
        <Icon name="arrow-up" />
      </button>
      <button
        aria-label={t('accounts.overview.moveDown', { label })}
        className={`icon-ghost ${styles.moveButton}`}
        data-move={`${accountId}:down`}
        disabled={disabled || !canMoveDown}
        onClick={onMoveDown}
        title={t('accounts.overview.moveDown', { label })}
        type="button"
      >
        <Icon name="arrow-down" />
      </button>
    </>
  );
}
