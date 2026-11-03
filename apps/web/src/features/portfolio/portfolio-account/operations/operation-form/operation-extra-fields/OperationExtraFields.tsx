import { useState } from 'react';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import type {
  OperationFieldErrors,
  OperationFormValues,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';
import { OperationField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationField';

type ExtraKey = 'settlementDate' | 'reference' | 'note';

/**
 * The bookkeeping details an operation rarely needs, folded under the essentials. They open by
 * themselves when an edited operation already carries one or when one of them is refused.
 */
export function OperationExtraFields({
  errors,
  onChange,
  values,
}: {
  errors: OperationFieldErrors;
  onChange: (key: ExtraKey, value: string) => void;
  values: Pick<OperationFormValues, ExtraKey>;
}) {
  const filled = Boolean(values.settlementDate || values.reference || values.note);
  const [open, setOpen] = useState(filled);
  const refused = Boolean(errors.settlementDate || errors.reference || errors.note);

  return (
    <FormSection title="Champs avancés" open={open || refused} onToggle={setOpen}>
      <OperationField error={errors.settlementDate} label="Date de règlement">
        <input
          onChange={(event) => onChange('settlementDate', event.target.value)}
          type="date"
          value={values.settlementDate}
        />
      </OperationField>
      <OperationField error={errors.reference} label="Référence">
        <input
          maxLength={64}
          onChange={(event) => onChange('reference', event.target.value)}
          value={values.reference}
        />
      </OperationField>
      <OperationField error={errors.note} label="Note" wide>
        <input
          maxLength={200}
          onChange={(event) => onChange('note', event.target.value)}
          value={values.note}
        />
      </OperationField>
    </FormSection>
  );
}
