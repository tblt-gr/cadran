import type { Account, CreateAccountRequest, UpdateAccountRequest } from '@cadran/api-client';
import { type AccountOrigin, originProductCode, originProductModelId } from './accountOrigin';
import type { AccountFormValues } from './accountFormValues';

interface BuildAccountRequestInput {
  account: Account | undefined;
  origin: AccountOrigin;
  values: AccountFormValues;
  isPrimary: boolean;
  primaryEligible: boolean;
}

export function buildAccountRequest({
  account,
  origin,
  values,
  isPrimary,
  primaryEligible,
}: BuildAccountRequestInput): CreateAccountRequest | UpdateAccountRequest {
  const institution = values.institution.trim();
  const identifier = values.maskedIdentifier.trim().toUpperCase();
  const common = {
    label: values.label.trim(),
    kind: values.kind,
    // Only the reference travels. Ceilings, rates and methods stay on the
    // product or the template, read on the business date they are needed.
    productCode: originProductCode(origin) ?? account?.productCode ?? null,
    productModelId: originProductModelId(origin) ?? account?.productModelId ?? null,
    institution: institution === '' ? null : institution,
    maskedIdentifier: identifier === '' ? null : identifier,
    valuationMode: values.valuationMode,
    liquidityLevel: values.liquidityLevel,
    includeInNetWorth: values.includeInNetWorth,
    includeInEmergencyFund: values.includeInNetWorth && values.includeInEmergencyFund,
    openedOn: values.openedOn,
    closedOn: values.closedOn === '' ? null : values.closedOn,
  };
  const grouping = {
    primaryGroupId: values.primaryGroupId === '' ? null : values.primaryGroupId,
  };

  return account
    ? {
        ...common,
        ...grouping,
        version: account.version,
        isPrimary: primaryEligible && isPrimary,
      }
    : { ...common, ...grouping, assetCode: values.assetCode };
}
