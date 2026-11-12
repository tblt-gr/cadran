import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import skeletonStyles from '@/components/ui/skeleton/Skeleton.module.css';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import styles from './RecordBalanceForm.module.css';

/** Mirrors RecordBalanceForm element for element in its initial recording state. */
export function RecordBalanceFormSkeleton() {
  const { t } = useTranslation();
  const field = (name: 'asOf' | 'amount' | 'comment') => (
    <FormField
      label={t(`accounts.balances.fields.${name}`)}
      hint={t(`accounts.balances.${name}Hint`)}
      name={`balance-${name === 'asOf' ? 'as-of' : name}`}
    >
      {() => <SkeletonBlock className={skeletonStyles.fieldControl} />}
    </FormField>
  );
  return (
    <div className={styles.wrapper} inert>
      <ModalForm className={styles.form} noValidate onSubmit={(event) => event.preventDefault()}>
        <p className={styles.hint}>
          {t('accounts.balances.formHint', { asset: '' })}
          <SkeletonBlock className={skeletonStyles.line} />
        </p>
        <FormGrid className={styles.fields}>
          {field('asOf')}
          {field('amount')}
        </FormGrid>
        <FormSection
          title={t('transactions.form.advanced')}
          open={false}
          onToggle={() => undefined}
        >
          {field('comment')}
        </FormSection>
        <ModalActions>
          <button className="primary-action" disabled type="submit">
            {t('accounts.balances.record')}
          </button>
        </ModalActions>
      </ModalForm>
      <Disclosure title={t('accounts.snapshots.title')} open={false} onToggle={() => undefined}>
        {null}
      </Disclosure>
    </div>
  );
}
