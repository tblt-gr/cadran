import { useTranslation } from 'react-i18next';
import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { OperationsToolbar } from '@/features/accounts/operations-toolbar/OperationsToolbar';
import { type OperationFilterValues } from './operationFilterValues';
import { OperationTypeSelect } from './operation-type-select/OperationTypeSelect';

export interface OperationFiltersProps {
  currencyCode: string;
  accountKind?: string;
  disabled?: boolean;
  onChange: (value: OperationFilterValues) => void;
  value: OperationFilterValues;
}

/** The type, period and instrument inputs, on screen from a tablet and in the phone sheet. */
export function OperationFilterInputs({
  currencyCode,
  accountKind,
  disabled = false,
  onChange,
  value,
}: OperationFiltersProps) {
  const { t } = useTranslation();
  return (
    <OperationsToolbar
      value={value}
      disabled={disabled}
      voidedInHeader
      onChange={(next) => onChange({ ...value, ...next })}
      types={
        <OperationTypeSelect
          accountKind={accountKind}
          onChange={(types) => onChange({ ...value, types })}
          value={value.types}
        />
      }
      instrument={
        <InstrumentPicker
          currencyCode={currencyCode}
          label={t('portfolio.account.form.instrument')}
          onChange={(item) =>
            onChange({
              ...value,
              instrumentId: item?.id ?? null,
              instrumentName: item?.name ?? null,
            })
          }
          value={value.instrumentName ? { name: value.instrumentName } : null}
        />
      }
    />
  );
}
