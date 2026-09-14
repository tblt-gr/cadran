import type {
  Account,
  CreateRecurrenceRequest,
  Recurrence,
  RecurrenceCandidate,
  RecurrenceIntervalKind,
  UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { isCanonicalDecimal, isCanonicalUnsignedDecimal } from '@/lib/decimal';
import styles from './RecurrenceForm.module.css';

interface FormValues {
  accountId: string;
  amountTolerance: string;
  counterparty: string;
  dayOfPeriod: string;
  expectedAmount: string;
  firstExpectedOn: string;
  intervalKind: RecurrenceIntervalKind;
  label: string;
}

interface RecurrenceFormProps {
  accounts: Account[];
  candidate?: RecurrenceCandidate;
  onSubmit: (body: CreateRecurrenceRequest | UpdateRecurrenceRequest) => void;
  pending: boolean;
  recurrence?: Recurrence;
  submitError: string | null;
}

const INTERVALS: RecurrenceIntervalKind[] = ['WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY'];

function initialValues(
  accounts: Account[],
  candidate?: RecurrenceCandidate,
  recurrence?: Recurrence,
): FormValues {
  if (recurrence) {
    return {
      accountId: recurrence.accountId,
      amountTolerance: recurrence.amountTolerance.value,
      counterparty: recurrence.counterparty ?? '',
      dayOfPeriod: String(recurrence.dayOfPeriod),
      expectedAmount: recurrence.expectedAmount.value,
      firstExpectedOn: recurrence.nextExpectedOn,
      intervalKind: recurrence.intervalKind,
      label: recurrence.label,
    };
  }

  return {
    accountId: candidate?.accountId ?? accounts[0]?.id ?? '',
    amountTolerance: candidate?.tolerance.value ?? '0',
    counterparty: candidate?.counterparty ?? '',
    dayOfPeriod: '',
    expectedAmount: candidate?.medianAmount.value ?? '',
    firstExpectedOn: candidate?.lastSeenOn ?? '',
    intervalKind: candidate?.intervalKind ?? 'MONTHLY',
    label: candidate?.counterparty ?? '',
  };
}

function validDay(value: string, interval: RecurrenceIntervalKind): boolean {
  if (!/^[0-9]+$/.test(value)) return false;

  const day = Number(value);
  return interval === 'WEEKLY' ? day >= 1 && day <= 7 : day >= 1 && day <= 31;
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
  const [values, setValues] = useState(() => initialValues(accounts, candidate, recurrence));
  const [showErrors, setShowErrors] = useState(false);
  const editing = recurrence !== undefined;
  const errors = {
    accountId: values.accountId === '',
    amountTolerance: !isCanonicalUnsignedDecimal(values.amountTolerance),
    dayOfPeriod: !validDay(values.dayOfPeriod, values.intervalKind),
    expectedAmount: !isCanonicalDecimal(values.expectedAmount) || values.expectedAmount === '0',
    firstExpectedOn: !/^\d{4}-\d{2}-\d{2}$/.test(values.firstExpectedOn),
    label: values.label.trim() === '' || values.label.trim().length > 80,
  };
  const invalid = Object.values(errors).some(Boolean);

  function set<K extends keyof FormValues>(field: K, value: FormValues[K]) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (invalid) {
      setShowErrors(true);
      return;
    }

    const shared = {
      amountTolerance: values.amountTolerance,
      counterparty: values.counterparty.trim() || null,
      dayOfPeriod: Number(values.dayOfPeriod),
      expectedAmount: values.expectedAmount,
      intervalKind: values.intervalKind,
      label: values.label.trim(),
    };
    onSubmit(
      editing
        ? { ...shared, version: recurrence.version }
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
          <span>{t('recurrences.fields.amount')}</span>
          <input
            aria-invalid={showErrors && errors.expectedAmount ? true : undefined}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => set('expectedAmount', event.target.value)}
            spellCheck={false}
            value={values.expectedAmount}
          />
          {showErrors && errors.expectedAmount ? (
            <small>{t('recurrences.validation.amount')}</small>
          ) : null}
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
        {!editing ? (
          <label>
            <span>{t('recurrences.fields.firstExpectedOn')}</span>
            <input
              aria-invalid={showErrors && errors.firstExpectedOn ? true : undefined}
              onChange={(event) => set('firstExpectedOn', event.target.value)}
              type="date"
              value={values.firstExpectedOn}
            />
            {showErrors && errors.firstExpectedOn ? (
              <small>{t('recurrences.validation.date')}</small>
            ) : null}
          </label>
        ) : null}
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'recurrences.form.saving' : 'recurrences.form.save')}
        </button>
      </div>
    </form>
  );
}
