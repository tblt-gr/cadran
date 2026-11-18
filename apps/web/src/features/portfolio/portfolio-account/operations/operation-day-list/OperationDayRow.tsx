import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { DatedCell } from '@/components/ui/dated-list/DatedCell';
import { DatedRow } from '@/components/ui/dated-list/DatedRow';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatDecimal, isZeroDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import { hasField } from '@/features/portfolio/portfolio-account/operations/operation-form/operationMatrix';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { OperationRowMenu, type OperationRowActions } from './OperationRowMenu';
import styles from './OperationDayRow.module.css';

/** The instrument name, "Virement" for a linked transfer, else the type label. */
function operationTitle(operation: PortfolioOperation) {
  if (operation.instrument) return operation.instrument.name;
  if (operation.linkedTransactionId) return 'Virement';
  return operationTypeLabel(operation.type);
}

function legSummary(operation: PortfolioOperation) {
  if (operation.type === 'SWITCH') {
    const sold = operation.legs.filter((leg) => leg.direction === 'OUT').length;
    const bought = operation.legs.filter((leg) => leg.direction === 'IN').length;
    return `${sold} supports vendus, ${bought} achetés`;
  }
  return hasField(operation.type, 'legs') ? `${operation.legs.length} supports` : null;
}

function present(amount: PortfolioOperation['fees']) {
  return amount !== null && !isZeroDecimal(amount.value) ? amount : null;
}

/** One operation of the dated list: instrument and meta on the left, the cash effect on the right. */
export function OperationDayRow({
  actions,
  operation,
}: {
  actions: OperationRowActions;
  /** Null while loading: the row keeps its final structure with blocks for the values. */
  operation: PortfolioOperation | null;
}) {
  const { i18n } = useTranslation();
  const locale = i18n.language;
  const active = operation?.state !== 'VOIDED';
  const fees = operation ? present(operation.fees) : null;
  const taxes = operation ? present(operation.taxes) : null;
  const quantified =
    operation?.quantity != null && hasField(operation.type, 'quantity')
      ? [
          formatDecimal(operation.quantity, locale),
          operation.unitPrice != null && hasField(operation.type, 'unitPrice')
            ? formatDecimal(operation.unitPrice, locale)
            : null,
        ]
          .filter((part) => part !== null)
          .join(' × ')
      : null;
  const detail = operation ? (legSummary(operation) ?? quantified) : null;
  const figure = (name: string, amount: PortfolioOperation['fees']) => (
    <DatedCell column={name === 'Frais' ? 2 : 3}>
      {operation && amount ? (
        <span className={styles.figure}>
          <span>{name}</span> {formatAmount(amount.value, amount.assetCode, locale)}
        </span>
      ) : operation ? null : (
        <SkeletonBlock />
      )}
    </DatedCell>
  );
  const gross = operation?.grossAmount ?? null;

  return (
    <DatedRow
      dataAttributes={{ 'data-state': operation?.state }}
      menu={<OperationRowMenu actions={actions} operation={operation} />}
      menuOnPhone
      onOpen={operation ? () => actions.onDetail(operation) : undefined}
      state={
        active
          ? undefined
          : {
              tone: 'muted',
              cue: (
                <StatusBadge tone="info">
                  {operation?.voidKind === 'REPLACED' ? 'Remplacée' : 'Annulée'}
                </StatusBadge>
              ),
            }
      }
      title={
        <button
          className={styles.open}
          disabled={operation === null}
          onClick={() => operation && actions.onDetail(operation)}
          title={operation ? operationTitle(operation) : undefined}
          type="button"
        >
          {operation ? operationTitle(operation) : <SkeletonBlock />}
        </button>
      }
      meta={
        <small className={styles.meta}>
          {operation ? (
            <>
              <span>
                {[operationTypeLabel(operation.type), detail].filter(Boolean).join(' · ')}
              </span>
              {fees || taxes ? (
                <span className={styles.marks}>
                  {fees ? (
                    <span aria-label="Frais" className={styles.mark} role="img" title="Frais">
                      <Icon name="sliders" size={12} />
                    </span>
                  ) : null}
                  {taxes ? (
                    <span aria-label="Taxes" className={styles.mark} role="img" title="Taxes">
                      <Icon name="tax" size={12} />
                    </span>
                  ) : null}
                </span>
              ) : null}
            </>
          ) : (
            <SkeletonBlock />
          )}
        </small>
      }
      cells={
        <>
          <DatedCell column={1}>
            {operation && gross ? (
              <span className={styles.figure}>
                <span>Brut</span> {formatAmount(gross.value, gross.assetCode, locale)}
              </span>
            ) : operation ? null : (
              <SkeletonBlock />
            )}
          </DatedCell>
          {figure('Frais', fees)}
          {figure('Taxes', taxes)}
        </>
      }
      value={operation ? <CashAmount amount={operation.cashAmount} /> : <SkeletonBlock />}
    />
  );
}
