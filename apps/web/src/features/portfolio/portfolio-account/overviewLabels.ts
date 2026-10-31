import { flagSentence } from '@/features/portfolio/valuations/valuationLabels';

export const kpiLabels = {
  TOTAL_VALUE: 'Valeur totale',
  UNREALIZED_GAIN: 'Plus-value latente',
  CASH: 'Espèces',
  NET_DEPOSITS: 'Versements nets',
  COSTS: 'Frais et taxes',
  RECONCILIATION: 'Rapprochement',
};
export type KpiCode = keyof typeof kpiLabels;
export const warningLabels: Record<string, string> = {
  ...flagSentence,
  LINKED_TRANSFER_CHANGED: 'Versement différent de son virement lié',
  NON_TRANSFER_TRANSACTION:
    'Une transaction a été enregistrée sur ce compte avant le suivi par portefeuille.',
  UNLINKED_TRANSFER_IN: 'Virement bancaire entrant non lié',
  UNLINKED_TRANSFER_OUT: 'Virement bancaire sortant non lié',
  NEGATIVE_CASH: 'Solde espèces négatif',
  UNKNOWN_LOT_COST: 'Prix de revient inconnu',
  ORDER_DEPENDENT_DAY: 'Ordre des opérations à vérifier',
  PARTIAL_VALUATION: 'Valorisation partielle',
  STALE_VALUATION: 'Valorisation ancienne',
  MISSING_PRICE: 'Prix manquant',
  UNKNOWN_POSITION_COST: 'Prix de revient inconnu',
};

export const reasonLabels: Record<string, string> = {
  ...warningLabels,
  NO_VALUATION: 'Aucune valorisation à cette date',
  PARTIAL_VALUATION: 'Valorisation partielle',
  UNKNOWN_LOT_COST: 'Prix de revient inconnu',
  NOT_DETAILED: 'Valorisation saisie en total : répartition par ligne indisponible',
  NON_POSITIVE_TOTAL: 'Valeur totale nulle ou négative : la répartition n’est pas calculable',
  STALE_VALUATION: 'Valorisation ancienne',
  COMPLETE: 'Complète',
  PARTIAL: 'Partielle',
  DERIVED: 'Calculée',
  OBSERVED: 'Relevé',
  RECONCILED: 'Rapproché',
  NEEDS_REVIEW: 'À vérifier',
  NO_DATA: 'Aucune donnée',
  EXACT: 'Exacte',
  CURRENT: 'À jour',
  WARNING: 'À vérifier',
};
