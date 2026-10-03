import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import {
  hasField,
  type FieldKey,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationMatrix';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { OPERATION_COLUMNS } from './operationColumns';
import styles from './OperationsTable.module.css';

interface Props {
  items: PortfolioOperation[];
  onDetail: (operation: PortfolioOperation) => void;
  onEdit: (operation: PortfolioOperation) => void;
  onVoid: (operation: PortfolioOperation) => void;
  onReorder: (operation: PortfolioOperation) => void;
}

export function OperationsTable({ items, onDetail, onEdit, onVoid, onReorder }: Props) {
  const { i18n } = useTranslation();
  const locale = i18n.language;

  function cell(operation: PortfolioOperation, field: FieldKey, content: string | null) {
    return content !== null && hasField(operation.type, field) ? (
      content
    ) : (
      <EmptyValue label="Non applicable" />
    );
  }

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Opérations du portefeuille</caption>
          <thead>
            <tr>
              {OPERATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map((operation) => {
              const active = operation.state === 'ACTIVE';
              const label = `${operationTypeLabel(operation.type)} du ${formatCalendarNumericDay(operation.tradeDate)}`;
              return (
                <tr className={active ? undefined : styles.voided} key={operation.id}>
                  <th scope="row">{formatCalendarNumericDay(operation.tradeDate)}</th>
                  <td>
                    <span className={styles.type}>
                      <span>{operationTypeLabel(operation.type)}</span>
                      {active ? null : (
                        <StatusBadge tone="info">
                          {operation.voidKind === 'REPLACED' ? 'Remplacée' : 'Annulée'}
                        </StatusBadge>
                      )}
                    </span>
                  </td>
                  <td>{operation.instrument?.name ?? <EmptyValue label="Aucun instrument" />}</td>
                  <td className={styles.number}>
                    {cell(
                      operation,
                      'quantity',
                      operation.quantity && formatDecimal(operation.quantity, locale),
                    )}
                  </td>
                  <td className={styles.number}>
                    {cell(
                      operation,
                      'unitPrice',
                      operation.unitPrice && formatDecimal(operation.unitPrice, locale),
                    )}
                  </td>
                  <td className={styles.number}>
                    {cell(
                      operation,
                      'grossAmount',
                      operation.grossAmount &&
                        formatAmount(
                          operation.grossAmount.value,
                          operation.grossAmount.assetCode,
                          locale,
                        ),
                    )}
                  </td>
                  <td className={styles.number}>
                    {cell(
                      operation,
                      'fees',
                      operation.fees &&
                        formatAmount(operation.fees.value, operation.fees.assetCode, locale),
                    )}
                  </td>
                  <td className={styles.number}>
                    {cell(
                      operation,
                      'taxes',
                      operation.taxes &&
                        formatAmount(operation.taxes.value, operation.taxes.assetCode, locale),
                    )}
                  </td>
                  <td className={styles.number}>
                    <CashAmount amount={operation.cashAmount} />
                  </td>
                  <td>
                    <ActionMenu
                      label={`Actions pour l’opération ${label}`}
                      items={[
                        ...(active && operation.activeDayOperationCount >= 2
                          ? [
                              {
                                id: 'reorder',
                                icon: 'move' as const,
                                label: `Réordonner la journée du ${formatCalendarNumericDay(operation.tradeDate)}`,
                                text: 'Réordonner la journée',
                                onSelect: () => onReorder(operation),
                              },
                            ]
                          : []),
                        {
                          id: 'detail',
                          icon: 'info',
                          label: `Détail de l’opération ${label}`,
                          text: 'Détail',
                          onSelect: () => onDetail(operation),
                        },
                        {
                          id: 'edit',
                          icon: 'edit',
                          label: `Modifier l’opération ${label}`,
                          text: 'Modifier',
                          disabled: !active,
                          onSelect: () => onEdit(operation),
                        },
                        {
                          id: 'void',
                          icon: 'archive',
                          label: `Annuler l’opération ${label}`,
                          text: 'Annuler',
                          disabled: !active,
                          onSelect: () => onVoid(operation),
                        },
                      ]}
                    />
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
