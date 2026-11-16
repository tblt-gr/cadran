import type { AccountKind, AccountValuationMode } from '@cadran/api-client';
import { POSITION_KINDS } from './positionKinds';
import type { AccountOrigin } from './accountOrigin';
import { productFeedsValuationMode } from './valuationModes';

interface AccountFormValidityInput {
  label: string;
  institution: string;
  maskedIdentifier: string;
  kind: AccountKind;
  valuationMode: AccountValuationMode;
  origin: AccountOrigin;
  assetMissing: boolean;
  openedOn: string;
  closedOn: string;
  includeInNetWorth: boolean;
  primaryGroupId: string;
  today: string;
}

export interface AccountFormValidity {
  labelInvalid: boolean;
  institutionInvalid: boolean;
  identifierInvalid: boolean;
  assetInvalid: boolean;
  openedInvalid: boolean;
  closedInvalid: boolean;
  valuationInvalid: boolean;
  groupingInvalid: boolean;
  /** Fields kept in the collapsed advanced section. */
  advancedInvalid: boolean;
  anyInvalid: boolean;
}

/** Client-side refusals that keep a filled form from a rejection the user can see coming. */
export function accountFormValidity(input: AccountFormValidityInput): AccountFormValidity {
  const label = input.label.trim();
  const identifier = input.maskedIdentifier.trim().toUpperCase();
  const labelInvalid = label.length < 1 || [...label].length > 80;
  const institutionInvalid = [...input.institution.trim()].length > 80;
  const identifierInvalid = identifier !== '' && !/^[A-Z0-9]{2,8}$/.test(identifier);
  const assetInvalid = input.assetMissing;
  const openedInvalid = input.openedOn === '' || input.openedOn > input.today;
  // The backend owns the final ruling.
  const closedInvalid =
    input.closedOn !== '' &&
    ((input.openedOn !== '' && input.closedOn < input.openedOn) || input.closedOn > input.today);
  const valuationInvalid =
    (input.valuationMode === 'PORTFOLIO' && !POSITION_KINDS.includes(input.kind)) ||
    !productFeedsValuationMode(input.origin, input.valuationMode);
  const groupingInvalid = input.includeInNetWorth && input.primaryGroupId === '';

  return {
    labelInvalid,
    institutionInvalid,
    identifierInvalid,
    assetInvalid,
    openedInvalid,
    closedInvalid,
    valuationInvalid,
    groupingInvalid,
    advancedInvalid: institutionInvalid || identifierInvalid || closedInvalid,
    anyInvalid:
      labelInvalid ||
      institutionInvalid ||
      identifierInvalid ||
      assetInvalid ||
      openedInvalid ||
      closedInvalid ||
      valuationInvalid ||
      groupingInvalid,
  };
}
