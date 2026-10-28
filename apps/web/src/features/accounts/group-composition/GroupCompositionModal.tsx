import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { Tabs } from '@/components/ui/tabs/Tabs';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { GroupCompositionPanel } from './GroupCompositionPanel';
import { useGroupComposition } from './useGroupComposition';
import styles from './GroupComposition.module.css';

interface GroupCompositionModalProps {
  asOf: string;
  close: () => void;
  groupId: string;
  label: string;
  returnFocus: RefObject<HTMLElement | null>;
}

/**
 * How one parent group splits, by direct sub-group and by account. The title, date and tab list
 * are known from the heading, so they show at once; a group without sub-groups has no tabs.
 */
export function GroupCompositionModal({
  asOf,
  close,
  groupId,
  label,
  returnFocus,
}: GroupCompositionModalProps) {
  const { t } = useTranslation();
  const composition = useGroupComposition(groupId, asOf);
  const data = composition.data ?? null;
  const subgroupPanel = <GroupCompositionPanel composition={data} view="subgroup" />;
  const accountPanel = <GroupCompositionPanel composition={data} view="account" />;
  const tabbed = data === null || data.bySubgroup.length > 0;

  return (
    <Modal
      close={close}
      eyebrow={formatCalendarNumericDay(asOf)}
      returnFocus={returnFocus}
      title={t('accounts.composition.title', { label })}
    >
      {composition.isError ? (
        <div className={styles.error} role="alert">
          <p>{t('accounts.composition.error')}</p>
          <button
            className="secondary-action"
            onClick={() => void composition.refetch()}
            type="button"
          >
            {t('accounts.composition.retry')}
          </button>
        </div>
      ) : (
        <div aria-busy={data === null}>
          {data === null ? (
            <SkeletonStatus>{t('accounts.composition.loading')}</SkeletonStatus>
          ) : null}
          {tabbed ? (
            <Tabs
              label={t('accounts.composition.tabs')}
              tabs={[
                {
                  id: 'subgroup',
                  label: t('accounts.composition.bySubgroup'),
                  content: subgroupPanel,
                },
                {
                  id: 'account',
                  label: t('accounts.composition.byAccount'),
                  content: accountPanel,
                },
              ]}
            />
          ) : (
            accountPanel
          )}
        </div>
      )}
    </Modal>
  );
}
