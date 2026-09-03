import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import type { AssetOptions } from '@/features/accounts/account-form/useAssetOptions';

interface AssetFieldProps {
  assets: AssetOptions;
  invalid: boolean;
  onChange: (assetCode: string) => void;
}

/**
 * The denomination of a new account, chosen among the units of the read-only
 * system asset reference rather than typed.
 */
export function AssetField({ assets, invalid, onChange }: AssetFieldProps) {
  const { t } = useTranslation();

  return (
    <FormField
      error={invalid ? t('accounts.validation.assetCode') : undefined}
      hint={
        assets.isPending
          ? t('accounts.form.assetsLoading')
          : assets.isError
            ? t('accounts.form.assetsError')
            : t('accounts.form.assetLocked')
      }
      label={t('accounts.fields.assetCode')}
      name="asset-code"
    >
      {({ fieldId, describedBy }) => (
        <select
          aria-describedby={describedBy}
          aria-invalid={invalid ? true : undefined}
          disabled={assets.isPending || assets.isError}
          id={fieldId}
          onChange={(event) => onChange(event.target.value)}
          value={assets.selected}
        >
          {assets.items.map((asset) => (
            <option key={asset.code} value={asset.code}>
              {asset.code} · {asset.displayName}
            </option>
          ))}
        </select>
      )}
    </FormField>
  );
}
