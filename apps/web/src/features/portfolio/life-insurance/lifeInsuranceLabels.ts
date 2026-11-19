import type {
  LifeInsuranceManagementMode,
  LifeInsuranceEuroFundRateBasis,
} from '@cadran/api-client';
import { lazyLabels } from '@/features/portfolio/lazyLabels';
export const managementLabels = lazyLabels<LifeInsuranceManagementMode>(
  ['FREE', 'MANAGED', 'PILOTED'],
  'portfolio.lifeInsurance.management',
);
export const basisIds: readonly LifeInsuranceEuroFundRateBasis[] = [
  'NET_OF_MANAGEMENT_FEES',
  'GROSS_OF_MANAGEMENT_FEES',
  'UNKNOWN',
];
