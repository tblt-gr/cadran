/** The value and its result lead, the cumulated flows and the fees follow. */
export const figureLabels = {
  value: 'Valeur du contrat',
  gain: "Gain depuis l'ouverture",
  premiums: 'Versements cumulés',
  surrenders: 'Rachats nets versés',
  fees: 'Frais cumulés',
};
export type ContractFigure = keyof typeof figureLabels;
