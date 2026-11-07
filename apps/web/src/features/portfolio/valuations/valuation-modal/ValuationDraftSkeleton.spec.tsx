import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { draftFixture } from '@/features/portfolio/valuations/valuationFixtures';
import { ValuationDraftSkeleton } from './ValuationDraftSkeleton';
import { ValuationForm } from './ValuationForm';

afterEach(cleanup);

it('keeps the form grid, labels and disclosure in the same places while the draft loads', () => {
  const pending = render(<ValuationDraftSkeleton />);
  const loadingForm = pending.container.querySelector('form')!;
  const labels = (form: Element) =>
    [...form.querySelectorAll('label > span:first-child')].map((node) => node.textContent);
  const loadingLabels = labels(loadingForm);
  const gridClass = loadingForm.children[2]!.className;
  expect(loadingForm.querySelectorAll('[data-skeleton]').length).toBe(4);
  expect(loadingForm.querySelector('small')?.textContent).toContain(
    'Laissez vide pour retenir les espèces calculées :',
  );
  expect(loadingForm.querySelector('small [data-skeleton]')).toBeTruthy();
  expect(loadingForm.querySelector('details')?.open).toBe(false);
  expect(loadingForm.querySelector('button')?.disabled).toBe(true);
  pending.unmount();
  const loaded = render(
    <ValuationForm
      assetCode="EUR"
      draft={draftFixture({ lastTotal: null })}
      fieldErrors={{}}
      onSubmit={vi.fn()}
      pending={false}
      version={null}
    />,
  );
  const loadedForm = loaded.container.querySelector('form')!;
  expect(document.querySelector('a[href^="/investments/instruments/"]')).toBeNull();
  expect(labels(loadedForm)).toEqual(loadingLabels);
  expect(loadedForm.children[1]!.className).toBe(gridClass);
  expect(loadedForm.querySelector('details')?.open).toBe(false);
});
