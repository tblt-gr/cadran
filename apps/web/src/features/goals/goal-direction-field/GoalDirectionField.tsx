import type { GoalDirection } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';

interface Props {
  disabled: boolean;
  value: GoalDirection;
  onChange: (value: GoalDirection) => void;
}

/** Stock goals choose a minimum or ceiling when created; the direction is then immutable. */
export function GoalDirectionField({ disabled, value, onChange }: Props) {
  const { t } = useTranslation();
  return (
    <label>
      <span>{t('goals.direction')}</span>
      <select
        disabled={disabled}
        value={value}
        onChange={(event) => onChange(event.target.value as GoalDirection)}
      >
        <option value="MINIMUM">{t('goals.directions.MINIMUM')}</option>
        <option value="CEILING">{t('goals.directions.CEILING')}</option>
      </select>
    </label>
  );
}
