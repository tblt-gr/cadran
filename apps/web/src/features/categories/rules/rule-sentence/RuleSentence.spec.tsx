import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { RuleSentence } from './RuleSentence';

afterEach(cleanup);

it('reads text, amount and direction conditions as one Si/alors sentence', () => {
  render(
    <RuleSentence
      conditions={{
        text: {
          combinator: 'OR',
          predicates: [
            { source: 'COUNTERPARTY', operator: 'CONTAINS', value: 'Carrefour', negated: false },
            { source: 'RAW_LABEL', operator: 'EQUALS', value: 'CB MARKET', negated: true },
          ],
        },
        amount: { min: '10', max: null, assetCode: 'EUR' },
        direction: 'OUT',
        mcc: null,
      }}
      targetCategoryLabel="Courses"
    />,
  );
  const text = screen.getByText(/^Si /).textContent ?? '';
  expect(text).toContain(
    'tiers contient « Carrefour » ou non (libellé d’origine est égal à « CB MARKET »)',
  );
  expect(text).toContain('et le montant est ≥ 10 EUR');
  expect(text).toContain('et c’est une sortie');
  expect(text).toContain('alors classer en « Courses ».');
});

it('says so when a rule has no condition yet', () => {
  render(<RuleSentence conditions={{}} targetCategoryLabel={null} />);
  expect(screen.getByText(/Aucune condition/).textContent).toContain('catégorie choisie');
});
