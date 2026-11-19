import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import {
  operationRequestError,
  OperationRequestError,
} from '@/features/portfolio/portfolio-account/operations/operationError';
type Result = { data?: unknown; response?: Response; error?: unknown };
const messageCodes = new Set([
  'stale_contract_version',
  'life_insurance_contract_exists',
  'fee_terms_exist',
  'euro_fund_rate_exists',
  'invalid_contract_field',
  'invalid_contract_date',
  'invalid_fee_rate',
  'invalid_fee_terms_date',
  'invalid_euro_fund_rate',
  'instrument_not_euro_fund',
  'fee_terms_limit_reached',
  'euro_fund_rates_limit_reached',
]);
export function lifeInsuranceError(error: unknown): string | undefined {
  if (!error) return undefined;
  if (error instanceof OperationRequestError && error.code && messageCodes.has(error.code))
    return i18n.t(`portfolio.lifeInsurance.errors.${error.code}` as ParseKeys);
  return i18n.t('portfolio.lifeInsurance.errors.saveFailed');
}
export function useLifeInsuranceMutation<T>(
  accountId: string,
  action: (body: T) => Promise<Result>,
  onSaved: () => void,
  relatedQueryKeys: readonly QueryKey[] = [],
) {
  const client = useQueryClient();
  const refresh = () =>
    Promise.all([
      client.invalidateQueries({ queryKey: ['life-insurance-contract', accountId] }),
      client.invalidateQueries({ queryKey: ['life-insurance-summary', accountId] }),
    ]);
  return useMutation({
    mutationFn: async (body: T) => {
      const result = await action(body);
      if (!result.response?.ok) throw operationRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      await Promise.all([
        refresh(),
        ...relatedQueryKeys.map((queryKey) => client.invalidateQueries({ queryKey })),
      ]);
      onSaved();
    },
    onError: async (error) => {
      if (
        error instanceof OperationRequestError &&
        (error.code === 'stale_contract_version' || error.code === 'life_insurance_contract_exists')
      )
        await refresh();
    },
  });
}
