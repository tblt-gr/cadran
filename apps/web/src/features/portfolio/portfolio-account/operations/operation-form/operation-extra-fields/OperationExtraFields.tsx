import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import type {
  OperationFieldErrors,
  OperationFormValues,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';
import { OperationField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationField';

type ExtraKey = 'settlementDate' | 'reference' | 'note' | 'reason';

/**
 * The bookkeeping details an operation rarely needs, folded under the essentials. They open by
 * themselves when an edited operation already carries one or when one of them is refused.
 */
export function OperationExtraFields({
  editing = false,
  errors,
  onChange,
  values,
}: {
  editing?: boolean;
  errors: OperationFieldErrors;
  onChange: (key: ExtraKey, value: string) => void;
  values: Pick<OperationFormValues, ExtraKey>;
}) {
  const { t } = useTranslation();
  const filled = Boolean(values.settlementDate || values.reference || values.note || values.reason);
  const [open, setOpen] = useState(filled);
  const refused = Boolean(
    errors.settlementDate || errors.reference || errors.note || errors.reason,
  );

  return (
    <FormSection
      title={t('portfolio.account.form.extra.title')}
      open={open || refused}
      onToggle={setOpen}
    >
      {editing ? (
        <OperationField error={errors.reason} label={t('portfolio.account.form.extra.reason')} wide>
          <input
            maxLength={200}
            onChange={(event) => onChange('reason', event.target.value)}
            value={values.reason}
          />
        </OperationField>
      ) : null}
      <OperationField
        error={errors.settlementDate}
        label={t('portfolio.account.form.extra.settlementDate')}
      >
        <input
          onChange={(event) => onChange('settlementDate', event.target.value)}
          type="date"
          value={values.settlementDate}
        />
      </OperationField>
      <OperationField error={errors.reference} label={t('portfolio.account.form.extra.reference')}>
        <input
          maxLength={64}
          onChange={(event) => onChange('reference', event.target.value)}
          value={values.reference}
        />
      </OperationField>
      <OperationField error={errors.note} label={t('portfolio.account.form.extra.note')} wide>
        <input
          maxLength={200}
          onChange={(event) => onChange('note', event.target.value)}
          value={values.note}
        />
      </OperationField>
    </FormSection>
  );
}
