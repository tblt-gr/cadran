export const instrumentTypes = [
  ['EQUITY', 'Action'],
  ['ETF', 'ETF'],
  ['FUND', 'Fonds (OPCVM)'],
  ['BOND', 'Obligation'],
  ['OTHER', 'Autre'],
] as const;
export const instrumentClasses = [
  ['EQUITY', 'Actions'],
  ['BOND', 'Obligations'],
  ['MONEY_MARKET', 'Monétaire'],
  ['REAL_ESTATE', 'Immobilier'],
  ['COMMODITY', 'Matières premières'],
  ['MIXED', 'Diversifié'],
  ['OTHER', 'Autre'],
] as const;
export const instrumentRegions = [
  ['FRANCE', 'France'],
  ['EUROPE', 'Europe'],
  ['NORTH_AMERICA', 'Amérique du Nord'],
  ['ASIA_PACIFIC', 'Asie-Pacifique'],
  ['EMERGING', 'Pays émergents'],
  ['WORLD', 'Monde'],
  ['OTHER', 'Autre'],
] as const;

export const PRECISION_MESSAGE = 'La précision doit être comprise entre 0 et 12.';
export function validPrecision(value: string) {
  return /^\d{1,2}$/.test(value) && Number(value) <= 12;
}
