import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { FormEvent } from 'react';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import styles from './ValuationForm.module.css';

/** Step 1: the valuation date, which loads the server-built draft. */
export function ValuationDateStep({
  onChange,
  onContinue,
  today,
  value,
}: {
  onChange: (value: string) => void;
  onContinue: () => void;
  today: string;
  value: string;
}) {
  function submit(event: FormEvent) {
    event.preventDefault();
    onContinue();
  }

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      <FormGrid>
        <BusinessDateField
          hint="Les positions et les espèces sont celles détenues à cette date."
          label="Date de valorisation"
          onChange={onChange}
          resetLabel="Aujourd’hui"
          today={today}
          value={value}
        />
      </FormGrid>
      <ModalActions>
        <button className="primary-action" type="submit">
          Continuer
        </button>
      </ModalActions>
    </ModalForm>
  );
}
