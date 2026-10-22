import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { AccountFilters } from '@/features/accounts/account-filters/AccountFilters';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountsPageActions.module.css';

interface AccountsPageActionsProps {
  includeArchived: boolean;
  includeClosed: boolean;
  onCreate: () => void;
  onIncludeArchivedChange: (checked: boolean) => void;
  onIncludeClosedChange: (checked: boolean) => void;
  onToggleReordering: () => void;
  reordering: boolean;
}

/** One primary action and the tools that organise the account overview. */
export function AccountsPageActions({
  includeArchived,
  includeClosed,
  onCreate,
  onIncludeArchivedChange,
  onIncludeClosedChange,
  onToggleReordering,
  reordering,
}: AccountsPageActionsProps) {
  const { t } = useTranslation();
  const reorderLabel = t(reordering ? 'accounts.reorder.stop' : 'accounts.reorder.start');

  return (
    <>
      <HeaderActions>
        <button
          aria-label={t('accounts.add')}
          className={`primary-action ${styles.add}`}
          onClick={onCreate}
          title={t('accounts.add')}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>{t('accounts.add')}</span>
        </button>
        <a
          aria-label={t('accounts.manageGroups')}
          className={`secondary-action ${styles.secondary}`}
          href="/accounts/groups"
          onClick={(event) => handleClientNavigation(event, '/accounts/groups')}
          title={t('accounts.manageGroups')}
        >
          <Icon name="layers" />
          <span className={styles.secondaryLabel}>{t('accounts.actions.groups')}</span>
        </a>
        <button
          aria-label={reorderLabel}
          aria-pressed={reordering}
          className={`secondary-action ${styles.secondary}`}
          onClick={onToggleReordering}
          title={reorderLabel}
          type="button"
        >
          <Icon name={reordering ? 'check-circle' : 'reorder'} />
          <span className={styles.secondaryLabel}>
            {t(reordering ? 'accounts.actions.done' : 'accounts.actions.reorder')}
          </span>
        </button>
      </HeaderActions>
      <AccountFilters
        includeArchived={includeArchived}
        includeClosed={includeClosed}
        onIncludeArchivedChange={onIncludeArchivedChange}
        onIncludeClosedChange={onIncludeClosedChange}
      />
    </>
  );
}
