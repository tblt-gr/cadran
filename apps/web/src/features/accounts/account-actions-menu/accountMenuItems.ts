import type { Account } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import type { ActionMenuItem } from '@/components/ui/action-menu/ActionMenu';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';

type MenuAccount = Pick<
  Account,
  'id' | 'kind' | 'valuationMode' | 'status' | 'editable' | 'valuation'
>;

export type AccountMenuItemId =
  | 'operation'
  | 'transfer'
  | 'recordBalance'
  | 'reconcile'
  | 'valuation'
  | 'takeover'
  | 'rules'
  | 'instruments'
  | 'edit'
  | 'archive';

/**
 * What each item does. An action the surface has no handler for is left out of the menu: an item
 * never exists as a silent no-op. Rules, edit and archive are available everywhere.
 */
export interface AccountMenuHandlers {
  /** Only the accounts list supplies it: the pages keep their primary button instead. */
  onAddOperation?: () => void;
  onAddTransfer?: () => void;
  onArchive: () => void;
  onEdit: () => void;
  onInstruments?: () => void;
  onReconcile?: () => void;
  onRecordBalance?: () => void;
  onRecordValuation?: () => void;
  /** Only supplied while the account can still take over the positions it already holds. */
  onTakeover?: () => void;
  onRules: () => void;
}

export const INSTRUMENTS_HREF = '/portfolios/instruments';

export type ArrivalAction =
  | 'transaction'
  | 'transfer'
  | 'operation'
  | 'valuation'
  | 'record-balance'
  | 'reconcile'
  | 'record-valuation';

/** Account page that opens the modal of a transfer or a valuation once on arrival. */
export function accountActionHref(account: Pick<Account, 'id'>, action: ArrivalAction): string {
  const base =
    action === 'valuation' || action === 'operation' || action === 'record-valuation'
      ? `/portfolios/${account.id}`
      : `/accounts/${account.id}`;
  return `${base}?action=${action}`;
}

/**
 * The actions of one account, in a fixed order, shared by the header menu of the account and
 * portfolio pages and by the row menu of the accounts list. Availability by type comes from the
 * valuation mode; a state that cannot accept an action keeps the item visible and disabled with
 * its reason as a visible hint and in the accessible name. `names` replaces the base accessible
 * name of an item (a row names the account it acts on).
 */
export function accountMenuItems({
  account,
  handlers,
  names = {},
  t,
}: {
  account: MenuAccount;
  handlers: AccountMenuHandlers;
  names?: Partial<Record<AccountMenuItemId, string>>;
  t: TFunction;
}): ActionMenuItem[] {
  const writable = account.editable && account.status === 'ACTIVE';
  const locked = writable
    ? undefined
    : t(
        `accounts.detail.actionsMenu.reasons.${account.status === 'ACTIVE' ? 'READ_ONLY' : account.status}`,
      );
  const nothingToReconcile =
    account.valuation.snapshotId === null ||
    account.valuation.reconciliationStatus !== 'UNRECONCILED';

  function item(
    id: AccountMenuItemId,
    icon: ActionMenuItem['icon'],
    onSelect: (() => void) | undefined,
    reason?: string,
  ): ActionMenuItem[] {
    if (onSelect === undefined) return [];
    const text = t(`accounts.detail.actionsMenu.${id}`);
    const name = names[id] ?? text;
    return [
      {
        disabled: reason !== undefined,
        hint: reason,
        icon,
        id,
        label:
          reason === undefined
            ? name
            : t('accounts.detail.actionsMenu.unavailable', { action: name, reason }),
        onSelect,
        text,
      },
    ];
  }

  const common = [
    ...item('rules', 'sliders', handlers.onRules),
    ...item('edit', 'edit', handlers.onEdit, locked),
    ...item('archive', 'archive', handlers.onArchive, locked),
  ];
  const [rules, edit, archive] = common;

  const operation = item('operation', 'add', handlers.onAddOperation, locked);

  if (valuedByPortfolio(account)) {
    return [
      ...operation,
      ...item('valuation', 'balance', handlers.onRecordValuation, locked),
      ...item('takeover', 'investments', handlers.onTakeover, locked),
      rules,
      ...item('instruments', 'chart-line', handlers.onInstruments),
      edit,
      archive,
    ];
  }

  return [
    ...operation,
    ...item('transfer', 'transfer', handlers.onAddTransfer, locked),
    ...item('recordBalance', 'balance', handlers.onRecordBalance, locked),
    ...item(
      'reconcile',
      'check-circle',
      handlers.onReconcile,
      locked ??
        (nothingToReconcile
          ? t('accounts.detail.actionsMenu.reasons.NOTHING_TO_RECONCILE')
          : undefined),
    ),
    rules,
    edit,
    archive,
  ];
}
