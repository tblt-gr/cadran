import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { navigationItems } from '@/lib/navigation';
import { Icon } from '@/components/ui/icon/Icon';
import { ModalSheet } from '@/components/ui/modal-sheet/ModalSheet';
import styles from './MobileMoreSheet.module.css';

interface MobileMoreSheetProps {
  accountSlot?: ReactNode;
  close: () => void;
  navigate: (path: string) => void;
  path: string;
}

export function MobileMoreSheet({ accountSlot, close, navigate, path }: MobileMoreSheetProps) {
  const { t } = useTranslation();

  return (
    <ModalSheet ariaLabel={t('navigation.moreDialog')} close={close}>
      <div className={styles.heading}>
        <h2>{t('navigation.explore')}</h2>
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
      <nav aria-label={t('navigation.moreDialog')} className={styles.navigation}>
        {navigationItems
          .filter((item) => !item.mobile)
          .map((item) => (
            <a
              aria-current={item.match(path) ? 'page' : undefined}
              href={item.href}
              key={item.href}
              onClick={(event) => {
                event.preventDefault();
                navigate(item.href);
                close();
              }}
            >
              <Icon name={item.icon} />
              <span>{t(item.labelKey)}</span>
              <Icon name="chevron-right" size={18} />
            </a>
          ))}
      </nav>
      {accountSlot ? <div className={styles.account}>{accountSlot}</div> : null}
    </ModalSheet>
  );
}
