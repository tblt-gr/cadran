import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';

interface AccountDescriptiveFieldsProps {
  identifierInvalid: boolean;
  institution: string;
  institutionInvalid: boolean;
  maskedIdentifier: string;
  onInstitutionChange: (value: string) => void;
  onMaskedIdentifierChange: (value: string) => void;
}

export function AccountDescriptiveFields({
  identifierInvalid,
  institution,
  institutionInvalid,
  maskedIdentifier,
  onInstitutionChange,
  onMaskedIdentifierChange,
}: AccountDescriptiveFieldsProps) {
  const { t } = useTranslation();
  return (
    <>
      <FormField
        error={institutionInvalid ? t('accounts.validation.institution') : undefined}
        hint={institutionInvalid ? undefined : t('accounts.form.institutionHint')}
        label={t('accounts.fields.institution')}
        name="institution"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={institutionInvalid ? true : undefined}
            autoComplete="off"
            id={fieldId}
            maxLength={80}
            onChange={(event) => onInstitutionChange(event.target.value)}
            value={institution}
          />
        )}
      </FormField>
      <FormField
        error={identifierInvalid ? t('accounts.validation.maskedIdentifier') : undefined}
        hint={t('accounts.form.maskedIdentifierHint')}
        label={t('accounts.fields.maskedIdentifier')}
        name="masked-identifier"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={identifierInvalid ? true : undefined}
            autoComplete="off"
            id={fieldId}
            maxLength={8}
            onChange={(event) => onMaskedIdentifierChange(event.target.value)}
            placeholder="4821"
            value={maskedIdentifier}
          />
        )}
      </FormField>
    </>
  );
}
