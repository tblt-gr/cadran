import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { AccountDescriptiveFields } from './identity-fields/AccountDescriptiveFields';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
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
import type { AccountOrigin } from './accountOrigin';
import { buildAccountRequest } from './buildAccountRequest';
import { IdentityFields } from './identity-fields/IdentityFields';
import { InclusionFieldset } from './inclusion-fieldset/InclusionFieldset';
import { LifecycleFields } from './lifecycle-fields/LifecycleFields';
import { PrimaryAccountField } from './primary-account-field/PrimaryAccountField';
import { useAssetOptions } from './useAssetOptions';
import { accountFormValidity } from './accountFormValidity';
import { GroupingFields } from './grouping-fields/GroupingFields';
import { ValuationFields } from './valuation-fields/ValuationFields';
import { AccountSubmitError } from './submit-error/AccountSubmitError';
import styles from './AccountForm.module.css';

interface AccountFormProps {
  account?: Account;
  /** Values a previous pass through this form left, so a step back does not blank it. */
  defaults?: AccountFormValues | null;
  pending: boolean;
  /** The catalogue product or workspace template the account is created from, or null when it is described by hand. */
  origin?: AccountOrigin;
  submitError: AccountErrorKind | null;
  /** For an insufficient balance: the first day the account would be negative (ISO 8601). */
  submitErrorDay?: string | null;
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
  submitErrorDay = null,
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
  const [isPrimary, setIsPrimary] = useState(account?.isPrimary ?? false);
  const [primaryGroupId, setPrimaryGroupId] = useState(initial.primaryGroupId);
  const [showErrors, setShowErrors] = useState(false);

  const assets = useAssetOptions(assetSelection);
  const today = todayInBrowser();
  const validity = accountFormValidity({
    label,
    institution,
    maskedIdentifier,
    kind,
    valuationMode,
    origin,
    // The denomination is fixed at creation, so an unavailable reference blocks
    // the form instead of letting a default currency through.
    assetMissing: !account && assets.selected === '',
    openedOn,
    closedOn,
    includeInNetWorth,
    primaryGroupId,
    today,
  });
  const primaryEligible =
    kind === 'CURRENT' &&
    valuationMode === 'TRANSACTIONS' &&
    closedOn === '' &&
    account?.archivedAt === null;

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
  };

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (validity.anyInvalid) {
      setShowErrors(true);
      return;
    }

    onSubmit(buildAccountRequest({ account, origin, values, isPrimary, primaryEligible }), values);
  }

  const [advancedOpen, setAdvancedOpen] = useState(
    Boolean(institution || maskedIdentifier || closedOn),
  );

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {submitError ? <AccountSubmitError day={submitErrorDay} error={submitError} /> : null}

      <FormGrid className={styles.fields}>
        <IdentityFields
          account={account}
          assetInvalid={showErrors && validity.assetInvalid}
          assets={assets}
          kind={kind}
          label={label}
          labelInvalid={showErrors && validity.labelInvalid}
          onAssetChange={setAssetSelection}
          onKindChange={setKind}
          onLabelChange={setLabel}
          origin={origin}
        />

        <ValuationFields
          invalid={showErrors && validity.valuationInvalid}
          kind={kind}
          liquidityLevel={liquidityLevel}
          onLiquidityLevelChange={setLiquidityLevel}
          onValuationModeChange={setValuationMode}
          origin={origin}
          valuationMode={valuationMode}
        />

        <LifecycleFields
          closedOn={closedOn}
          closedOnInvalid={showErrors && validity.closedInvalid}
          onClosedOnChange={setClosedOn}
          onOpenedOnChange={setOpenedOn}
          openedOn={openedOn}
          openedOnInvalid={showErrors && validity.openedInvalid}
          today={today}
        />
        <FormSection
          title={t('transactions.form.advanced')}
          open={advancedOpen || (showErrors && validity.advancedInvalid)}
          onToggle={setAdvancedOpen}
        >
          <AccountDescriptiveFields
            identifierInvalid={showErrors && validity.identifierInvalid}
            institution={institution}
            institutionInvalid={showErrors && validity.institutionInvalid}
            maskedIdentifier={maskedIdentifier}
            onInstitutionChange={setInstitution}
            onMaskedIdentifierChange={setMaskedIdentifier}
          />
          <LifecycleFields
            advanced
            closedOn={closedOn}
            closedOnInvalid={showErrors && validity.closedInvalid}
            onClosedOnChange={setClosedOn}
            onOpenedOnChange={setOpenedOn}
            openedOn={openedOn}
            openedOnInvalid={showErrors && validity.openedInvalid}
            today={today}
          />
        </FormSection>
      </FormGrid>

      {account && kind === 'CURRENT' ? (
        <PrimaryAccountField
          checked={primaryEligible && isPrimary}
          eligible={primaryEligible}
          onChange={setIsPrimary}
        />
      ) : null}

      <GroupingFields
        includeInNetWorth={includeInNetWorth}
        invalid={showErrors && validity.groupingInvalid}
        onPrimaryChange={setPrimaryGroupId}
        primaryGroupId={primaryGroupId}
        share={account?.share}
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

      <ModalActions>
        {submitLabel === 'continue' ? (
          <button className="secondary-action" onClick={() => onCancel(values)} type="button">
            {t('accounts.form.back')}
          </button>
        ) : null}
        <button className="primary-action" disabled={pending} type="submit">
          {t(
            submitLabel === 'continue'
              ? 'accounts.form.continue'
              : pending
                ? 'accounts.form.saving'
                : 'accounts.form.save',
          )}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
