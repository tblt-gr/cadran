import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

export const FIELD_LABELS = {
  ratio: 'goals.history.fields.ratio',
  schedule: 'goals.history.fields.schedule',
  scheduleMode: 'goals.history.fields.scheduleMode',
  targetAmount: 'goals.history.fields.targetAmount',
} as const;

export type GoalVersionField = keyof typeof FIELD_LABELS;

/** Formats a recorded goal value: amounts in the goal currency, ratios as percentages, modes translated. */
export function useGoalVersionValue(assetCode: string) {
  const { i18n, t } = useTranslation();
  return (value: string | string[] | null, field: GoalVersionField) => {
    if (value === null) return <EmptyValue label={t('goals.notCalculable')} />;
    if (Array.isArray(value) && value.length === 12 && value.every((part) => part === value[0]))
      return t('goals.history.everyMonthAmount', {
        amount: formatAmount(value[0]!, assetCode, i18n.language),
      });
    if (Array.isArray(value))
      return value.map((part) => formatAmount(part, assetCode, i18n.language)).join(', ');
    if (value === 'LINEAR') return t('goals.schedule.modeLinear');
    if (value === 'CUSTOM') return t('goals.schedule.modeCustom');
    if (field === 'ratio') return formatRatioPercentage(value, i18n.language);
    return formatAmount(value, assetCode, i18n.language);
  };
}
