import type {
  Account,
  AccountKind,
  AccountValuationMode,
  CreateAccountRequest,
  LiquidityLevel,
  UpdateAccountRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { todayInBrowser } from '@/lib/businessDay';
import {
  accountFormValues,
  emptyAccountFormValues,
  type AccountFormValues,
} from './accountFormValues';
import { type AccountOrigin, originProductCode, originProductModelId } from './accountOrigin';
import { IdentityFields } from './identity-fields/IdentityFields';
import { InclusionFieldset } from './inclusion-fieldset/InclusionFieldset';
import { LifecycleFields } from './lifecycle-fields/LifecycleFields';
import { useAssetOptions } from './useAssetOptions';
import { POSITION_KINDS } from './positionKinds';
import { productFeedsValuationMode } from './valuationModes';
import { GroupingFields } from './grouping-fields/GroupingFields';
import { ValuationFields } from './valuation-fields/ValuationFields';
import styles from './AccountForm.module.css';

interface AccountFormProps {
  account?: Account;
  /** Values a previous pass through this form left, so a step back does not blank it. */
  defaults?: AccountFormValues | null;
  pending: boolean;
  /** The catalogue product or workspace template the account is created from, or null when it is described by hand. */
  origin?: AccountOrigin;
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
  origin = null,
  submitError,
  submitLabel = 'save',
  onCancel,
  onSubmit,
}: AccountFormProps) {
  const { t } = useTranslation();
  // A product or a template declares the kind of account it is, so the
  // starting point comes from it rather than from a default the API would
  // refuse.
  const initial =
    defaults ?? (account ? accountFormValues(account) : emptyAccountFormValues(origin));
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
  const [primaryGroupId, setPrimaryGroupId] = useState(initial.primaryGroupId);
  const [tagGroupIds, setTagGroupIds] = useState(initial.tagGroupIds);
  const [showErrors, setShowErrors] = useState(false);

  const assets = useAssetOptions(assetSelection);
  const today = todayInBrowser();
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
    !productFeedsValuationMode(origin, valuationMode);

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
    primaryGroupId,
    tagGroupIds,
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
      // Only the reference travels. Ceilings, rates and methods stay on the
      // product or the template, read on the business date they are needed.
      productCode: originProductCode(origin) ?? account?.productCode ?? null,
      productModelId: originProductModelId(origin) ?? account?.productModelId ?? null,
      institution: cleanInstitution === '' ? null : cleanInstitution,
      maskedIdentifier: identifier === '' ? null : identifier,
      valuationMode,
      liquidityLevel,
      includeInNetWorth,
      includeInEmergencyFund: includeInNetWorth && includeInEmergencyFund,
      openedOn,
      closedOn: closedOn === '' ? null : closedOn,
    };

    const grouping = {
      primaryGroupId: primaryGroupId === '' ? null : primaryGroupId,
      tagGroupIds,
    };

    onSubmit(
      account
        ? {
            ...common,
            ...grouping,
            version: account.version,
          }
        : { ...common, ...grouping, assetCode: assets.selected },
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
          origin={origin}
        />

        <ValuationFields
          invalid={showErrors && valuationInvalid}
          kind={kind}
          liquidityLevel={liquidityLevel}
          onLiquidityLevelChange={setLiquidityLevel}
          onValuationModeChange={setValuationMode}
          origin={origin}
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

      <GroupingFields
        onPrimaryChange={setPrimaryGroupId}
        onTagsChange={setTagGroupIds}
        primaryGroupId={primaryGroupId}
        share={account?.share}
        tagGroupIds={tagGroupIds}
      />

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
