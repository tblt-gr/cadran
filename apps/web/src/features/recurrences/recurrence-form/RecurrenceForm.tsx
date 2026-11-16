import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import type {
  Account,
  CreateRecurrenceRequest,
  Recurrence,
  RecurrenceCandidate,
  UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRecurrenceCategory } from '@/features/recurrences/useRecurrenceCategory';
import {
  hasRecordedAmount,
  SignedAmountHint,
} from '@/components/ui/signed-amount-hint/SignedAmountHint';
import { isCanonicalDecimal, isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';
import { toSignedAmount } from '@/lib/signedAmount';
import {
  categoryTypeFor,
  initialValues,
  validDay,
  withDirection,
  type FormValues,
} from './recurrenceFormValues';
import { RecurrenceAdvancedFields } from './RecurrenceAdvancedFields';
import { recurrenceRequest } from './recurrenceRequest';
import { RecurrenceScheduleFields } from './RecurrenceScheduleFields';
import { RecurrenceTransactionFields } from './RecurrenceTransactionFields';
import styles from './RecurrenceForm.module.css';

interface RecurrenceFormProps {
  accounts: Account[];
  candidate?: RecurrenceCandidate;
  onSubmit: (body: CreateRecurrenceRequest | UpdateRecurrenceRequest) => void;
  pending: boolean;
  recurrence?: Recurrence;
  submitError: string | null;
}

/** Pure modal child: the schedule fields never create their own surface or focus trap. */
export function RecurrenceForm({
  accounts,
  candidate,
  onSubmit,
  pending,
  recurrence,
  submitError,
}: RecurrenceFormProps) {
  const { t } = useTranslation();
  const amountHintId = useId();
  const startHintId = useId();
  const [values, setValues] = useState(() => initialValues(accounts, candidate, recurrence));
  const [showErrors, setShowErrors] = useState(false);
  const editing = recurrence !== undefined;
  const direction = values.direction === '' ? null : values.direction;
  const expectedAmount = toSignedAmount(values.expectedAmount, direction);
  const baseErrors = {
    accountId: values.accountId === '',
    amountTolerance: !isCanonicalUnsignedDecimal(values.amountTolerance),
    dayOfPeriod: !validDay(values.dayOfPeriod, values.intervalKind),
    expectedAmount: !isCanonicalDecimal(expectedAmount) || isZeroDecimal(expectedAmount),
    firstExpectedOn: !/^\d{4}-\d{2}-\d{2}$/.test(values.firstExpectedOn),
    label: values.label.trim() === '' || values.label.trim().length > 80,
  };
  // The sign typed in the amount decides when no direction is chosen, like the server does.
  const effectiveDirection =
    direction ??
    (baseErrors.expectedAmount ? '' : expectedAmount.startsWith('-') ? 'OUTFLOW' : 'INFLOW');
  const expectedCategoryType = categoryTypeFor(effectiveDirection);
  const saved = useRecurrenceCategory(recurrence?.categoryId ?? null);
  const categoryType = values.categoryType ?? (values.categoryId === saved?.id ? saved.type : null);
  const errors = {
    ...baseErrors,
    categoryId:
      values.categoryId !== '' &&
      categoryType !== null &&
      expectedCategoryType !== undefined &&
      categoryType !== expectedCategoryType,
  };
  const invalid = Object.values(errors).some(Boolean);

  function set<K extends keyof FormValues>(field: K, value: FormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function changeDirection(next: FormValues['direction']) {
    setValues((current) => withDirection(current, next, categoryType));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (invalid) {
      setShowErrors(true);
      return;
    }

    onSubmit(recurrenceRequest(values, expectedAmount, recurrence));
  }

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      <p className={styles.forecast}>{t('recurrences.form.forecast')}</p>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {submitError}
        </p>
      ) : null}
      <FormGrid>
        <label>
          <span>{t('recurrences.fields.account')}</span>
          <select
            aria-invalid={showErrors && errors.accountId ? true : undefined}
            disabled={editing}
            onChange={(event) => set('accountId', event.target.value)}
            value={values.accountId}
          >
            <option value="">{t('recurrences.form.selectAccount')}</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.label}
              </option>
            ))}
          </select>
          {showErrors && errors.accountId ? (
            <small>{t('recurrences.validation.account')}</small>
          ) : null}
        </label>

        <div className={styles.field}>
          <label>
            <span>{t('recurrences.fields.firstExpectedOn')}</span>
            <input
              aria-describedby={editing ? startHintId : undefined}
              aria-invalid={showErrors && errors.firstExpectedOn ? true : undefined}
              onChange={(event) => set('firstExpectedOn', event.target.value)}
              type="date"
              value={values.firstExpectedOn}
            />
            {showErrors && errors.firstExpectedOn ? (
              <small>{t('recurrences.validation.date')}</small>
            ) : null}
          </label>
          {editing ? (
            <small className={styles.hint} id={startHintId}>
              {t('recurrences.form.firstExpectedOnEditHint')}
            </small>
          ) : null}
        </div>
        <label>
          <span>{t('recurrences.fields.label')}</span>
          <input
            aria-invalid={showErrors && errors.label ? true : undefined}
            data-autofocus
            onChange={(event) => set('label', event.target.value)}
            value={values.label}
          />
          {showErrors && errors.label ? <small>{t('recurrences.validation.label')}</small> : null}
        </label>
        <label>
          <span>{t('recurrences.fields.amount')}</span>
          <input
            aria-describedby={hasRecordedAmount(values.expectedAmount) ? amountHintId : undefined}
            aria-invalid={showErrors && errors.expectedAmount ? true : undefined}
            aria-label={t('recurrences.fields.amount')}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => set('expectedAmount', event.target.value)}
            spellCheck={false}
            value={values.expectedAmount}
          />
          {showErrors && errors.expectedAmount ? (
            <small>{t('recurrences.validation.amount')}</small>
          ) : null}
          <SignedAmountHint
            assetCode={accounts.find((account) => account.id === values.accountId)?.assetCode ?? ''}
            direction={direction}
            id={amountHintId}
            recordedOnly
            typed={values.expectedAmount}
          />
        </label>

        <label>
          <span>{t('recurrences.fields.direction')}</span>
          <select
            onChange={(event) => changeDirection(event.target.value as FormValues['direction'])}
            value={values.direction}
          >
            <option value="OUTFLOW">{t('recurrences.directions.OUTFLOW')}</option>
            <option value="INFLOW">{t('recurrences.directions.INFLOW')}</option>
          </select>
        </label>
        <RecurrenceTransactionFields
          advancedFilled={values.counterparty !== '' || values.amountTolerance !== '0'}
          advancedInvalid={showErrors && errors.amountTolerance}
          autoCreate={values.autoCreate}
          categoryError={showErrors && errors.categoryId}
          categoryId={values.categoryId}
          categoryType={expectedCategoryType}
          note={values.note}
          onCategoryChange={(categoryId, category) =>
            setValues((current) => ({
              ...current,
              categoryId,
              categoryLabel: category?.label ?? null,
              categoryType: category?.type ?? null,
            }))
          }
          onChange={set}
          paymentMethod={values.paymentMethod}
          schedule={
            <RecurrenceScheduleFields
              dayInvalid={showErrors && errors.dayOfPeriod}
              dayOfPeriod={values.dayOfPeriod}
              intervalKind={values.intervalKind}
              onChange={set}
            />
          }
          selected={{
            color: saved?.effectiveColor,
            icon: saved?.effectiveIcon,
            label: values.categoryLabel ?? saved?.label,
          }}
        >
          <RecurrenceAdvancedFields
            amountTolerance={values.amountTolerance}
            counterparty={values.counterparty}
            onChange={set}
            toleranceInvalid={showErrors && errors.amountTolerance}
          />
        </RecurrenceTransactionFields>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'recurrences.form.saving' : 'recurrences.form.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
