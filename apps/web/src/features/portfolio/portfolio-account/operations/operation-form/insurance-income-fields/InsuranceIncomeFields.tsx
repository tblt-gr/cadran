import { readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { OperationDecimalField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationDecimalField';
import { OperationField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationField';
import type {
  OperationFormValues,
  OperationFieldErrors,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';

export function InsuranceIncomeFields({
  account,
  errors,
  values,
  onChange,
}: {
  account: { assetCode: string };
  errors: OperationFieldErrors;
  values: OperationFormValues;
  onChange: <K extends keyof OperationFormValues>(key: K, value: OperationFormValues[K]) => void;
}) {
  const { t } = useTranslation();
  const fee = values.type === 'MANAGEMENT_FEE';
  const interest = values.type === 'EURO_FUND_INTEREST' || values.type === 'PROFIT_SHARING';
  const metadata = useQuery({
    queryKey: ['instrument', values.instrument?.id],
    enabled: !!values.instrument && !values.instrument.instrumentType,
    queryFn: async ({ signal }) => {
      const result = await readInstrument({
        ...authApiOptions(),
        path: { id: values.instrument!.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Support unavailable');
      return result.data;
    },
    retry: false,
  });
  useEffect(() => {
    if (metadata.data && values.instrument && !values.instrument.instrumentType)
      onChange('instrument', {
        ...values.instrument,
        instrumentType: metadata.data.instrumentType,
      });
  }, [metadata.data, values.instrument, onChange]);
  const decimal = (
    field: 'grossAmount' | 'taxes' | 'fees' | 'quantity' | 'unitPrice',
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
      <div>
        <InstrumentPicker
          allowCreate
          key={values.type}
          currencyCode={account.assetCode}
          instrumentType={fee ? undefined : 'EURO_FUND'}
          label={t('portfolio.account.form.income.support')}
          value={values.instrument}
          onChange={(instrument) => {
            onChange('instrument', instrument);
            onChange('quantity', '');
            onChange('unitPrice', '');
          }}
        />
        {fee && !values.instrument ? (
          <small>{t('portfolio.account.form.income.contractCash')}</small>
        ) : null}
        {errors.instrument ? <small role="alert">{errors.instrument}</small> : null}
        {metadata.isError ? (
          <p role="alert">
            {t('portfolio.account.form.income.supportUnavailable')}{' '}
            <button
              className="secondary-action"
              type="button"
              onClick={() => void metadata.refetch()}
            >
              {t('actions.retry')}
            </button>
          </p>
        ) : null}
      </div>
      {interest ? (
        <>
          {decimal('grossAmount', t('portfolio.account.form.income.grossCredited'))}
          {decimal('taxes', t('portfolio.account.form.insurance.withheldSocial'))}
          <OperationField
            label={t('portfolio.account.form.income.rateBasis')}
            error={errors.interestBasis}
          >
            <select
              value={values.interestBasis}
              onChange={(event) =>
                onChange(
                  'interestBasis',
                  event.target.value as OperationFormValues['interestBasis'],
                )
              }
            >
              <option value="">{t('portfolio.account.form.income.chooseBasis')}</option>
              <option value="NET_OF_MANAGEMENT_FEES">
                {t('portfolio.lifeInsurance.basis.NET_OF_MANAGEMENT_FEES')}
              </option>
              <option value="GROSS_OF_MANAGEMENT_FEES">
                {t('portfolio.lifeInsurance.basis.GROSS_OF_MANAGEMENT_FEES')}
              </option>
              <option value="UNKNOWN">{t('portfolio.lifeInsurance.basis.UNKNOWN')}</option>
            </select>
          </OperationField>
        </>
      ) : fee ? (
        <>
          {decimal('fees', t('portfolio.account.form.income.feeAmount'))}
          {values.instrument &&
          values.instrument.instrumentType &&
          values.instrument.instrumentType !== 'EURO_FUND' ? (
            <>
              {decimal('quantity', t('portfolio.account.form.income.unitsWithdrawn'))}
              {decimal('unitPrice', t('portfolio.account.form.income.unitValue'))}
            </>
          ) : null}
        </>
      ) : (
        decimal('taxes', t('portfolio.account.form.income.amount'))
      )}
      {!fee ? (
        <OperationField
          label={
            interest
              ? t('portfolio.account.form.income.rateYear')
              : t('portfolio.account.form.income.year')
          }
          error={errors.rateYear}
        >
          <input
            inputMode="numeric"
            maxLength={4}
            value={values.rateYear}
            onChange={(event) => onChange('rateYear', event.target.value)}
          />
        </OperationField>
      ) : null}
    </>
  );
}
