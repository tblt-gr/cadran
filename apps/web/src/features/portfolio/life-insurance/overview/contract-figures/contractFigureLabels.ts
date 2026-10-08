export const figureLabels = {
  value: 'Valeur du contrat',
  premiums: 'Versements cumulés',
  surrenders: 'Rachats nets versés',
  gain: "Gain depuis l'ouverture",
  fees: 'Frais cumulés',
};
export type ContractFigure = keyof typeof figureLabels;
