import { useTranslation } from 'react-i18next';
import { PositionsList } from './positions-list/PositionsList';
import styles from './PositionsTab.module.css';

/** Faithful cold-load layout used before the account or positions response is available. */
export function PositionsTabSkeleton({ status }: { status?: string }) {
  const { t } = useTranslation();
  return (
    <div className={styles.tab}>
      <PositionsList
        items={null}
        loadingLabel={status ?? t('portfolio.account.positions.loading')}
        onOpen={() => {}}
        onReorder={() => {}}
      />
    </div>
  );
}
