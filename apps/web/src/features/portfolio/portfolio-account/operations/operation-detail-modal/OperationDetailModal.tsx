import type { PortfolioOperation } from '@cadran/api-client';
import type { ReactNode, RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import {
  hasField,
  type FieldKey,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationMatrix';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { useOperation } from '@/features/portfolio/portfolio-account/operations/useOperation';
import styles from './OperationDetailModal.module.css';

function Row({ children, label }: { children: ReactNode; label: string }) {
  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd>{children ?? <EmptyValue label="Non renseigné" />}</dd>
    </div>
  );
}

export function OperationDetailModal({
  onClose,
  onOpenOperation,
  operation,
  returnFocus,
}: {
  onClose: () => void;
  onOpenOperation: (id: string) => void;
  operation: PortfolioOperation;
  returnFocus?: RefObject<HTMLElement | null>;
}) {
  const { i18n } = useTranslation();
  const locale = i18n.language;
  const replaced = useOperation(operation.correctedByOperationId);
  const replaces = useOperation(operation.correctsOperationId);
  const has = (field: FieldKey) => hasField(operation.type, field);
  const decimal = (value: string | null) => (value === null ? null : formatDecimal(value, locale));
  const amount = (value: { value: string; assetCode: string } | null) =>
    value === null ? null : `${formatDecimal(value.value, locale)} ${value.assetCode}`;
  const instrumentText = (item: PortfolioOperation['instrument']) =>
    item
      ? [item.name, item.isin, item.ticker]
          .filter((part) => part !== null && part !== '')
          .join(' · ')
      : null;

  return (
    <Modal
      returnFocus={returnFocus}
      close={onClose}
      eyebrow="Portefeuille"
      title={`Opération : ${operationTypeLabel(operation.type)}`}
    >
      <dl className={styles.list}>
        <Row label="Statut">
          {operation.state === 'ACTIVE' ? (
            <StatusBadge tone="positive">Active</StatusBadge>
          ) : (
            <StatusBadge tone="info">
              {operation.voidKind === 'REPLACED' ? 'Remplacée' : 'Annulée'}
            </StatusBadge>
          )}
        </Row>
        <Row label="Date d’opération">{formatCalendarNumericDay(operation.tradeDate)}</Row>
        <Row label="Date de règlement">
          {operation.settlementDate && formatCalendarNumericDay(operation.settlementDate)}
        </Row>
        <Row label="Ordre dans la journée">{String(operation.sequence)}</Row>
        {has('instrument') ? (
          <Row label="Instrument">{instrumentText(operation.instrument)}</Row>
        ) : null}
        {has('counterInstrument') ? (
          <Row label="Instrument reçu">{instrumentText(operation.counterInstrument)}</Row>
        ) : null}
        {has('quantity') ? <Row label="Quantité">{decimal(operation.quantity)}</Row> : null}
        {has('unitPrice') ? <Row label="Prix unitaire">{decimal(operation.unitPrice)}</Row> : null}
        {has('grossAmount') ? (
          <Row label="Montant brut">{amount(operation.grossAmount)}</Row>
        ) : null}
        {has('fees') ? <Row label="Frais">{amount(operation.fees)}</Row> : null}
        {has('taxes') ? <Row label="Taxes">{amount(operation.taxes)}</Row> : null}
        {has('costBasis') ? <Row label="Coût de revient">{amount(operation.costBasis)}</Row> : null}
        {has('counterQuantity') ? (
          <Row label="Quantité reçue">{decimal(operation.counterQuantity)}</Row>
        ) : null}
        {has('ratio') ? (
          <Row label="Ratio">
            {operation.ratio &&
              `${decimal(operation.ratio.numerator)} pour ${decimal(operation.ratio.denominator)}`}
          </Row>
        ) : null}
        {has('costAllocationRatio') ? (
          <Row label="Part du coût allouée">{decimal(operation.costAllocationRatio)}</Row>
        ) : null}
        <Row label="Effet sur les espèces">
          <CashAmount amount={operation.cashAmount} />
        </Row>
        {operation.roundingResidual ? (
          <Row label="Écart d’arrondi">{amount(operation.roundingResidual)}</Row>
        ) : null}
        <Row label="Référence">{operation.reference}</Row>
        <Row label="Note">{operation.note}</Row>
        {operation.linkedTransactionId ? (
          <Row label="Virement lié">
            <a
              href={`/accounts/${operation.accountId}?month=${operation.tradeDate.slice(0, 7)}`}
              onClick={(event) =>
                handleClientNavigation(
                  event,
                  `/accounts/${operation.accountId}?month=${operation.tradeDate.slice(0, 7)}`,
                )
              }
            >
              Virement lié du {formatCalendarNumericDay(operation.tradeDate)}
            </a>
          </Row>
        ) : null}
        {operation.state === 'VOIDED' ? <Row label="Motif">{operation.voidReason}</Row> : null}
      </dl>
      <div className={styles.links}>
        {operation.correctsOperationId ? (
          <button
            className="secondary-action"
            onClick={() => onOpenOperation(operation.correctsOperationId!)}
            type="button"
          >
            {replaces.data
              ? `Remplace l’opération du ${formatCalendarNumericDay(replaces.data.tradeDate)}`
              : 'Remplace une opération'}
          </button>
        ) : null}
        {operation.correctedByOperationId ? (
          <button
            className="secondary-action"
            onClick={() => onOpenOperation(operation.correctedByOperationId!)}
            type="button"
          >
            {replaced.data
              ? `Remplacée par l’opération du ${formatCalendarNumericDay(replaced.data.tradeDate)}`
              : 'Remplacée par une opération'}
          </button>
        ) : null}
      </div>
    </Modal>
  );
}
