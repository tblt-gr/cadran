import type { Ref } from 'react';
import { useTranslation } from 'react-i18next';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import { InsufficientBalanceMessage } from '@/features/transactions/insufficient-balance/InsufficientBalanceMessage';

interface BalanceFieldsProps {
  amount: string;
  amountInvalid: boolean;
  amountRef: Ref<HTMLInputElement>;
  asOf: string;
  asOfInvalid: boolean;
  className: string;
  /** The date is fixed while a listed snapshot is being corrected. */
  dateLocked: boolean;
  latestAllowed: string;
  minDay: string;
  onAmount: (value: string) => void;
  onAsOf: (value: string) => void;
  /** The server refused the balance: `day` is the first negative day, when it said so. */
  refusal: { day: string | null } | null;
  showErrors: boolean;
}

/** The date and amount of one declared balance, with the refusal of an overdrawn account on the amount. */
export function BalanceFields({
  amount,
  amountInvalid,
  amountRef,
  asOf,
  asOfInvalid,
  className,
  dateLocked,
  latestAllowed,
  minDay,
  onAmount,
  onAsOf,
  refusal,
  showErrors,
}: BalanceFieldsProps) {
  const { t } = useTranslation();

  return (
    <FormGrid className={className}>
      <FormField
        error={showErrors && asOfInvalid ? t('accounts.balances.validation.asOf') : undefined}
        hint={t('accounts.balances.asOfHint')}
        label={t('accounts.balances.fields.asOf')}
        name="balance-as-of"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={showErrors && asOfInvalid ? true : undefined}
            id={fieldId}
            max={latestAllowed}
            min={minDay}
            onChange={(event) => onAsOf(event.target.value)}
            readOnly={dateLocked}
            required
            type="date"
            value={asOf}
          />
        )}
      </FormField>

      <FormField
        error={
          refusal ? (
            <InsufficientBalanceMessage date={refusal.day} />
          ) : showErrors && amountInvalid ? (
            t('accounts.balances.validation.amount')
          ) : undefined
        }
        hint={t('accounts.balances.amountHint')}
        label={t('accounts.balances.fields.amount')}
        name="balance-amount"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={refusal || (showErrors && amountInvalid) ? true : undefined}
            autoComplete="off"
            id={fieldId}
            inputMode="decimal"
            onChange={(event) => onAmount(event.target.value)}
            ref={amountRef}
            required
            spellCheck={false}
            value={amount}
          />
        )}
      </FormField>
    </FormGrid>
  );
}
