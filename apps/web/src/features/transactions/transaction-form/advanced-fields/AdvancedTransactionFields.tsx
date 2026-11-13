import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import type {
  TransactionFormErrors,
  TransactionFormValues,
} from '@/features/transactions/transaction-form/transactionFormValues';
import { LABELLED_FIELDS, type LabelledField } from './advancedFields';
import styles from './AdvancedTransactionFields.module.css';

interface AdvancedTransactionFieldsProps {
  errors: TransactionFormErrors;
  onChange: <K extends LabelledField>(field: K, value: TransactionFormValues[K]) => void;
  onToggle: (open: boolean) => void;
  open: boolean;
  /** Offers the rejection of a pending transaction, which only an edit can do. */
  rejectable: boolean;
  showErrors: boolean;
  /** Why the state cannot change any more, or `null` while it can. */
  stateLockedHint: string | null;
  /** The date is later this month: the row can only be pending, the hint sits under the date. */
  statePlanned?: boolean;
  values: Pick<TransactionFormValues, LabelledField | 'splitMode'>;
}

/** Whether a field holds something other than what a new manual entry starts with. */
function filled(field: LabelledField, values: Pick<TransactionFormValues, LabelledField>) {
  return field === 'state' ? values.state !== 'BOOKED' : values[field] !== '';
}

/**
 * Counterparty, state, secondary dates and note of a transaction. They stay
 * folded by default; the summary names those holding a value so nothing is hidden
 * without a trace, and the form opens the section when one of them is invalid.
 */
export function AdvancedTransactionFields({
  errors,
  onChange,
  onToggle,
  open,
  rejectable,
  showErrors,
  stateLockedHint,
  statePlanned = false,
  values,
}: AdvancedTransactionFieldsProps) {
  const { t } = useTranslation();
  const filledNames = [
    ...LABELLED_FIELDS.filter((field) => filled(field, values)).map((field) =>
      t(`transactions.fields.${field}`),
    ),
  ];

  return (
    <FormSection
      meta={filledNames.length > 0 ? filledNames.join(', ') : undefined}
      onToggle={onToggle}
      open={open}
      title={t('transactions.form.advanced')}
    >
      <label>
        <span>{t('transactions.fields.counterparty')}</span>
        <input
          aria-invalid={showErrors && errors.counterparty ? true : undefined}
          onChange={(event) => onChange('counterparty', event.target.value)}
          value={values.counterparty}
        />
        {showErrors && errors.counterparty ? (
          <small>{t('transactions.validation.counterparty')}</small>
        ) : null}
      </label>

      <label>
        <span>{t('transactions.fields.state')}</span>
        <select
          aria-describedby={
            stateLockedHint
              ? 'transaction-state-hint'
              : statePlanned
                ? 'transaction-upcoming-hint'
                : undefined
          }
          aria-label={t('transactions.fields.state')}
          disabled={stateLockedHint !== null}
          onChange={(event) =>
            onChange('state', event.target.value as TransactionFormValues['state'])
          }
          value={values.state}
        >
          <option value="PENDING">{t('transactions.states.PENDING')}</option>
          <option disabled={statePlanned} value="BOOKED">
            {t('transactions.states.BOOKED')}
          </option>
          {rejectable ? (
            <option value="REJECTED">{t('transactions.states.REJECTED')}</option>
          ) : null}
        </select>
        {stateLockedHint ? (
          <small className={styles.hint} id="transaction-state-hint">
            {stateLockedHint}
          </small>
        ) : null}
      </label>

      <label>
        <span>{t('transactions.fields.valueOn')}</span>
        <input
          aria-invalid={showErrors && errors.valueOn ? true : undefined}
          onChange={(event) => onChange('valueOn', event.target.value)}
          type="date"
          value={values.valueOn}
        />
        {showErrors && errors.valueOn ? (
          <small>{t('transactions.validation.valueOn')}</small>
        ) : null}
      </label>

      <label>
        <span>{t('transactions.fields.authorizedOn')}</span>
        <input
          aria-invalid={showErrors && errors.authorizedOn ? true : undefined}
          onChange={(event) => onChange('authorizedOn', event.target.value)}
          type="date"
          value={values.authorizedOn}
        />
        {showErrors && errors.authorizedOn ? (
          <small>{t('transactions.validation.authorizedOn')}</small>
        ) : null}
      </label>

      <label data-span="full">
        <span>{t('transactions.fields.note')}</span>
        <input
          aria-invalid={showErrors && errors.note ? true : undefined}
          onChange={(event) => onChange('note', event.target.value)}
          value={values.note}
        />
        {showErrors && errors.note ? <small>{t('transactions.validation.note')}</small> : null}
      </label>
    </FormSection>
  );
}
