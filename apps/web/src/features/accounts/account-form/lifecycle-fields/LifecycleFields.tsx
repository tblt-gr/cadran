import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';

interface LifecycleFieldsProps {
  closedOn: string;
  closedOnInvalid: boolean;
  onClosedOnChange: (value: string) => void;
  onOpenedOnChange: (value: string) => void;
  openedOn: string;
  openedOnInvalid: boolean;
  today: string;
}

/**
 * The two dates that describe the life of an account. Closing is expressed here
 * rather than through a separate action: a closing date is a fact about the
 * account, and clearing it reopens the account the same way.
 */
export function LifecycleFields({
  closedOn,
  closedOnInvalid,
  onClosedOnChange,
  onOpenedOnChange,
  openedOn,
  openedOnInvalid,
  today,
}: LifecycleFieldsProps) {
  const { t } = useTranslation();

  return (
    <>
      <FormField
        error={openedOnInvalid ? t('accounts.validation.openedOn') : undefined}
        label={t('accounts.fields.openedOn')}
        name="opened-on"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={openedOnInvalid ? true : undefined}
            id={fieldId}
            max={today}
            onChange={(event) => onOpenedOnChange(event.target.value)}
            required
            type="date"
            value={openedOn}
          />
        )}
      </FormField>

      <FormField
        error={closedOnInvalid ? t('accounts.validation.closedOn') : undefined}
        hint={t('accounts.form.closedOnHint')}
        label={t('accounts.fields.closedOn')}
        name="closed-on"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={closedOnInvalid ? true : undefined}
            id={fieldId}
            max={today}
            min={openedOn === '' ? undefined : openedOn}
            onChange={(event) => onClosedOnChange(event.target.value)}
            type="date"
            value={closedOn}
          />
        )}
      </FormField>
    </>
  );
}
