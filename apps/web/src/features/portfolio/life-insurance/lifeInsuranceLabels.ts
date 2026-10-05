import type {
  LifeInsuranceManagementMode,
  LifeInsuranceEuroFundRateBasis,
} from '@cadran/api-client';
export const managementLabels: Record<LifeInsuranceManagementMode, string> = {
  FREE: 'Gestion libre',
  MANAGED: 'Gestion sous mandat',
  PILOTED: 'Gestion pilotée',
};
export const basisLabels: Record<LifeInsuranceEuroFundRateBasis, string> = {
  NET_OF_MANAGEMENT_FEES: 'Net de frais de gestion',
  GROSS_OF_MANAGEMENT_FEES: 'Brut de frais de gestion',
  UNKNOWN: 'Non précisé',
};
