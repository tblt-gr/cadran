import type { GoalEvaluation, GoalPeriodEvaluation } from '@cadran/api-client';

/** A monthly ceiling `Essentiel` of 1 200,00 EUR exceeded by 50,00: the worked example of the goals. */
export function goalEvaluation(extra: Partial<GoalEvaluation> = {}): GoalEvaluation {
  return {
    goalId: '00000000-0000-7000-8000-000000000001',
    label: 'Essentiel',
    periodicity: 'MONTHLY',
    metric: 'AXIS_SPENDING',
    scopeType: 'AXIS',
    scopeRef: 'ESSENTIAL',
    direction: 'CEILING',
    valueType: 'AMOUNT',
    columnKey: 'cashIncome',
    target: { value: '1200.00', assetCode: 'EUR' },
    achieved: { value: '1250.00', assetCode: 'EUR' },
    gap: '-50.00',
    status: 'MISSED',
    reason: null,
    formula: {
      key: 'goals.formula.amount',
      operands: { target: '1200.00', achieved: '1250.00', income: null, ratio: null },
    },
    versionNo: 1,
    overrides: [],
    pace: null,
    ...extra,
  };
}

export function goalPeriod(period: string, ...items: GoalEvaluation[]): GoalPeriodEvaluation {
  return { period, items };
}

/** A minimum savings rate: `0.10` targeted, `0.06` achieved. */
export function rateEvaluation(extra: Partial<GoalEvaluation> = {}): GoalEvaluation {
  return goalEvaluation({
    goalId: '00000000-0000-7000-8000-000000000002',
    label: 'Taux d’épargne',
    metric: 'SAVINGS_RATE',
    scopeType: 'WORKSPACE',
    scopeRef: null,
    direction: 'MINIMUM',
    valueType: 'RATE',
    columnKey: 'cashSavingsRate',
    target: { ratio: '0.10', percentDisplay: '10 %' },
    achieved: { ratio: '0.06', percentDisplay: '6 %' },
    gap: '-0.04',
    formula: {
      key: 'goals.formula.rate',
      operands: { target: '0.10', achieved: '0.06', income: null, ratio: null },
    },
    ...extra,
  });
}
