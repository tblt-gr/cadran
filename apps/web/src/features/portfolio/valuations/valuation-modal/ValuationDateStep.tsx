import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
  function submit(event: FormEvent) {
    event.preventDefault();
    onContinue();
  }

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      <FormGrid>
        <BusinessDateField
          hint={t('portfolio.valuations.dateStep.hint')}
          label={t('portfolio.valuations.dateStep.label')}
          onChange={onChange}
          resetLabel={t('transactions.list.today')}
          today={today}
          value={value}
        />
      </FormGrid>
      <ModalActions>
        <button className="primary-action" type="submit">
          {t('portfolio.valuations.dateStep.continue')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
