import { readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { authApiOptions } from '@/features/auth/apiOptions';
import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { OperationDecimalField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationDecimalField';
import type {
  OperationFieldErrors,
  SupportLegValue,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';
import styles from './SupportLegsField.module.css';

export function SupportLegRow({
  assetCode,
  errors,
  index,
  onChange,
  onRemove,
  value,
}: {
  assetCode: string;
  errors: OperationFieldErrors;
  index: number;
  onChange: (value: SupportLegValue) => void;
  onRemove: () => void;
  value: SupportLegValue;
}) {
  const { t } = useTranslation();
  const metadata = useQuery({
    queryKey: ['instrument', value.instrument?.id],
    enabled: !!value.instrument && !value.instrument.instrumentType,
    queryFn: async ({ signal }) => {
      const result = await readInstrument({
        ...authApiOptions(),
        path: { id: value.instrument!.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Support unavailable');
      return result.data;
    },
    retry: false,
  });
  useEffect(() => {
    if (metadata.data && value.instrument && !value.instrument.instrumentType)
      onChange({
        ...value,
        instrument: { ...value.instrument, instrumentType: metadata.data.instrumentType },
      });
  }, [metadata.data, value, onChange]);
  const euro = value.instrument?.instrumentType === 'EURO_FUND';
  const prefix = `legs.${index}` as const;
  return (
    <fieldset className={styles.row}>
      <legend>{t('portfolio.account.form.legs.support', { index })}</legend>
      <div className={styles.controls}>
        <InstrumentPicker
          allowCreate
          currencyCode={assetCode}
          label={t('portfolio.account.form.legs.support', { index })}
          value={value.instrument}
          onChange={(instrument) =>
            onChange({ ...value, instrument, quantity: '', unitPrice: '', amount: '' })
          }
        />
        {!euro ? (
          <>
            <OperationDecimalField
              label={t('portfolio.account.form.legs.units', { index })}
              value={value.quantity}
              error={errors[`${prefix}.quantity`]}
              onChange={(quantity) => onChange({ ...value, quantity })}
            />
            <OperationDecimalField
              label={t('portfolio.account.form.legs.unitValue', { index })}
              value={value.unitPrice}
              error={errors[`${prefix}.unitPrice`]}
              onChange={(unitPrice) => onChange({ ...value, unitPrice })}
            />
          </>
        ) : null}
        <OperationDecimalField
          label={t('portfolio.account.form.legs.amount', { index })}
          value={value.amount}
          error={errors[`${prefix}.amount`]}
          onChange={(amount) => onChange({ ...value, amount })}
        />
        <button
          className={`icon-button ${styles.remove}`}
          aria-label={t('portfolio.account.form.legs.remove')}
          type="button"
          onClick={onRemove}
        >
          <Icon name="delete" />
        </button>
      </div>
      {errors[`${prefix}.instrument`] ? <p role="alert">{errors[`${prefix}.instrument`]}</p> : null}
      {errors[`${prefix}.row`] ? <p role="alert">{errors[`${prefix}.row`]}</p> : null}
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
    </fieldset>
  );
}
