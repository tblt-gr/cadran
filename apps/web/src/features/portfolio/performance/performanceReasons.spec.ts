import { describe, expect, it } from 'vitest';
import { describeReason } from './performanceReasons';

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
      'Les espèces constatées diffèrent des espèces calculées.',
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

  it('names an unknown code instead of hiding it', () => {
    const reason = describeReason({ code: 'BRAND_NEW' as never, dates: [] }, context);
    expect(reason.sentence).toBe('Motif non reconnu (BRAND_NEW)');
    expect(reason.action).toBeNull();
  });
});
