import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { PortfolioOperation, PortfolioOperationRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
  operationFieldErrors,
  operationSaveMessage,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import { OperationTypeSelect } from '@/features/portfolio/portfolio-account/operations/operation-type-select/OperationTypeSelect';
import { LinkedTransferField } from './LinkedTransferField';
import { OperationDecimalField } from './OperationDecimalField';
import { OperationField } from './OperationField';
import { OperationExtraFields } from './operation-extra-fields/OperationExtraFields';
import { OperationInstrumentField } from './operation-instrument-field/OperationInstrumentField';
import { OperationPreviewLine } from './OperationPreviewLine';
import { OperationRatioFields } from './operation-ratio-fields/OperationRatioFields';
import { InsuranceIncomeFields } from './insurance-income-fields/InsuranceIncomeFields';
import { InsuranceOperationFields } from './InsuranceOperationFields';
import { hasField } from './operationMatrix';
import {
  buildOperationRequest,
  emptyValues,
  valuesFromOperation,
  type OperationFieldErrors,
  type OperationFormValues,
} from './operationRequest';
import { useOperationPreview } from './useOperationPreview';
import styles from './OperationForm.module.css';

interface Props {
  account: { id: string; assetCode: string; kind?: 'PORTFOLIO' | 'INSURANCE_CONTRACT' | string };
  fieldErrors?: OperationFieldErrors;
  onSubmit: (request: PortfolioOperationRequest, reason: string | null) => void;
  operation?: PortfolioOperation;
  pending: boolean;
  saveError?: unknown;
  today: string;
}

export function OperationForm({
  account,
  fieldErrors = {},
  onSubmit,
  operation,
  pending,
  saveError,
  today,
}: Props) {
  const { t } = useTranslation();
  const [values, setValues] = useState<OperationFormValues>(() =>
    operation
      ? valuesFromOperation(operation)
      : {
          ...emptyValues(today),
          type: account.kind === 'INSURANCE_CONTRACT' ? 'PREMIUM' : 'DEPOSIT',
        },
  );
  const [submitted, setSubmitted] = useState(false);
  const built = buildOperationRequest(values, account.assetCode, t);
  const preview = useOperationPreview(account.id, built.request, operation?.id);
  const errors: OperationFieldErrors = {
    ...(submitted ? built.errors : null),
    ...operationFieldErrors(preview.error, t),
    ...fieldErrors,
  };
  if (values.type === 'MANAGEMENT_FEE' && errors.grossAmount) {
    errors.fees = errors.grossAmount;
    delete errors.grossAmount;
  }
  const type = values.type;
  const insuranceIncome = [
    'EURO_FUND_INTEREST',
    'PROFIT_SHARING',
    'MANAGEMENT_FEE',
    'SOCIAL_CONTRIBUTION',
  ].includes(type);
  const instruments = [
    ...values.legs.map((leg) => leg.instrument),
    values.instrument,
    values.counterInstrument,
    operation?.instrument ?? null,
    operation?.counterInstrument ?? null,
  ];
  const saveMessage =
    operationSaveMessage(saveError, t, instruments) ??
    operationSaveMessage(preview.error, t, instruments);
  const has = (field: Parameters<typeof hasField>[1]) => hasField(type, field);

  function set<K extends keyof OperationFormValues>(key: K, value: OperationFormValues[K]) {
    setValues((current) => ({ ...current, [key]: value }));
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setSubmitted(true);
    if (built.request) onSubmit(built.request, values.reason.trim() || null);
  }
  const decimal = (
    field: 'quantity' | 'unitPrice' | 'grossAmount' | 'fees' | 'taxes',
    label: string,
  ) =>
    has(field) ? (
      <OperationDecimalField
        error={errors[field]}
        label={label}
        onChange={(value) => set(field, value)}
        value={values[field]}
      />
    ) : null;

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {saveMessage ? (
        <p className={styles.alert} role="alert">
          {saveMessage}
        </p>
      ) : null}
      <FormGrid>
        <OperationTypeSelect
          accountKind={account.kind}
          value={type}
          onChange={(next) => {
            set('type', next);
            if (
              ['EURO_FUND_INTEREST', 'PROFIT_SHARING', 'SOCIAL_CONTRIBUTION'].includes(next) &&
              values.instrument?.instrumentType !== 'EURO_FUND'
            )
              set('instrument', null);
            set('linkedTransactionId', '');
          }}
        />
        <OperationField error={errors.tradeDate} label={t('portfolio.account.form.tradeDate')}>
          <input
            onChange={(event) => set('tradeDate', event.target.value)}
            type="date"
            value={values.tradeDate}
          />
        </OperationField>
        {has('linkedTransaction') ? (
          <LinkedTransferField
            accountId={account.id}
            direction={type === 'DEPOSIT' || type === 'PREMIUM' ? 'IN' : 'OUT'}
            error={errors.linkedTransaction}
            onChange={(candidate, id) => {
              set('linkedTransactionId', id);
              if (candidate) {
                set('tradeDate', candidate.bookedOn);
                if (type === 'DEPOSIT' || type === 'PREMIUM' || type === 'WITHDRAWAL')
                  set('grossAmount', candidate.amount.value);
              }
            }}
            value={values.linkedTransactionId}
          />
        ) : null}
        {!insuranceIncome && has('instrument') ? (
          <OperationInstrumentField
            currencyCode={account.assetCode}
            error={errors.instrument}
            label={t('portfolio.account.form.instrument')}
            onChange={(item) => set('instrument', item)}
            value={values.instrument}
          />
        ) : null}
        {!insuranceIncome && decimal('quantity', t('portfolio.account.form.quantity'))}
        {!insuranceIncome && decimal('unitPrice', t('portfolio.account.form.unitPrice'))}
        {insuranceIncome ? (
          <InsuranceIncomeFields account={account} errors={errors} values={values} onChange={set} />
        ) : has('legs') ? (
          <InsuranceOperationFields
            account={account}
            editing={!!operation}
            errors={errors}
            values={values}
            onChange={set}
          />
        ) : (
          <>
            {decimal('grossAmount', t('portfolio.account.form.grossAmount'))}
            {decimal('fees', t('portfolio.account.form.fees'))}
          </>
        )}
        {!insuranceIncome && decimal('taxes', t('portfolio.account.form.taxes'))}
        {has('costBasis') ? (
          <OperationDecimalField
            error={errors.costBasis}
            hint={t('portfolio.account.form.costBasisHint')}
            label={t('portfolio.account.form.costBasis')}
            onChange={(value) => set('costBasis', value)}
            value={values.costBasis}
          />
        ) : null}
        {has('counterInstrument') ? (
          <OperationInstrumentField
            currencyCode={account.assetCode}
            error={errors.counterInstrument}
            label={t('portfolio.account.form.counterInstrument')}
            onChange={(item) => set('counterInstrument', item)}
            value={values.counterInstrument}
          />
        ) : null}
        {has('counterQuantity') ? (
          <OperationDecimalField
            error={errors.counterQuantity}
            label={t('portfolio.account.form.counterQuantity')}
            onChange={(value) => set('counterQuantity', value)}
            value={values.counterQuantity}
          />
        ) : null}
        {has('ratio') ? (
          <OperationRatioFields
            denominator={values.ratioDenominator}
            error={errors.ratio}
            numerator={values.ratioNumerator}
            onDenominatorChange={(value) => set('ratioDenominator', value)}
            onNumeratorChange={(value) => set('ratioNumerator', value)}
          />
        ) : null}
        {has('costAllocationRatio') ? (
          <OperationDecimalField
            error={errors.costAllocationRatio}
            hint={t('portfolio.account.form.allocationHint')}
            label={t('portfolio.account.form.allocation')}
            onChange={(value) => set('costAllocationRatio', value)}
            value={values.costAllocationRatio}
          />
        ) : null}
      </FormGrid>
      <OperationExtraFields
        editing={Boolean(operation)}
        errors={errors}
        onChange={set}
        values={values}
      />
      <OperationPreviewLine
        type={type}
        pending={preview.isFetching}
        preview={built.request ? preview.data : undefined}
        showResidual={type === 'BUY' || type === 'SELL'}
      />
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? t('portfolio.account.form.saving') : t('portfolio.account.form.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
