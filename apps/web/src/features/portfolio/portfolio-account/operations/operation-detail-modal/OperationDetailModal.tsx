import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { InsuranceIncomeDetail } from './insurance-income-detail/InsuranceIncomeDetail';
import { SwitchOperationDetail } from './switch-operation-detail/SwitchOperationDetail';
import type { PortfolioOperation } from '@cadran/api-client';
import type { ReactNode, RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { portfolioAccountHref } from '@/features/portfolio/portfolioAccountHref';
import { formatCalendarNumericDay, formatFinancialDecimal } from '@/lib/decimal';
import { CashAmount } from '@/features/portfolio/portfolio-account/operations/cash-amount/CashAmount';
import {
  hasField,
  type FieldKey,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationMatrix';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { useOperation } from '@/features/portfolio/portfolio-account/operations/useOperation';
import { OperationLegsTable } from './OperationLegsTable';
import styles from './OperationDetailModal.module.css';
import { formatExactAmount } from '@/lib/decimal';

function Row({ children, label }: { children: ReactNode; label: string }) {
  const { t } = useTranslation();
  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd>
        {typeof children === 'string' ? (
          <MoneyValue wrapGroups={false} value={children} />
        ) : (
          (children ?? <EmptyValue label={t('portfolio.lifeInsurance.notProvided')} />)
        )}
      </dd>
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
  const { i18n, t } = useTranslation();
  const locale = i18n.language;
  const replaced = useOperation(operation.correctedByOperationId);
  const replaces = useOperation(operation.correctsOperationId);
  const has = (field: FieldKey) => hasField(operation.type, field);
  const transferHref = portfolioAccountHref(operation.accountId, operation.tradeDate.slice(0, 7));
  const decimal = (value: string | null) =>
    value === null ? null : formatFinancialDecimal(value, locale);
  const amount = (value: { value: string; assetCode: string } | null) =>
    value === null ? null : formatExactAmount(value.value, value.assetCode, locale);
  const instrumentText = (item: PortfolioOperation['instrument']) =>
    item ? (
      <>
        <InstrumentLink instrument={item} />
        {[item.isin, item.ticker]
          .filter(Boolean)
          .map((part) => ` · ${part}`)
          .join('')}
      </>
    ) : null;

  return (
    <Modal
      intent="detail"
      returnFocus={returnFocus}
      close={onClose}
      title={t('portfolio.account.detail.title', { type: operationTypeLabel(t, operation.type) })}
    >
      <dl className={styles.list}>
        <Row label={t('portfolio.account.detail.status')}>
          {operation.state === 'ACTIVE' ? (
            <StatusBadge tone="positive">{t('portfolio.account.detail.active')}</StatusBadge>
          ) : (
            <StatusBadge tone="info">
              {operation.voidKind === 'REPLACED'
                ? t('portfolio.account.operations.replaced')
                : t('portfolio.account.operations.voided')}
            </StatusBadge>
          )}
        </Row>
        <Row label={t('portfolio.account.form.tradeDate')}>
          {formatCalendarNumericDay(operation.tradeDate)}
        </Row>
        <Row label={t('portfolio.account.form.extra.settlementDate')}>
          {operation.settlementDate && formatCalendarNumericDay(operation.settlementDate)}
        </Row>
        <Row label={t('portfolio.account.detail.sequence')}>{String(operation.sequence)}</Row>
        {has('instrument') ? (
          <Row label={t('portfolio.account.form.instrument')}>
            {instrumentText(operation.instrument)}
          </Row>
        ) : null}
        {has('counterInstrument') ? (
          <Row label={t('portfolio.account.form.counterInstrument')}>
            {instrumentText(operation.counterInstrument)}
          </Row>
        ) : null}
        {has('quantity') ? (
          <Row label={t('portfolio.account.form.quantity')}>{decimal(operation.quantity)}</Row>
        ) : null}
        {has('unitPrice') ? (
          <Row label={t('portfolio.account.form.unitPrice')}>{decimal(operation.unitPrice)}</Row>
        ) : null}
        {has('grossAmount') ? (
          <Row label={t('portfolio.account.form.grossAmount')}>{amount(operation.grossAmount)}</Row>
        ) : null}
        {has('fees') && operation.type !== 'SWITCH' ? (
          <Row label={t('portfolio.account.form.fees')}>{amount(operation.fees)}</Row>
        ) : null}
        {has('withheldIncomeTax') ? (
          <Row label={t('portfolio.account.form.insurance.withheldIncomeTax')}>
            {amount(operation.withheldIncomeTax)}
          </Row>
        ) : null}
        {has('withheldSocialContributions') ? (
          <Row label={t('portfolio.account.form.insurance.withheldSocial')}>
            {amount(operation.withheldSocialContributions)}
          </Row>
        ) : null}
        {has('withheldIncomeTax') ? (
          <Row label={t('portfolio.account.form.preview.netPaid')}>
            {amount(operation.netPaidAmount)}
          </Row>
        ) : null}
        {has('taxes') ? (
          <Row label={t('portfolio.account.form.taxes')}>{amount(operation.taxes)}</Row>
        ) : null}
        {has('costBasis') ? (
          <Row label={t('portfolio.account.form.costBasis')}>{amount(operation.costBasis)}</Row>
        ) : null}
        {has('counterQuantity') ? (
          <Row label={t('portfolio.account.form.counterQuantity')}>
            {decimal(operation.counterQuantity)}
          </Row>
        ) : null}
        {has('ratio') ? (
          <Row label={t('portfolio.account.detail.ratio')}>
            {operation.ratio &&
              t('portfolio.account.detail.ratioValue', {
                numerator: decimal(operation.ratio.numerator),
                denominator: decimal(operation.ratio.denominator),
              })}
          </Row>
        ) : null}
        {has('costAllocationRatio') ? (
          <Row label={t('portfolio.account.form.allocation')}>
            {decimal(operation.costAllocationRatio)}
          </Row>
        ) : null}
        <InsuranceIncomeDetail operation={operation} />
        <Row label={t('portfolio.account.form.preview.cashEffect')}>
          <CashAmount amount={operation.cashAmount} />
        </Row>
        {operation.roundingResidual ? (
          <Row label={t('portfolio.account.form.preview.rounding')}>
            {amount(operation.roundingResidual)}
          </Row>
        ) : null}
        <Row label={t('portfolio.account.form.extra.reference')}>{operation.reference}</Row>
        <Row label={t('portfolio.account.form.extra.note')}>{operation.note}</Row>
        {operation.linkedTransactionId ? (
          <Row label={t('portfolio.account.form.linkedTransfer')}>
            <a href={transferHref} onClick={(event) => handleClientNavigation(event, transferHref)}>
              {t('portfolio.account.detail.linkedTransferOf', {
                date: formatCalendarNumericDay(operation.tradeDate),
              })}
            </a>
          </Row>
        ) : null}
        {operation.state === 'VOIDED' ? (
          <Row label={t('portfolio.account.detail.reason')}>{operation.voidReason}</Row>
        ) : null}
      </dl>
      {operation.type === 'SWITCH' ? (
        <SwitchOperationDetail operation={operation} />
      ) : has('legs') ? (
        <OperationLegsTable legs={operation.legs ?? []} />
      ) : null}
      <div className={styles.links}>
        {operation.correctsOperationId ? (
          <button
            className="secondary-action"
            onClick={() => onOpenOperation(operation.correctsOperationId!)}
            type="button"
          >
            {replaces.data
              ? t('portfolio.account.detail.replacesOn', {
                  date: formatCalendarNumericDay(replaces.data.tradeDate),
                })
              : t('portfolio.account.detail.replaces')}
          </button>
        ) : null}
        {operation.correctedByOperationId ? (
          <button
            className="secondary-action"
            onClick={() => onOpenOperation(operation.correctedByOperationId!)}
            type="button"
          >
            {replaced.data
              ? t('portfolio.account.detail.replacedByOn', {
                  date: formatCalendarNumericDay(replaced.data.tradeDate),
                })
              : t('portfolio.account.detail.replacedBy')}
          </button>
        ) : null}
      </div>
    </Modal>
  );
}
