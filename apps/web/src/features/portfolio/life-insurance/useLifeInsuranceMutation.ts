import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import {
  operationRequestError,
  OperationRequestError,
} from '@/features/portfolio/portfolio-account/operations/operationError';
type Result = { data?: unknown; response?: Response; error?: unknown };
const messages: Record<string, string> = {
  stale_contract_version:
    'Ce contrat a été modifié ailleurs. Fermez cette fenêtre et rouvrez le contrat actualisé.',
  life_insurance_contract_exists:
    'Un contrat existe déjà. Fermez cette fenêtre pour consulter le contrat actualisé.',
  fee_terms_exist: 'Un barème existe déjà à cette date. Choisissez une autre date.',
  euro_fund_rate_exists:
    'Un taux existe déjà pour ce support et cette année. Supprimez-le avant de le remplacer.',
  invalid_contract_field: 'Vérifiez les champs du contrat et le format du numéro.',
  invalid_contract_date: 'Les dates du contrat ne peuvent pas être dans le futur.',
  invalid_fee_rate:
    'Les frais doivent être compris entre 0 et 100 %, avec au plus quatre décimales.',
  invalid_fee_terms_date: 'Le barème doit commencer à la date de souscription ou après.',
  invalid_euro_fund_rate: 'Vérifiez l’année et les taux, avec au plus quatre décimales.',
  instrument_not_euro_fund: 'Sélectionnez un support de type fonds en euros.',
  fee_terms_limit_reached: 'La limite de 50 barèmes est atteinte.',
  euro_fund_rates_limit_reached: 'La limite de 200 taux est atteinte.',
};
export function lifeInsuranceError(error: unknown): string | undefined {
  if (!error) return undefined;
  if (error instanceof OperationRequestError && error.code && messages[error.code])
    return messages[error.code];
  return 'L’enregistrement a échoué. Vérifiez les champs et votre connexion, puis réessayez.';
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
