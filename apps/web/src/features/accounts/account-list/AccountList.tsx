import type { Account, AccountOverviewRow } from '@cadran/api-client';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { useTemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import {
  accountActionHref,
  accountMenuItems,
  INSTRUMENTS_HREF,
} from '@/features/accounts/account-actions-menu/accountMenuItems';
import { navigateFromPage } from '@/hooks/use-client-navigation';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { depositCeilingOf } from '@/lib/depositCeiling';
import { AccountNameCell } from '@/features/accounts/account-list/account-name-cell/AccountNameCell';
import styles from './AccountList.module.css';

interface AccountListProps {
  /** Null while the rows are being read: the table then mirrors its final markup with blocks. */
  accounts: AccountOverviewRow[] | null;
  /** The asset of the group total: a row in another asset says so. */
  groupAssetCode?: string | null;
  /** More rows exist beyond the ones listed, so the last listed row cannot know its successor. */
  hasMore?: boolean;
  label: string;
  moveDisabled?: boolean;
  /** Shows the move controls; they stay out of the way until the owner asks to reorder. */
  reordering?: boolean;
  onArchive: (account: Account) => void;
  /** Places the account before `beforeAccountId`, or last when it is null. */
  onMove: (account: AccountOverviewRow, beforeAccountId: string | null) => void;
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
  reordering = false,
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

  function move(
    account: AccountOverviewRow,
    direction: 'up' | 'down',
    beforeAccountId: string | null,
  ) {
    pendingFocus.current = { id: account.id, direction };
    onMove(account, beforeAccountId);
  }

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table className={reordering ? styles.reordering : undefined}>
          <caption className="sr-only">{label}</caption>
          <colgroup>
            <col />
            <col className={styles.balanceColumn} />
            <col className={styles.actionsColumn} />
          </colgroup>
          <thead>
            <tr>
              <th scope="col">{t('accounts.fields.label')}</th>
              <th scope="col">{t('accounts.fields.balance')}</th>
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
                      <AccountNameCell account={null} />
                    </th>
                    <td data-label={t('accounts.fields.balance')}>
                      <AccountValuationCell provenance={false} valuation={null} />
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
                  // A section can list several primary groups; an account only moves inside its own.
                  const previous = accounts[index - 1];
                  const next = accounts[index + 1];
                  const after = accounts[index + 2];
                  const sameGroup = (other: AccountOverviewRow | undefined) =>
                    other !== undefined && other.primaryGroupId === account.primaryGroupId;
                  const canMoveUp = sameGroup(previous);
                  const canMoveDown =
                    !last && sameGroup(next) && !(hasMore && index === accounts.length - 2);
                  const identity = [
                    account.institution,
                    productName(account),
                    account.maskedIdentifier && `••${account.maskedIdentifier}`,
                  ]
                    .filter(Boolean)
                    .join(' · ');

                  return (
                    <tr key={account.id}>
                      <th data-label={t('accounts.fields.label')} scope="row">
                        <AccountNameCell
                          account={account}
                          assetWarning={
                            groupAssetCode !== null && account.assetCode !== groupAssetCode
                              ? t('accounts.overview.assetWarning', { asset: account.assetCode })
                              : null
                          }
                          identity={identity}
                        />
                      </th>
                      <td data-label={t('accounts.fields.balance')}>
                        <AccountValuationCell
                          ceiling={
                            asOf === undefined
                              ? null
                              : depositCeilingOf(account, products.items, models.items)
                          }
                          provenance={false}
                          startingBalanceHint={account.valuationMode === 'TRANSACTIONS'}
                          valuation={account.valuation}
                        />
                      </td>
                      <td data-label={t('accounts.list.actions')}>
                        <div className={styles.rowActions}>
                          {reordering ? (
                            <>
                              <button
                                aria-label={t('accounts.overview.moveUp', { label: account.label })}
                                className={`icon-ghost ${styles.moveButton}`}
                                data-move={`${account.id}:up`}
                                disabled={moveDisabled || !canMoveUp}
                                onClick={() => move(account, 'up', previous!.id)}
                                title={t('accounts.overview.moveUp', { label: account.label })}
                                type="button"
                              >
                                <Icon name="arrow-up" />
                              </button>
                              <button
                                aria-label={t('accounts.overview.moveDown', {
                                  label: account.label,
                                })}
                                className={`icon-ghost ${styles.moveButton}`}
                                data-move={`${account.id}:down`}
                                disabled={moveDisabled || !canMoveDown}
                                onClick={() =>
                                  move(account, 'down', sameGroup(after) ? after!.id : null)
                                }
                                title={t('accounts.overview.moveDown', { label: account.label })}
                                type="button"
                              >
                                <Icon name="arrow-down" />
                              </button>
                            </>
                          ) : null}
                          <ActionMenu
                            items={accountMenuItems({
                              account,
                              handlers: {
                                onAddOperation: () =>
                                  navigateFromPage(
                                    accountActionHref(
                                      account,
                                      valuedByPortfolio(account) ? 'operation' : 'transaction',
                                    ),
                                  ),
                                onAddTransfer: () =>
                                  navigateFromPage(accountActionHref(account, 'transfer')),
                                onArchive: () => onArchive(account),
                                onEdit: () => onEdit(account),
                                onInstruments: () => navigateFromPage(INSTRUMENTS_HREF),
                                onReconcile: () => onReconcile(account),
                                onRecordBalance: () => onRecordBalance(account),
                                onRecordValuation: () =>
                                  navigateFromPage(accountActionHref(account, 'valuation')),
                                onRules: () => onRules(account),
                              },
                              names: {
                                archive: t('accounts.list.archiveAccount', {
                                  label: account.label,
                                }),
                                edit: t('accounts.list.editAccount', { label: account.label }),
                                instruments: t('accounts.list.instrumentsOfAccount', {
                                  label: account.label,
                                }),
                                reconcile: t('accounts.list.reconcileOfAccount', {
                                  label: account.label,
                                }),
                                operation: t('accounts.list.operationOfAccount', {
                                  label: account.label,
                                }),
                                recordBalance: t('accounts.list.recordBalanceOfAccount', {
                                  label: account.label,
                                }),
                                rules: t('accounts.list.rulesOfAccount', { label: account.label }),
                                transfer: t('accounts.list.transferOfAccount', {
                                  label: account.label,
                                }),
                                valuation: t('accounts.list.valuationOfAccount', {
                                  label: account.label,
                                }),
                              },
                              t,
                            })}
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
