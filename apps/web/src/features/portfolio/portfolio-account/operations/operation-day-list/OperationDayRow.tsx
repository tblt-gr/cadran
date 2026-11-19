import type { PortfolioOperation } from '@cadran/api-client';
import type { TFunction } from 'i18next';
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
function operationTitle(t: TFunction, operation: PortfolioOperation) {
  if (operation.instrument) return operation.instrument.name;
  if (operation.linkedTransactionId) return t('portfolio.account.operations.transfer');
  return operationTypeLabel(t, operation.type);
}

function legSummary(t: TFunction, operation: PortfolioOperation) {
  if (operation.type === 'SWITCH') {
    const sold = operation.legs.filter((leg) => leg.direction === 'OUT').length;
    const bought = operation.legs.filter((leg) => leg.direction === 'IN').length;
    return t('portfolio.account.operations.switchSummary', { sold, bought });
  }
  return hasField(operation.type, 'legs')
    ? t('portfolio.account.form.legs.supportCount', { count: operation.legs.length })
    : null;
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
  const { i18n, t } = useTranslation();
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
  const detail = operation ? (legSummary(t, operation) ?? quantified) : null;
  const figure = (kind: 'fees' | 'taxes', amount: PortfolioOperation['fees']) => (
    <DatedCell column={kind === 'fees' ? 2 : 3}>
      {operation && amount ? (
        <span className={styles.figure}>
          <span>
            {kind === 'fees' ? t('portfolio.account.form.fees') : t('portfolio.account.form.taxes')}
          </span>{' '}
          {formatAmount(amount.value, amount.assetCode, locale)}
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
                  {operation?.voidKind === 'REPLACED'
                    ? t('portfolio.account.operations.replaced')
                    : t('portfolio.account.operations.voided')}
                </StatusBadge>
              ),
            }
      }
      title={
        <button
          className={styles.open}
          disabled={operation === null}
          onClick={() => operation && actions.onDetail(operation)}
          title={operation ? operationTitle(t, operation) : undefined}
          type="button"
        >
          {operation ? operationTitle(t, operation) : <SkeletonBlock />}
        </button>
      }
      meta={
        <small className={styles.meta}>
          {operation ? (
            <>
              <span>
                {[operationTypeLabel(t, operation.type), detail].filter(Boolean).join(' · ')}
              </span>
              {fees || taxes ? (
                <span className={styles.marks}>
                  {fees ? (
                    <span
                      aria-label={t('portfolio.account.form.fees')}
                      className={styles.mark}
                      role="img"
                      title={t('portfolio.account.form.fees')}
                    >
                      <Icon name="sliders" size={12} />
                    </span>
                  ) : null}
                  {taxes ? (
                    <span
                      aria-label={t('portfolio.account.form.taxes')}
                      className={styles.mark}
                      role="img"
                      title={t('portfolio.account.form.taxes')}
                    >
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
                <span>{t('portfolio.account.operations.gross')}</span>{' '}
                {formatAmount(gross.value, gross.assetCode, locale)}
              </span>
            ) : operation ? null : (
              <SkeletonBlock />
            )}
          </DatedCell>
          {figure('fees', fees)}
          {figure('taxes', taxes)}
        </>
      }
      value={operation ? <CashAmount amount={operation.cashAmount} /> : <SkeletonBlock />}
    />
  );
}
