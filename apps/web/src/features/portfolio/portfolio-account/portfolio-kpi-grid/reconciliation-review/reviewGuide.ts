import { warningLabels } from '@/features/portfolio/portfolio-account/overviewLabels';
import type { PortfolioTab } from '@/features/portfolio/portfolio-account/portfolio-tabs/PortfolioTabs';

/** What a review point means, what to do about it and the tab where it is fixed. */
interface ReviewGuide {
  title: (count: number) => string;
  hint: string;
  tab: PortfolioTab;
}

const counted = (label: string, one: string, many: string) => (count: number) =>
  `${label} (${count} ${count === 1 ? one : many})`;

const operations = (label: string) => counted(label, 'opération', 'opérations');
const transfers = (label: string) => counted(label, 'virement', 'virements');
const single = (label: string) => () => label;

const guides: Record<string, ReviewGuide> = {
  NEGATIVE_CASH: {
    title: operations('Espèces négatives'),
    hint: 'Les achats et retraits dépassent l’argent arrivé sur le compte : un versement, un dividende ou des intérêts manquent probablement.',
    tab: 'cash',
  },
  UNLINKED_TRANSFER_IN: {
    title: transfers('Virements reçus sans versement'),
    hint: 'Ces virements bancaires sont arrivés sur le compte sans versement dans le portefeuille. Créez le versement et liez-le au virement.',
    tab: 'cash',
  },
  UNLINKED_TRANSFER_OUT: {
    title: transfers('Virements sortis sans retrait'),
    hint: 'Ces virements bancaires ont quitté le compte sans retrait dans le portefeuille. Créez le retrait et liez-le au virement.',
    tab: 'cash',
  },
  LINKED_TRANSFER_CHANGED: {
    title: operations('Versements différents de leur virement'),
    hint: 'La date, le montant ou le sens ne correspond plus au virement bancaire lié, ou le virement a été annulé. Corrigez l’opération ou le virement pour qu’ils concordent.',
    tab: 'cash',
  },
  NON_TRANSFER_TRANSACTION: {
    title: counted('Transactions bancaires hors virement', 'transaction', 'transactions'),
    hint: 'Un revenu, une dépense ou des frais ont été saisis comme transaction du compte. Saisissez-les comme opération du portefeuille, puis annulez la transaction.',
    tab: 'account',
  },
  CASH_DIFFERENCE: {
    title: single('Espèces du relevé différentes du calcul'),
    hint: 'Le relevé indique d’autres espèces que celles calculées à partir des opérations : une opération manque ou est erronée avant cette date.',
    tab: 'valuations',
  },
  OBSERVED_TOTAL_DIFFERENCE: {
    title: single('Total du relevé différent du calcul'),
    hint: 'Le total saisi s’écarte de la somme des lignes et des espèces. Vérifiez les quantités et les prix de la valorisation.',
    tab: 'valuations',
  },
  POSITIONS_CHANGED: {
    title: single('Valorisation à reprendre'),
    hint: 'Des opérations ont changé à sa date ou avant : ses positions ne correspondent plus. Ressaisissez la valorisation.',
    tab: 'valuations',
  },
  NEGATIVE_SECURITIES_VALUE: {
    title: single('Valeur des titres négative'),
    hint: 'Le total saisi est inférieur aux espèces. Vérifiez le total et les espèces du relevé.',
    tab: 'valuations',
  },
  STALE_PRICES: {
    title: single('Prix anciens'),
    hint: 'Au moins un prix date de plus de 5 jours avant la valorisation. Mettez les prix à jour.',
    tab: 'valuations',
  },
  PARTIAL_VALUATION: {
    title: single('Valorisation partielle'),
    hint: 'Certaines lignes n’ont pas de prix. Complétez la valorisation.',
    tab: 'valuations',
  },
  STALE_VALUATION: {
    title: single('Valorisation ancienne'),
    hint: 'La dernière valorisation est trop ancienne pour la date affichée. Saisissez une valorisation plus récente.',
    tab: 'valuations',
  },
  UNKNOWN_LOT_COST: {
    title: counted('Prix de revient inconnu', 'position', 'positions'),
    hint: 'Des titres sont entrés sans prix de revient (transfert, attribution). Renseignez-le dans l’opération d’origine.',
    tab: 'operations',
  },
  ORDER_DEPENDENT_DAY: {
    title: counted('Ordre des opérations à vérifier', 'jour', 'jours'),
    hint: 'Le même jour, plusieurs opérations donnent un résultat différent selon leur ordre. Vérifiez leur ordre de saisie.',
    tab: 'operations',
  },
};

/** A code without advice still shows under its label, sent to Opérations, rather than hidden. */
export function reviewGuide(code: string): ReviewGuide {
  const label = (warningLabels[code] ?? code).replace(/\.$/, '');
  return guides[code] ?? { title: single(label), hint: '', tab: 'operations' };
}

export const tabLinkLabels: Partial<Record<PortfolioTab, string>> = {
  cash: 'Voir le détail dans Espèces',
  valuations: 'Ouvrir les valorisations',
  operations: 'Ouvrir les opérations',
  account: 'Ouvrir les mouvements du compte',
};
