import type { PortfolioOperation } from '@cadran/api-client';
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
  if (operation === null) return <ActionMenu disabled items={[]} label="Actions de l’opération" />;

  const active = operation.state === 'ACTIVE';
  const day = formatCalendarNumericDay(operation.tradeDate);
  const label = `${operationTypeLabel(operation.type)} du ${day}`;

  return (
    <ActionMenu
      label={`Actions pour l’opération ${label}`}
      items={[
        ...(active && operation.activeDayOperationCount >= 2
          ? [
              {
                id: 'reorder',
                icon: 'move' as const,
                label: `Réordonner la journée du ${day}`,
                text: 'Réordonner la journée',
                onSelect: () => actions.onReorder(operation),
              },
            ]
          : []),
        {
          id: 'detail',
          icon: 'info',
          label: `Détail de l’opération ${label}`,
          text: 'Détail',
          onSelect: () => actions.onDetail(operation),
        },
        {
          id: 'edit',
          icon: 'edit',
          label: `Modifier l’opération ${label}`,
          text: 'Modifier',
          disabled: !active,
          onSelect: () => actions.onEdit(operation),
        },
        {
          id: 'void',
          icon: 'archive',
          label: `Annuler l’opération ${label}`,
          text: 'Annuler',
          disabled: !active,
          onSelect: () => actions.onVoid(operation),
        },
      ]}
    />
  );
}
