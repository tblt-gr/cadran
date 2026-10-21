import type { Account } from '@cadran/api-client';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { useTemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { depositCeilingOf } from '@/lib/depositCeiling';
import styles from './AccountList.module.css';

interface AccountListProps {
  /** Null while the rows are being read: the table then mirrors its final markup with blocks. */
  accounts: Account[] | null;
  /** The asset of the group total: a row in another asset says so. */
  groupAssetCode?: string | null;
  /** More rows exist beyond the ones listed, so the last listed row cannot know its successor. */
  hasMore?: boolean;
  label: string;
  moveDisabled?: boolean;
  onArchive: (account: Account) => void;
  /** Places the account before `beforeAccountId`, or last when it is null. */
  onMove: (account: Account, beforeAccountId: string | null) => void;
  onEdit: (account: Account) => void;
  onReconcile: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onRules: (account: Account) => void;
}

const SKELETON_ROWS = 3;

export function AccountList({
  accounts,
  groupAssetCode = null,
  hasMore = false,
  label,
  moveDisabled = false,
  onArchive,
  onEdit,
  onMove,
  onReconcile,
  onRecordBalance,
  onRules,
}: AccountListProps) {
  const { t } = useTranslation();
  const asOf = accounts?.find((account) => account.valuation.requestedOn)?.valuation.requestedOn;
  const products = useProductOptions(asOf ?? '');
  const models = useTemplateOptions();
  const pendingFocus = useRef<{ id: string; direction: 'up' | 'down' } | null>(null);
  const order = accounts?.map((account) => account.id).join('|');

  // Once the write settles (saved or refused), hand the focus back to the control that was used: it
  // was disabled during the request and a moved row is re-inserted by the refetch.
  useEffect(() => {
    const target = pendingFocus.current;
    if (target === null || moveDisabled) {
      return;
    }
    const preferred = document.querySelector<HTMLButtonElement>(
      `[data-move="${target.id}:${target.direction}"]`,
    );
    const other = document.querySelector<HTMLButtonElement>(
      `[data-move="${target.id}:${target.direction === 'up' ? 'down' : 'up'}"]`,
    );
    (preferred && !preferred.disabled ? preferred : other)?.focus();
    pendingFocus.current = null;
  }, [order, moveDisabled]);

  function productName(account: Account): string {
    return (
      models.items.find((model) => model.id === account.productModelId)?.name ??
      products.items.find((product) => product.code === account.productCode)?.displayName ??
      t(`accounts.kinds.${account.kind}`)
    );
  }

  function move(account: Account, direction: 'up' | 'down', beforeAccountId: string | null) {
    pendingFocus.current = { id: account.id, direction };
    onMove(account, beforeAccountId);
  }

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{label}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.fields.label')}</th>
              <th scope="col">{t('accounts.overview.columnIdentity')}</th>
              <th scope="col">{t('accounts.fields.balance')}</th>
              <th scope="col">{t('accounts.fields.share')}</th>
              <th scope="col">
                <span className="sr-only">{t('accounts.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody aria-hidden={accounts === null ? 'true' : undefined}>
            {accounts === null
              ? Array.from({ length: SKELETON_ROWS }, (_, row) => (
                  <tr key={row}>
                    <th data-label={t('accounts.fields.label')} scope="row">
                      <a className={styles.accountLink}>
                        <SkeletonBlock />
                      </a>
                    </th>
                    <td data-label={t('accounts.overview.columnIdentity')}>
                      <SkeletonBlock />
                    </td>
                    <td data-label={t('accounts.fields.balance')}>
                      <AccountValuationCell valuation={null} />
                    </td>
                    <td data-label={t('accounts.fields.share')}>
                      <SkeletonBlock className={styles.shareSkeleton} />
                    </td>
                    <td data-label={t('accounts.list.actions')}>
                      <div className={styles.rowActions}>
                        <SkeletonBlock />
                      </div>
                    </td>
                  </tr>
                ))
              : accounts.map((account, index) => {
                  const last = index === accounts.length - 1;
                  const canMoveUp = index > 0;
                  const canMoveDown = !last && !(hasMore && index === accounts.length - 2);
                  const identity = [
                    account.institution,
                    account.maskedIdentifier && `••${account.maskedIdentifier}`,
                  ]
                    .filter(Boolean)
                    .join(' · ');

                  return (
                    <tr key={account.id}>
                      <th data-label={t('accounts.fields.label')} scope="row">
                        <a
                          className={styles.accountLink}
                          href={`/accounts/${account.id}`}
                          onClick={(event) =>
                            handleClientNavigation(event, `/accounts/${account.id}`)
                          }
                        >
                          {account.label}
                        </a>
                        {account.status !== 'ACTIVE' ? (
                          <StatusBadge tone={account.status === 'CLOSED' ? 'info' : 'warning'}>
                            {t(`accounts.statuses.${account.status}`)}
                          </StatusBadge>
                        ) : null}
                      </th>
                      <td data-label={t('accounts.overview.columnIdentity')}>
                        <span>{productName(account)}</span>
                        {identity ? <small>{identity}</small> : null}
                        {groupAssetCode !== null && account.assetCode !== groupAssetCode ? (
                          <small>
                            {t('accounts.overview.assetWarning', { asset: account.assetCode })}
                          </small>
                        ) : null}
                      </td>
                      <td data-label={t('accounts.fields.balance')}>
                        <AccountValuationCell
                          ceiling={
                            asOf === undefined
                              ? null
                              : depositCeilingOf(account, products.items, models.items)
                          }
                          startingBalanceHint={account.valuationMode === 'TRANSACTIONS'}
                          valuation={account.valuation}
                        />
                      </td>
                      <td data-label={t('accounts.fields.share')}>
                        <ShareCell share={account.share} />
                      </td>
                      <td data-label={t('accounts.list.actions')}>
                        <div className={styles.rowActions}>
                          <button
                            aria-label={t('accounts.overview.moveUp', { label: account.label })}
                            className="icon-button"
                            data-move={`${account.id}:up`}
                            disabled={moveDisabled || !canMoveUp}
                            onClick={() => move(account, 'up', accounts[index - 1]!.id)}
                            title={t('accounts.overview.moveUp', { label: account.label })}
                            type="button"
                          >
                            <Icon name="arrow-up" />
                          </button>
                          <button
                            aria-label={t('accounts.overview.moveDown', { label: account.label })}
                            className="icon-button"
                            data-move={`${account.id}:down`}
                            disabled={moveDisabled || !canMoveDown}
                            onClick={() => move(account, 'down', accounts[index + 2]?.id ?? null)}
                            title={t('accounts.overview.moveDown', { label: account.label })}
                            type="button"
                          >
                            <Icon name="arrow-down" />
                          </button>
                          <ActionMenu
                            items={[
                              {
                                disabled:
                                  !account.editable || account.valuationMode === 'PORTFOLIO',
                                icon: 'balance',
                                id: 'recordBalance',
                                label: t('accounts.list.recordBalanceOfAccount', {
                                  label: account.label,
                                }),
                                onSelect: () => onRecordBalance(account),
                                text: t('accounts.list.recordBalance'),
                              },
                              {
                                disabled:
                                  !account.editable ||
                                  account.valuationMode === 'PORTFOLIO' ||
                                  account.valuation.snapshotId === null ||
                                  account.valuation.reconciliationStatus !== 'UNRECONCILED',
                                icon: 'check-circle',
                                id: 'reconcile',
                                label: t('accounts.list.reconcileOfAccount', {
                                  label: account.label,
                                }),
                                onSelect: () => onReconcile(account),
                                text: t('accounts.list.reconcile'),
                              },
                              {
                                // Reading the rules changes nothing, so an archived or closed
                                // account still answers for the dates it was open.
                                icon: 'sliders',
                                id: 'rules',
                                label: t('accounts.list.rulesOfAccount', { label: account.label }),
                                onSelect: () => onRules(account),
                                text: t('accounts.list.rules'),
                              },
                              {
                                disabled: !account.editable,
                                icon: 'edit',
                                id: 'edit',
                                label: t('accounts.list.editAccount', { label: account.label }),
                                onSelect: () => onEdit(account),
                                text: t('accounts.list.edit'),
                              },
                              {
                                disabled: !account.editable,
                                icon: 'archive',
                                id: 'archive',
                                label: t('accounts.list.archiveAccount', { label: account.label }),
                                onSelect: () => onArchive(account),
                                text: t('accounts.list.archive'),
                              },
                            ]}
                            label={t('accounts.list.openActions', { label: account.label })}
                          />
                        </div>
                      </td>
                    </tr>
                  );
                })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
