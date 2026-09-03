import type {
  Account,
  AccountKind,
  AccountValuationMode,
  CreateAccountRequest,
  LiquidityLevel,
  Product,
  UpdateAccountRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import {
  accountFormValues,
  emptyAccountFormValues,
  type AccountFormValues,
} from './accountFormValues';
import { IdentityFields } from './identity-fields/IdentityFields';
import { InclusionFieldset } from './inclusion-fieldset/InclusionFieldset';
import { LifecycleFields } from './lifecycle-fields/LifecycleFields';
import { useAssetOptions } from './useAssetOptions';
import { POSITION_KINDS } from './positionKinds';
import { productFeedsValuationMode } from './valuationModes';
import { ValuationFields } from './valuation-fields/ValuationFields';
import styles from './AccountForm.module.css';

interface AccountFormProps {
  account?: Account;
  /** Values a previous pass through this form left, so a step back does not blank it. */
  defaults?: AccountFormValues | null;
  pending: boolean;
  /** The catalogue product the account is created from, or null when it is described by hand. */
  product?: Product | null;
  submitError: AccountErrorKind | null;
  submitLabel?: 'save' | 'continue';
  /** Receives the fields as they stand, so a caller can restore them later. */
  onCancel: (values: AccountFormValues) => void;
  onSubmit: (body: CreateAccountRequest | UpdateAccountRequest, values: AccountFormValues) => void;
}

export function AccountForm({
  account,
  defaults = null,
  pending,
  product = null,
  submitError,
  submitLabel = 'save',
  onCancel,
  onSubmit,
}: AccountFormProps) {
  const { t } = useTranslation();
  // A product declares the kind of account it is, so the starting point comes
  // from the catalogue rather than from a default the API would refuse.
  const initial =
    defaults ?? (account ? accountFormValues(account) : emptyAccountFormValues(product));
  const [label, setLabel] = useState(initial.label);
  const [institution, setInstitution] = useState(initial.institution);
  const [assetSelection, setAssetSelection] = useState(initial.assetCode);
  const [kind, setKind] = useState<AccountKind>(initial.kind);
  const [maskedIdentifier, setMaskedIdentifier] = useState(initial.maskedIdentifier);
  const [valuationMode, setValuationMode] = useState<AccountValuationMode>(initial.valuationMode);
  const [liquidityLevel, setLiquidityLevel] = useState<LiquidityLevel>(initial.liquidityLevel);
  const [includeInNetWorth, setIncludeInNetWorth] = useState(initial.includeInNetWorth);
  const [includeInEmergencyFund, setIncludeInEmergencyFund] = useState(
    initial.includeInEmergencyFund,
  );
  const [openedOn, setOpenedOn] = useState(initial.openedOn);
  const [closedOn, setClosedOn] = useState(initial.closedOn);
  const [showErrors, setShowErrors] = useState(false);

  const assets = useAssetOptions(assetSelection);
  const today = new Date().toISOString().slice(0, 10);
  const cleanLabel = label.trim();
  const cleanInstitution = institution.trim();
  const identifier = maskedIdentifier.trim().toUpperCase();
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const institutionInvalid = [...cleanInstitution].length > 80;
  const identifierInvalid = identifier !== '' && !/^[A-Z0-9]{2,8}$/.test(identifier);
  // The denomination is fixed at creation, so an unavailable reference blocks
  // the form instead of letting a default currency through.
  const assetInvalid = !account && assets.selected === '';
  const openedInvalid = openedOn === '' || openedOn > today;
  // The backend owns the final ruling; refusing here keeps the user from losing
  // a filled form to a rejection they can see coming.
  const closedInvalid =
    closedOn !== '' && ((openedOn !== '' && closedOn < openedOn) || closedOn > today);
  const valuationInvalid =
    (valuationMode === 'PORTFOLIO' && !POSITION_KINDS.includes(kind)) ||
    !productFeedsValuationMode(product, valuationMode);

  const values: AccountFormValues = {
    label,
    institution,
    assetCode: assets.selected,
    kind,
    maskedIdentifier,
    valuationMode,
    liquidityLevel,
    includeInNetWorth,
    includeInEmergencyFund,
    openedOn,
    closedOn,
  };

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (
      labelInvalid ||
      institutionInvalid ||
      identifierInvalid ||
      assetInvalid ||
      openedInvalid ||
      closedInvalid ||
      valuationInvalid
    ) {
      setShowErrors(true);
      return;
    }

    const common = {
      label: cleanLabel,
      kind,
      // Only the reference travels. Ceilings, rates and methods stay in the
      // catalogue, read on the business date they are needed.
      productCode: product?.code ?? account?.productCode ?? null,
      institution: cleanInstitution === '' ? null : cleanInstitution,
      maskedIdentifier: identifier === '' ? null : identifier,
      valuationMode,
      liquidityLevel,
      includeInNetWorth,
      includeInEmergencyFund: includeInNetWorth && includeInEmergencyFund,
      openedOn,
      closedOn: closedOn === '' ? null : closedOn,
    };

    onSubmit(
      account ? { ...common, version: account.version } : { ...common, assetCode: assets.selected },
      values,
    );
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <IdentityFields
          account={account}
          assetInvalid={showErrors && assetInvalid}
          assets={assets}
          identifierInvalid={showErrors && identifierInvalid}
          institution={institution}
          institutionInvalid={showErrors && institutionInvalid}
          kind={kind}
          label={label}
          labelInvalid={showErrors && labelInvalid}
          maskedIdentifier={maskedIdentifier}
          onAssetChange={setAssetSelection}
          onInstitutionChange={setInstitution}
          onKindChange={setKind}
          onLabelChange={setLabel}
          onMaskedIdentifierChange={setMaskedIdentifier}
          product={product}
        />

        <ValuationFields
          invalid={showErrors && valuationInvalid}
          kind={kind}
          liquidityLevel={liquidityLevel}
          onLiquidityLevelChange={setLiquidityLevel}
          onValuationModeChange={setValuationMode}
          product={product}
          valuationMode={valuationMode}
        />

        <LifecycleFields
          closedOn={closedOn}
          closedOnInvalid={showErrors && closedInvalid}
          onClosedOnChange={setClosedOn}
          onOpenedOnChange={setOpenedOn}
          openedOn={openedOn}
          openedOnInvalid={showErrors && openedInvalid}
          today={today}
        />
      </div>

      <InclusionFieldset
        includeInEmergencyFund={includeInEmergencyFund}
        includeInNetWorth={includeInNetWorth}
        onEmergencyFundChange={setIncludeInEmergencyFund}
        onNetWorthChange={(checked) => {
          setIncludeInNetWorth(checked);
          if (!checked) {
            setIncludeInEmergencyFund(false);
          }
        }}
      />

      <div className={styles.actions}>
        <button className="secondary-action" onClick={() => onCancel(values)} type="button">
          {t(submitLabel === 'continue' ? 'accounts.form.back' : 'accounts.form.cancel')}
        </button>
        <button className="primary-action" disabled={pending} type="submit">
          {t(
            submitLabel === 'continue'
              ? 'accounts.form.continue'
              : pending
                ? 'accounts.form.saving'
                : 'accounts.form.save',
          )}
        </button>
      </div>
    </form>
  );
}
