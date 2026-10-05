import type {
  PortfolioOperation,
  PortfolioOperationRequest,
  PortfolioOperationType,
} from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import {
  operationFieldErrors,
  operationSaveMessage,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import {
  operationTypeLabel,
  operationTypes,
} from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { LinkedTransferField } from './LinkedTransferField';
import { OperationDecimalField } from './OperationDecimalField';
import { OperationField } from './OperationField';
import { OperationInstrumentField } from './operation-instrument-field/OperationInstrumentField';
import { OperationPreviewLine } from './OperationPreviewLine';
import { OperationRatioFields } from './operation-ratio-fields/OperationRatioFields';
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
  const [values, setValues] = useState<OperationFormValues>(() =>
    operation
      ? valuesFromOperation(operation)
      : { ...emptyValues(today), type: account.kind === 'INSURANCE_CONTRACT' ? 'BUY' : 'DEPOSIT' },
  );
  const [submitted, setSubmitted] = useState(false);
  const built = buildOperationRequest(values, account.assetCode);
  const preview = useOperationPreview(account.id, built.request);
  const errors: OperationFieldErrors = {
    ...(submitted ? built.errors : null),
    ...operationFieldErrors(preview.error),
    ...fieldErrors,
  };
  const type = values.type;
  const saveMessage = operationSaveMessage(saveError, [
    values.instrument,
    values.counterInstrument,
    operation?.instrument ?? null,
    operation?.counterInstrument ?? null,
  ]);
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
    <form className={styles.form} noValidate onSubmit={submit}>
      {saveMessage ? (
        <p className={styles.alert} role="alert">
          {saveMessage}
        </p>
      ) : null}
      <div className={styles.fields}>
        <OperationField label="Type">
          <select
            data-autofocus
            onChange={(event) => {
              set('type', event.target.value as PortfolioOperationType);
              set('linkedTransactionId', '');
            }}
            value={type}
          >
            {operationTypes
              .filter(
                (value) =>
                  account.kind !== 'INSURANCE_CONTRACT' ||
                  ![
                    'DEPOSIT',
                    'WITHDRAWAL',
                    'SECURITY_TRANSFER_IN',
                    'SECURITY_TRANSFER_OUT',
                  ].includes(value),
              )
              .map((value) => (
                <option key={value} value={value}>
                  {operationTypeLabel(value)}
                </option>
              ))}
          </select>
        </OperationField>
        <OperationField error={errors.tradeDate} label="Date d’opération">
          <input
            onChange={(event) => set('tradeDate', event.target.value)}
            type="date"
            value={values.tradeDate}
          />
        </OperationField>
        <OperationField error={errors.settlementDate} label="Date de règlement">
          <input
            onChange={(event) => set('settlementDate', event.target.value)}
            type="date"
            value={values.settlementDate}
          />
        </OperationField>
        {has('instrument') ? (
          <OperationInstrumentField
            currencyCode={account.assetCode}
            error={errors.instrument}
            label="Instrument"
            onChange={(item) => set('instrument', item)}
            value={values.instrument}
          />
        ) : null}
        {decimal('quantity', 'Quantité')}
        {decimal('unitPrice', 'Prix unitaire')}
        {decimal('grossAmount', 'Montant brut')}
        {decimal('fees', 'Frais')}
        {decimal('taxes', 'Taxes')}
        {has('costBasis') ? (
          <OperationDecimalField
            error={errors.costBasis}
            hint="Laissez vide si le coût d’origine est inconnu."
            label="Coût de revient"
            onChange={(value) => set('costBasis', value)}
            value={values.costBasis}
          />
        ) : null}
        {has('counterInstrument') ? (
          <OperationInstrumentField
            currencyCode={account.assetCode}
            error={errors.counterInstrument}
            label="Instrument reçu"
            onChange={(item) => set('counterInstrument', item)}
            value={values.counterInstrument}
          />
        ) : null}
        {has('counterQuantity') ? (
          <OperationDecimalField
            error={errors.counterQuantity}
            label="Quantité reçue"
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
            hint="Part du coût de revient allouée aux titres reçus, entre 0 et 1."
            label="Part du coût allouée"
            onChange={(value) => set('costAllocationRatio', value)}
            value={values.costAllocationRatio}
          />
        ) : null}
        {has('linkedTransaction') ? (
          <LinkedTransferField
            accountId={account.id}
            direction={type === 'DEPOSIT' ? 'IN' : 'OUT'}
            error={errors.linkedTransaction}
            onChange={(candidate, id) => {
              set('linkedTransactionId', id);
              if (candidate) {
                set('tradeDate', candidate.bookedOn);
                set('grossAmount', candidate.amount.value);
              }
            }}
            value={values.linkedTransactionId}
          />
        ) : null}
        <OperationField error={errors.reference} label="Référence">
          <input
            maxLength={64}
            onChange={(event) => set('reference', event.target.value)}
            value={values.reference}
          />
        </OperationField>
        <OperationField error={errors.note} label="Note">
          <input
            maxLength={200}
            onChange={(event) => set('note', event.target.value)}
            value={values.note}
          />
        </OperationField>
        {operation ? (
          <OperationField error={errors.reason} label="Motif (facultatif)" wide>
            <input
              maxLength={200}
              onChange={(event) => set('reason', event.target.value)}
              value={values.reason}
            />
          </OperationField>
        ) : null}
      </div>
      <OperationPreviewLine
        pending={preview.isFetching}
        preview={built.request ? preview.data : undefined}
        showResidual={type === 'BUY' || type === 'SELL'}
      />
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  );
}
