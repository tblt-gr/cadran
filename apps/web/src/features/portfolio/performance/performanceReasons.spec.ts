import { describe, expect, it } from 'vitest';
import { describeReason, formatReasonDates, summarizeReason } from './performanceReasons';

const context = { accountId: 'account-1', assetCode: 'EUR', residual: '0.50' };

describe('describeReason', () => {
  it('asks for the missing flow valuation with a link to the valuations tab', () => {
    const reason = describeReason(
      { code: 'MISSING_FLOW_VALUATION', dates: ['2026-03-25'] },
      context,
    );
    expect(reason.sentence).toBe("Valorisation manquante le jour d'un flux : 25/03/2026.");
    expect(reason.action).toEqual({
      label: 'Enregistrer une valorisation',
      href: '/portfolios/account-1?tab=valuations',
    });
  });

  it('proposes to complete a partial valuation', () => {
    const reason = describeReason(
      { code: 'END_VALUATION_PARTIAL', dates: ['2026-03-10'] },
      context,
    );
    expect(reason.sentence).toBe('La valorisation du 10/03/2026 est partielle.');
    expect(reason.action).toEqual({
      label: 'Compléter la valorisation',
      href: '/portfolios/account-1?tab=valuations',
    });
  });

  it('links an unknown lot cost to the positions tab', () => {
    const reason = describeReason({ code: 'UNKNOWN_LOT_COST', dates: [] }, context);
    expect(reason.action?.href).toBe('/portfolios/account-1?tab=positions');
  });

  it('has no action for a reason the user cannot act on', () => {
    expect(describeReason({ code: 'NO_CONVERGENCE', dates: [] }, context)).toEqual({
      sentence: "Le calcul n'a pas convergé.",
      action: null,
    });
  });

  it('reuses the valuation flag sentence for a warning', () => {
    expect(describeReason({ code: 'CASH_DIFFERENCE', dates: [] }, context).sentence).toBe(
      'Les espèces du relevé diffèrent des espèces calculées.',
    );
  });

  it('names a non-positive growth factor with the blocking months and no action', () => {
    expect(
      describeReason({ code: 'NON_POSITIVE_GROWTH_FACTOR' as never, dates: ['2026-02'] }, context),
    ).toEqual({
      sentence:
        "Rendement mensuel inférieur ou égal à -100 % : le chaînage n'est pas publié (02/2026).",
      action: null,
    });
    expect(
      describeReason(
        { code: 'NON_POSITIVE_GROWTH_FACTOR' as never, dates: ['2026-01', '2026-03'] },
        context,
      ).sentence,
    ).toBe(
      "Rendement mensuel inférieur ou égal à -100 % : le chaînage n'est pas publié (01/2026, 03/2026).",
    );
  });

  it('fills months, the period end and the residual amount', () => {
    expect(
      describeReason({ code: 'MONTH_NOT_CALCULABLE', dates: ['2026-01', '2026-02'] }, context)
        .sentence,
    ).toBe('Mois non calculables : 01/2026, 02/2026.');
    expect(
      describeReason({ code: 'PERIOD_IN_PROGRESS', dates: ['2026-03-10'] }, context).sentence,
    ).toBe("Période en cours, calculée jusqu'au 10/03/2026.");
    expect(describeReason({ code: 'DECOMPOSITION_RESIDUAL', dates: [] }, context).sentence).toBe(
      'Écart de décomposition de 0,50\u00a0€.',
    );
  });

  it('keeps a long date list out of the overview sentence', () => {
    const dates = ['2023-12-01', '2023-12-28', '2024-02-01', '2024-03-01'];
    expect(summarizeReason({ code: 'MISSING_FLOW_VALUATION', dates }, context)).toBe(
      'Relevés manquants aux dates de versement ou retrait.',
    );
    expect(formatReasonDates(dates)).toBe('01/12/2023, 28/12/2023, 01/02/2024, 01/03/2024');
    expect(summarizeReason({ code: 'NO_END_VALUATION', dates: [] }, context)).toBe(
      'Aucune valorisation complète dans la période.',
    );
  });

  it('names an unknown code instead of hiding it', () => {
    const reason = describeReason({ code: 'BRAND_NEW' as never, dates: [] }, context);
    expect(reason.sentence).toBe('Motif non reconnu (BRAND_NEW)');
    expect(reason.action).toBeNull();
  });
});
