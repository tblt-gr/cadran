import { readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
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
          key={values.type}
          currencyCode={account.assetCode}
          instrumentType={fee ? undefined : 'EURO_FUND'}
          label="Support"
          value={values.instrument}
          onChange={(instrument) => {
            onChange('instrument', instrument);
            onChange('quantity', '');
            onChange('unitPrice', '');
          }}
        />
        {fee && !values.instrument ? <small>Liquidités du contrat</small> : null}
        {errors.instrument ? <small role="alert">{errors.instrument}</small> : null}
        {metadata.isError ? (
          <p role="alert">
            Le support est indisponible.{' '}
            <button
              className="secondary-action"
              type="button"
              onClick={() => void metadata.refetch()}
            >
              Réessayer
            </button>
          </p>
        ) : null}
      </div>
      {interest ? (
        <>
          {decimal('grossAmount', 'Montant brut crédité')}
          {decimal('taxes', 'Prélèvements sociaux retenus')}
          <OperationField label="Base du taux" error={errors.interestBasis}>
            <select
              value={values.interestBasis}
              onChange={(event) =>
                onChange(
                  'interestBasis',
                  event.target.value as OperationFormValues['interestBasis'],
                )
              }
            >
              <option value="">Choisir une base</option>
              <option value="NET_OF_MANAGEMENT_FEES">Net de frais de gestion</option>
              <option value="GROSS_OF_MANAGEMENT_FEES">Brut de frais de gestion</option>
              <option value="UNKNOWN">Non précisé</option>
            </select>
          </OperationField>
        </>
      ) : fee ? (
        <>
          {decimal('fees', 'Montant des frais')}
          {values.instrument &&
          values.instrument.instrumentType &&
          values.instrument.instrumentType !== 'EURO_FUND' ? (
            <>
              {decimal('quantity', 'Parts prélevées')}
              {decimal('unitPrice', 'Valeur liquidative')}
            </>
          ) : null}
        </>
      ) : (
        decimal('taxes', 'Montant')
      )}
      {!fee ? (
        <OperationField label={interest ? 'Année du taux' : 'Année'} error={errors.rateYear}>
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
