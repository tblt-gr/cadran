import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';

export interface OperationRowActions {
  onDetail: (operation: PortfolioOperation) => void;
  onEdit: (operation: PortfolioOperation) => void;
  onReorder: (operation: PortfolioOperation) => void;
  onVoid: (operation: PortfolioOperation) => void;
}

/** Inert actions for the loading rows, which never open a menu. */
export const noRowActions: OperationRowActions = {
  onDetail: () => undefined,
  onEdit: () => undefined,
  onReorder: () => undefined,
  onVoid: () => undefined,
};

/** The three-dot menu of an operation: reorder its day, detail, edit and void. */
export function OperationRowMenu({
  actions,
  operation,
}: {
  actions: OperationRowActions;
  operation: PortfolioOperation | null;
}) {
  const { t } = useTranslation();
  if (operation === null)
    return (
      <ActionMenu disabled items={[]} label={t('portfolio.account.operations.menu.actions')} />
    );

  const active = operation.state === 'ACTIVE';
  const day = formatCalendarNumericDay(operation.tradeDate);
  const label = t('portfolio.account.operations.menu.operationLabel', {
    type: operationTypeLabel(t, operation.type),
    day,
  });

  return (
    <ActionMenu
      label={t('portfolio.account.operations.menu.actionsFor', { label })}
      items={[
        ...(active && operation.activeDayOperationCount >= 2
          ? [
              {
                id: 'reorder',
                icon: 'move' as const,
                label: t('portfolio.account.operations.menu.reorderDay', { day }),
                text: t('portfolio.account.operations.menu.reorder'),
                onSelect: () => actions.onReorder(operation),
              },
            ]
          : []),
        {
          id: 'detail',
          icon: 'info',
          label: t('portfolio.account.operations.menu.detailOf', { label }),
          text: t('portfolio.account.operations.menu.detail'),
          onSelect: () => actions.onDetail(operation),
        },
        {
          id: 'edit',
          icon: 'edit',
          label: t('portfolio.account.operations.menu.editOf', { label }),
          text: t('portfolio.account.operations.menu.edit'),
          disabled: !active,
          onSelect: () => actions.onEdit(operation),
        },
        {
          id: 'void',
          icon: 'archive',
          label: t('portfolio.account.operations.menu.voidOf', { label }),
          text: t('actions.cancel'),
          disabled: !active,
          onSelect: () => actions.onVoid(operation),
        },
      ]}
    />
  );
}
