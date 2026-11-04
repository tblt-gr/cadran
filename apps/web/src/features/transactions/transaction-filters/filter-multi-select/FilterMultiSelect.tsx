import { useTranslation } from 'react-i18next';
import { MultiSelect } from '@/components/ui/multi-select/MultiSelect';

interface FilterMultiSelectProps<T extends string> {
  /** Summary shown while nothing is selected, which means "no restriction". */
  emptyLabel: string;
  label: string;
  labelFor: (value: T) => string;
  onChange: (next: T[]) => void;
  options: readonly T[];
  value: readonly T[];
}

/**
 * One multi-valued filter (state, nature, analytic axis or source) as a dropdown of options:
 * the four share this exact shape, so the filter panel renders each from this single component.
 */
export function FilterMultiSelect<T extends string>({
  emptyLabel,
  label,
  labelFor,
  onChange,
  options,
  value,
}: FilterMultiSelectProps<T>) {
  const { t } = useTranslation();

  return (
    <MultiSelect<T>
      countLabel={(count) => t('transactions.filters.selectedCount', { count })}
      emptyLabel={emptyLabel}
      label={label}
      onChange={onChange}
      options={options.map((option) => ({ value: option, label: labelFor(option) }))}
      value={value}
    />
  );
}
