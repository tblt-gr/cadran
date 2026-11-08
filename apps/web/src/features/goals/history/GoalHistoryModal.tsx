import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { GoalHistoryTable } from './GoalHistoryTable';
import styles from './GoalHistory.module.css';

/** The recorded versions of a goal as one table, newest first. */
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
    <Modal intent="detail" close={close} title={t('goals.history.title')}>
      {items.length === 0 ? (
        <p className={styles.empty}>{t('goals.history.empty')}</p>
      ) : (
        <GoalHistoryTable assetCode={assetCode} items={items} />
      )}
    </Modal>
  );
}
