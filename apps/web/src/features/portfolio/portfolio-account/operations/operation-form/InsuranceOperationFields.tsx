import { OperationDecimalField } from './OperationDecimalField';
import { SupportLegsField } from './support-legs-field/SupportLegsField';
import { FullSurrenderPrefill } from './support-legs-field/FullSurrenderPrefill';
import type { OperationFieldErrors, OperationFormValues } from './operationRequest';
import styles from './OperationForm.module.css';

export function InsuranceOperationFields({
  account,
  editing,
  errors,
  onChange,
  values,
}: {
  account: { id: string; assetCode: string };
  editing: boolean;
  errors: OperationFieldErrors;
  onChange: <K extends keyof OperationFormValues>(key: K, value: OperationFormValues[K]) => void;
  values: OperationFormValues;
}) {
  const premium = values.type === 'PREMIUM';
  const decimal = (
    field: 'grossAmount' | 'fees' | 'withheldIncomeTax' | 'withheldSocialContributions',
    label: string,
  ) => (
    <OperationDecimalField
      label={label}
      error={errors[field]}
      value={values[field]}
      onChange={(value) => onChange(field, value)}
    />
  );
  return (
    <>
      {decimal('grossAmount', premium ? 'Montant versé' : 'Montant brut du rachat')}
      {decimal('fees', premium ? 'Frais sur versement' : 'Frais de rachat')}
      {!premium ? (
        <>
          {decimal('withheldIncomeTax', 'Prélèvement fiscal retenu')}
          {decimal('withheldSocialContributions', 'Prélèvements sociaux retenus')}
        </>
      ) : null}
      <SupportLegsField
        assetCode={account.assetCode}
        errors={errors}
        value={values.legs}
        onChange={(legs) => onChange('legs', legs)}
      />
      {values.type === 'FULL_SURRENDER' && !editing ? (
        <div className={styles.wide}>
          <FullSurrenderPrefill
            accountId={account.id}
            tradeDate={values.tradeDate}
            onPrefill={(legs) => onChange('legs', legs)}
          />
        </div>
      ) : null}
    </>
  );
}
