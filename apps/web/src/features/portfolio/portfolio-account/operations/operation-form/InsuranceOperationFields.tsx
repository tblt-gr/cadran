import { useTranslation } from 'react-i18next';
import { SwitchOperationFields } from './switch-operation-fields/SwitchOperationFields';
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
  const { t } = useTranslation();
  const premium = values.type === 'PREMIUM';
  if (values.type === 'SWITCH')
    return (
      <SwitchOperationFields
        account={account}
        errors={errors}
        values={values}
        onChange={onChange}
      />
    );
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
      {decimal(
        'grossAmount',
        premium
          ? t('portfolio.account.form.insurance.premiumAmount')
          : t('portfolio.account.form.insurance.surrenderGross'),
      )}
      {decimal(
        'fees',
        premium
          ? t('portfolio.account.form.insurance.premiumFees')
          : t('portfolio.account.form.insurance.surrenderFees'),
      )}
      {!premium ? (
        <>
          {decimal('withheldIncomeTax', t('portfolio.account.form.insurance.withheldIncomeTax'))}
          {decimal(
            'withheldSocialContributions',
            t('portfolio.account.form.insurance.withheldSocial'),
          )}
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
