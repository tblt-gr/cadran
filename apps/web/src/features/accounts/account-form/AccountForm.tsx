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
import { IdentityFields } from './identity-fields/IdentityFields';
import { InclusionFieldset } from './inclusion-fieldset/InclusionFieldset';
import { LifecycleFields } from './lifecycle-fields/LifecycleFields';
import { useAssetOptions } from './useAssetOptions';
import { POSITION_KINDS } from './positionKinds';
import { ValuationFields } from './valuation-fields/ValuationFields';
import styles from './AccountForm.module.css';

interface AccountFormProps {
  account?: Account;
  pending: boolean;
  submitError: AccountErrorKind | null;
  onCancel: () => void;
  onSubmit: (body: CreateAccountRequest | UpdateAccountRequest) => void;
}

export function AccountForm({
  account,
  pending,
  submitError,
  onCancel,
  onSubmit,
}: AccountFormProps) {
  const { t } = useTranslation();
  const [label, setLabel] = useState(account?.label ?? '');
  const [assetSelection, setAssetSelection] = useState(account?.assetCode ?? '');
  const [kind, setKind] = useState<AccountKind>(account?.kind ?? 'CURRENT');
  const [maskedIdentifier, setMaskedIdentifier] = useState(account?.maskedIdentifier ?? '');
  const [valuationMode, setValuationMode] = useState<AccountValuationMode>(
    account?.valuationMode ?? 'TRANSACTIONS',
  );
  const [liquidityLevel, setLiquidityLevel] = useState<LiquidityLevel>(
    account?.liquidityLevel ?? 'IMMEDIATE',
  );
  const [includeInNetWorth, setIncludeInNetWorth] = useState(account?.includeInNetWorth ?? true);
  const [includeInEmergencyFund, setIncludeInEmergencyFund] = useState(
    account?.includeInEmergencyFund ?? false,
  );
  const [openedOn, setOpenedOn] = useState(account?.openedOn ?? '');
  const [closedOn, setClosedOn] = useState(account?.closedOn ?? '');
  const [showErrors, setShowErrors] = useState(false);

  const assets = useAssetOptions(assetSelection);
  const today = new Date().toISOString().slice(0, 10);
  const cleanLabel = label.trim();
  const identifier = maskedIdentifier.trim().toUpperCase();
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const identifierInvalid = identifier !== '' && !/^[A-Z0-9]{2,8}$/.test(identifier);
  // The denomination is fixed at creation, so an unavailable reference blocks
  // the form instead of letting a default currency through.
  const assetInvalid = !account && assets.selected === '';
  const openedInvalid = openedOn === '' || openedOn > today;
  // The backend owns the final ruling; refusing here keeps the user from losing
  // a filled form to a rejection they can see coming.
  const closedInvalid =
    closedOn !== '' && ((openedOn !== '' && closedOn < openedOn) || closedOn > today);
  const valuationInvalid = valuationMode === 'PORTFOLIO' && !POSITION_KINDS.includes(kind);

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (
      labelInvalid ||
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
          kind={kind}
          label={label}
          labelInvalid={showErrors && labelInvalid}
          maskedIdentifier={maskedIdentifier}
          onAssetChange={setAssetSelection}
          onKindChange={setKind}
          onLabelChange={setLabel}
          onMaskedIdentifierChange={setMaskedIdentifier}
        />

        <ValuationFields
          invalid={showErrors && valuationInvalid}
          kind={kind}
          liquidityLevel={liquidityLevel}
          onLiquidityLevelChange={setLiquidityLevel}
          onValuationModeChange={setValuationMode}
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
        <button className="secondary-action" onClick={onCancel} type="button">
          {t('accounts.form.cancel')}
        </button>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'accounts.form.saving' : 'accounts.form.save')}
        </button>
      </div>
    </form>
  );
}
