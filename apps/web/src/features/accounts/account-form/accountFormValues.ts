import type {
  Account,
  AccountKind,
  AccountValuationMode,
  LiquidityLevel,
} from '@cadran/api-client';
import { type AccountOrigin, originKind } from './accountOrigin';
import { defaultValuationMode } from './valuationModes';

/**
 * The raw field state of the account form, before validation.
 *
 * It is deliberately not a `CreateAccountRequest`: a half-filled form has an
 * empty opening date and an empty institution, which the request shape cannot
 * express. Carrying the raw values is what lets a wizard step back to the form
 * and find it as the user left it.
 */
export interface AccountFormValues {
  label: string;
  institution: string;
  assetCode: string;
  kind: AccountKind;
  maskedIdentifier: string;
  valuationMode: AccountValuationMode;
  liquidityLevel: LiquidityLevel;
  includeInNetWorth: boolean;
  includeInEmergencyFund: boolean;
  openedOn: string;
  closedOn: string;
  primaryGroupId: string;
  tagGroupIds: string[];
}

export function accountFormValues(account: Account): AccountFormValues {
  return {
    label: account.label,
    institution: account.institution ?? '',
    assetCode: account.assetCode,
    kind: account.kind,
    maskedIdentifier: account.maskedIdentifier ?? '',
    valuationMode: account.valuationMode,
    liquidityLevel: account.liquidityLevel,
    includeInNetWorth: account.includeInNetWorth,
    includeInEmergencyFund: account.includeInEmergencyFund,
    openedOn: account.openedOn,
    closedOn: account.closedOn ?? '',
    primaryGroupId: account.primaryGroupId ?? '',
    tagGroupIds: account.tagGroupIds,
  };
}

/**
 * The starting point for a new account. A product or a template declares the
 * kind and the mode it feeds, so those come from it rather than from a
 * default the API would refuse.
 */
export function emptyAccountFormValues(origin: AccountOrigin): AccountFormValues {
  return {
    label: '',
    institution: '',
    assetCode: '',
    kind: originKind(origin) ?? 'CURRENT',
    maskedIdentifier: '',
    valuationMode: defaultValuationMode(origin),
    liquidityLevel: 'IMMEDIATE',
    includeInNetWorth: true,
    includeInEmergencyFund: false,
    openedOn: '',
    closedOn: '',
    primaryGroupId: '',
    tagGroupIds: [],
  };
}
