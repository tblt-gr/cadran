import type { AccountKind, AccountValuationMode, LiquidityLevel } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import { POSITION_KINDS } from '@/features/accounts/account-form/positionKinds';

const VALUATION_MODES: AccountValuationMode[] = ['TRANSACTIONS', 'SNAPSHOTS', 'PORTFOLIO'];

const LIQUIDITY_LEVELS: LiquidityLevel[] = [
  'IMMEDIATE',
  'SHORT_TERM',
  'MEDIUM_TERM',
  'LONG_TERM',
  'ILLIQUID',
];

interface ValuationFieldsProps {
  invalid: boolean;
  kind: AccountKind;
  liquidityLevel: LiquidityLevel;
  onLiquidityLevelChange: (level: LiquidityLevel) => void;
  onValuationModeChange: (mode: AccountValuationMode) => void;
  valuationMode: AccountValuationMode;
}

/**
 * Where the value of the account will come from, and how fast that value can be
 * spent. A portfolio valuation is offered only for a kind that holds positions,
 * so the impossible combination cannot be picked in the first place.
 */
export function ValuationFields({
  invalid,
  kind,
  liquidityLevel,
  onLiquidityLevelChange,
  onValuationModeChange,
  valuationMode,
}: ValuationFieldsProps) {
  const { t } = useTranslation();

  return (
    <>
      <FormField
        error={invalid ? t('accounts.validation.valuationMode') : undefined}
        label={t('accounts.fields.valuationMode')}
        name="valuation-mode"
      >
        {({ fieldId, describedBy }) => (
          <select
            aria-describedby={describedBy}
            aria-invalid={invalid ? true : undefined}
            id={fieldId}
            onChange={(event) => onValuationModeChange(event.target.value as AccountValuationMode)}
            value={valuationMode}
          >
            {VALUATION_MODES.map((option) => (
              <option
                disabled={option === 'PORTFOLIO' && !POSITION_KINDS.includes(kind)}
                key={option}
                value={option}
              >
                {t(`accounts.valuationModes.${option}`)}
              </option>
            ))}
          </select>
        )}
      </FormField>

      <FormField label={t('accounts.fields.liquidityLevel')} name="liquidity-level">
        {({ fieldId }) => (
          <select
            id={fieldId}
            onChange={(event) => onLiquidityLevelChange(event.target.value as LiquidityLevel)}
            value={liquidityLevel}
          >
            {LIQUIDITY_LEVELS.map((option) => (
              <option key={option} value={option}>
                {t(`accounts.liquidityLevels.${option}`)}
              </option>
            ))}
          </select>
        )}
      </FormField>
    </>
  );
}
