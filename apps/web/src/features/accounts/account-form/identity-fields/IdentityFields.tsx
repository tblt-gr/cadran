import type { Account, AccountKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { AccountOrigin } from '@/features/accounts/account-form/accountOrigin';
import { AssetField } from '@/features/accounts/account-form/asset-field/AssetField';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import type { AssetOptions } from '@/features/accounts/account-form/useAssetOptions';

const KINDS: AccountKind[] = [
  'CURRENT',
  'SAVINGS',
  'PORTFOLIO',
  'INSURANCE_CONTRACT',
  'EMPLOYEE_BENEFIT',
  'CASH',
  'REAL_ASSET',
  'LIABILITY',
];

interface IdentityFieldsProps {
  account?: Account;
  assetInvalid: boolean;
  assets: AssetOptions;
  identifierInvalid: boolean;
  institution: string;
  institutionInvalid: boolean;
  kind: AccountKind;
  label: string;
  labelInvalid: boolean;
  maskedIdentifier: string;
  onAssetChange: (assetCode: string) => void;
  onInstitutionChange: (institution: string) => void;
  onKindChange: (kind: AccountKind) => void;
  onLabelChange: (label: string) => void;
  onMaskedIdentifierChange: (identifier: string) => void;
  origin: AccountOrigin;
}

/**
 * What names an account: its free label, where it is held, its denomination,
 * its kind and the visible tail of its identifier. The denomination is a
 * read-only reminder once the account exists, because changing it would
 * reinterpret every figure already recorded against it. The kind is read-only
 * too whenever a product or a template declares it: that origin is the
 * authority there. If the origin cannot be read, the kind stays locked
 * rather than offering a choice the API would refuse.
 */
export function IdentityFields({
  account,
  assetInvalid,
  assets,
  identifierInvalid,
  institution,
  institutionInvalid,
  kind,
  label,
  labelInvalid,
  maskedIdentifier,
  onAssetChange,
  onInstitutionChange,
  onKindChange,
  onLabelChange,
  onMaskedIdentifierChange,
  origin,
}: IdentityFieldsProps) {
  const { t } = useTranslation();
  const originLocked =
    origin !== null || account?.productCode != null || account?.productModelId != null;
  const kindLocked = originLocked || (account ? !account.kindEditable : false);

  return (
    <>
      <FormField
        error={labelInvalid ? t('accounts.validation.label') : undefined}
        label={t('accounts.fields.label')}
        name="label"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={labelInvalid ? true : undefined}
            autoComplete="off"
            data-autofocus
            id={fieldId}
            maxLength={80}
            onChange={(event) => onLabelChange(event.target.value)}
            required
            value={label}
          />
        )}
      </FormField>

      <FormField
        error={institutionInvalid ? t('accounts.validation.institution') : undefined}
        hint={institutionInvalid ? undefined : t('accounts.form.institutionHint')}
        label={t('accounts.fields.institution')}
        name="institution"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={institutionInvalid ? true : undefined}
            autoComplete="off"
            id={fieldId}
            maxLength={80}
            onChange={(event) => onInstitutionChange(event.target.value)}
            value={institution}
          />
        )}
      </FormField>

      {account ? (
        <FormField
          hint={t('accounts.form.assetLocked')}
          label={t('accounts.fields.assetCode')}
          name="asset-code"
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              disabled
              id={fieldId}
              readOnly
              value={account.assetCode}
            />
          )}
        </FormField>
      ) : (
        <AssetField assets={assets} invalid={assetInvalid} onChange={onAssetChange} />
      )}

      <FormField
        hint={
          origin?.type === 'product'
            ? t('accounts.form.kindFromProduct', { product: origin.product.displayName })
            : origin?.type === 'template'
              ? t('accounts.form.kindFromTemplate', { template: origin.template.name })
              : account?.productModelId
                ? t('accounts.form.kindFromUnreadTemplate')
                : account?.productCode
                  ? t('accounts.form.kindFromUnreadProduct')
                  : account && !account.kindEditable
                    ? t(`accounts.form.kindReasons.${account.kindEditReason ?? 'USED'}`)
                    : undefined
        }
        label={t('accounts.fields.kind')}
        name="kind"
      >
        {({ fieldId, describedBy }) => (
          <select
            aria-describedby={describedBy}
            disabled={kindLocked}
            id={fieldId}
            onChange={(event) => onKindChange(event.target.value as AccountKind)}
            value={kind}
          >
            {KINDS.map((option) => (
              <option key={option} value={option}>
                {t(`accounts.kinds.${option}`)}
              </option>
            ))}
          </select>
        )}
      </FormField>

      <FormField
        error={identifierInvalid ? t('accounts.validation.maskedIdentifier') : undefined}
        hint={t('accounts.form.maskedIdentifierHint')}
        label={t('accounts.fields.maskedIdentifier')}
        name="masked-identifier"
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={identifierInvalid ? true : undefined}
            autoComplete="off"
            id={fieldId}
            maxLength={8}
            onChange={(event) => onMaskedIdentifierChange(event.target.value)}
            placeholder="4821"
            value={maskedIdentifier}
          />
        )}
      </FormField>
    </>
  );
}
