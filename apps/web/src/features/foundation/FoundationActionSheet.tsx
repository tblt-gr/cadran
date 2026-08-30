import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { ModalSheet } from '@/components/ui/modal-sheet/ModalSheet';
import styles from './FoundationActionSheet.module.css';

export type FoundationAction = 'add' | 'search';

interface FoundationActionSheetProps {
  action: FoundationAction;
  close: () => void;
}

export function FoundationActionSheet({ action, close }: FoundationActionSheetProps) {
  const { t } = useTranslation();

  return (
    <ModalSheet ariaLabel={t(`foundationActions.${action}.title`)} close={close}>
      <div className={styles.heading}>
        <h2>{t(`foundationActions.${action}.title`)}</h2>
        <button
          aria-label={t('actions.close')}
          className="icon-button"
          data-autofocus
          onClick={close}
          type="button"
        >
          <Icon name="close" />
        </button>
      </div>
      <div className={styles.state} role="status">
        <div className={styles.mark}>
          <Icon name={action === 'search' ? 'search' : 'add'} size={26} />
        </div>
        <div>
          <h3>{t(`foundationActions.${action}.status`)}</h3>
          <p>{t(`foundationActions.${action}.description`)}</p>
        </div>
      </div>
    </ModalSheet>
  );
}
