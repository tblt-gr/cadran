import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { HistoryVersion } from './HistoryVersion';
import styles from './GoalHistory.module.css';

/** Versions of a goal, newest first, each folded to its month so the page stays short. */
export function GoalHistoryModal({
  assetCode,
  close,
  items,
}: {
  assetCode: string;
  close: () => void;
  items: GoalVersionHistoryItem[];
}) {
  const { t } = useTranslation();
  return (
    <Modal close={close} title={t('goals.history.title')}>
      {items.length === 0 ? (
        <p className={styles.empty}>{t('goals.history.empty')}</p>
      ) : (
        <ol aria-label={t('goals.history.caption')} className={styles.versions}>
          {items.map((item, index) => (
            <HistoryVersion
              assetCode={assetCode}
              initiallyOpen={index === 0}
              item={item}
              key={item.versionNo}
            />
          ))}
        </ol>
      )}
    </Modal>
  );
}
