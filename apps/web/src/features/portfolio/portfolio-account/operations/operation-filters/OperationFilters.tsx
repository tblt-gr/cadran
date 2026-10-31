import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { type OperationFilterValues } from './operationFilterValues';
import { OperationTypeSelect } from './operation-type-select/OperationTypeSelect';
import { OperationsToolbar } from '@/features/accounts/operations-toolbar/OperationsToolbar';

export function OperationFilters({
  currencyCode,
  accountKind,
  disabled = false,
  onChange,
  value,
}: {
  currencyCode: string;
  accountKind?: string;
  disabled?: boolean;
  onChange: (value: OperationFilterValues) => void;
  value: OperationFilterValues;
}) {
  return (
    <OperationsToolbar
      value={value}
      disabled={disabled}
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
          label="Instrument"
          onChange={(item) => onChange({ ...value, instrumentId: item?.id ?? null })}
        />
      }
    />
  );
}
