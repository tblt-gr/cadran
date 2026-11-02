import type {
  Account,
  CreateRecurrenceRequest,
  Recurrence,
  RecurrenceCandidate,
  RecurrenceIntervalKind,
  UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRecurrenceCategory } from '@/features/recurrences/useRecurrenceCategory';
import { SignedAmountHint } from '@/components/ui/signed-amount-hint/SignedAmountHint';
import { isCanonicalDecimal, isCanonicalUnsignedDecimal, isZeroDecimal } from '@/lib/decimal';
import { toSignedAmount } from '@/lib/signedAmount';
import {
  categoryTypeFor,
  initialValues,
  INTERVALS,
  validDay,
  withDirection,
  type FormValues,
} from './recurrenceFormValues';
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
  const effectiveDirection = direction ?? (expectedAmount.startsWith('-') ? 'OUTFLOW' : 'INFLOW');
  const expectedCategoryType = categoryTypeFor(effectiveDirection);
  const saved = useRecurrenceCategory(recurrence?.categoryId ?? null);
  const categoryType = values.categoryType ?? (values.categoryId === saved?.id ? saved.type : null);
  const errors = {
    ...baseErrors,
    categoryId:
      values.categoryId !== '' && categoryType !== null && categoryType !== expectedCategoryType,
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

    const shared = {
      amountTolerance: values.amountTolerance,
      autoCreate: values.autoCreate,
      categoryId: values.categoryId === '' ? null : values.categoryId,
      counterparty: values.counterparty.trim() || null,
      dayOfPeriod: Number(values.dayOfPeriod),
      expectedAmount,
      intervalKind: values.intervalKind,
      label: values.label.trim(),
      note: values.note.trim() === '' ? null : values.note.trim(),
      paymentMethod: values.paymentMethod === '' ? null : values.paymentMethod,
    };
    onSubmit(
      editing
        ? { ...shared, firstExpectedOn: values.firstExpectedOn, version: recurrence.version }
        : { ...shared, accountId: values.accountId, firstExpectedOn: values.firstExpectedOn },
    );
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      <p className={styles.forecast}>{t('recurrences.form.forecast')}</p>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {submitError}
        </p>
      ) : null}
      <div className={styles.fields}>
        <label>
          <span>{t('recurrences.fields.label')}</span>
          <input
            aria-invalid={showErrors && errors.label ? true : undefined}
            autoFocus
            onChange={(event) => set('label', event.target.value)}
            value={values.label}
          />
          {showErrors && errors.label ? <small>{t('recurrences.validation.label')}</small> : null}
        </label>
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
        <label>
          <span>{t('recurrences.fields.counterparty')}</span>
          <input
            onChange={(event) => set('counterparty', event.target.value)}
            value={values.counterparty}
          />
        </label>
        <label>
          <span>{t('recurrences.fields.direction')}</span>
          <select
            onChange={(event) => changeDirection(event.target.value as FormValues['direction'])}
            value={values.direction}
          >
            <option value="">{t('recurrences.directions.unset')}</option>
            <option value="OUTFLOW">{t('recurrences.directions.OUTFLOW')}</option>
            <option value="INFLOW">{t('recurrences.directions.INFLOW')}</option>
          </select>
        </label>
        <label>
          <span>{t('recurrences.fields.amount')}</span>
          <input
            aria-describedby={amountHintId}
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
            typed={values.expectedAmount}
          />
        </label>
        <label>
          <span>{t('recurrences.fields.tolerance')}</span>
          <input
            aria-invalid={showErrors && errors.amountTolerance ? true : undefined}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => set('amountTolerance', event.target.value)}
            spellCheck={false}
            value={values.amountTolerance}
          />
          {showErrors && errors.amountTolerance ? (
            <small>{t('recurrences.validation.tolerance')}</small>
          ) : null}
        </label>
        <label>
          <span>{t('recurrences.fields.interval')}</span>
          <select
            onChange={(event) => set('intervalKind', event.target.value as RecurrenceIntervalKind)}
            value={values.intervalKind}
          >
            {INTERVALS.map((interval) => (
              <option key={interval} value={interval}>
                {t(`recurrences.intervals.${interval}`)}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span>{t('recurrences.fields.day')}</span>
          <input
            aria-invalid={showErrors && errors.dayOfPeriod ? true : undefined}
            inputMode="numeric"
            max={values.intervalKind === 'WEEKLY' ? 7 : 31}
            min={1}
            onChange={(event) => set('dayOfPeriod', event.target.value)}
            type="number"
            value={values.dayOfPeriod}
          />
          {showErrors && errors.dayOfPeriod ? (
            <small>{t('recurrences.validation.day')}</small>
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
        <RecurrenceTransactionFields
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
          selected={{
            color: saved?.effectiveColor,
            icon: saved?.effectiveIcon,
            label: values.categoryLabel ?? saved?.label,
          }}
        />
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'recurrences.form.saving' : 'recurrences.form.save')}
        </button>
      </div>
    </form>
  );
}
