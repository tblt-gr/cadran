import { useTranslation } from 'react-i18next';
import { Icon } from '../design-system/Icon';
import { ModalSheet } from '../design-system/ModalSheet';

export type FoundationAction = 'add' | 'search';

interface FoundationActionSheetProps {
  action: FoundationAction;
  close: () => void;
}

export function FoundationActionSheet({ action, close }: FoundationActionSheetProps) {
  const { t } = useTranslation();

  return (
    <ModalSheet ariaLabel={t(`foundationActions.${action}.title`)} close={close}>
      <div className="more-sheet__heading">
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
      <div className="foundation-action-state" role="status">
        <div className="placeholder-page__mark">
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
