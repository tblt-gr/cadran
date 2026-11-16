import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';

interface BalanceCommentFieldProps {
  invalid: boolean;
  maxLength: number;
  value: string;
  onChange: (value: string) => void;
}

export function BalanceCommentField({
  invalid,
  maxLength,
  value,
  onChange,
}: BalanceCommentFieldProps) {
  const { t } = useTranslation();

  return (
    <FormField
      error={invalid ? t('accounts.balances.validation.comment') : undefined}
      hint={t('accounts.balances.commentHint')}
      label={t('accounts.balances.fields.comment')}
      name="balance-comment"
    >
      {({ fieldId, describedBy }) => (
        <input
          aria-describedby={describedBy}
          aria-invalid={invalid ? true : undefined}
          id={fieldId}
          maxLength={maxLength}
          onChange={(event) => onChange(event.target.value)}
          value={value}
        />
      )}
    </FormField>
  );
}
