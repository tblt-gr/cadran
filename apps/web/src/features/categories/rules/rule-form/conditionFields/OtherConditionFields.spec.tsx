import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { OtherConditionFields } from './OtherConditionFields';

afterEach(cleanup);

it('re-signs the amount bounds when the direction changes to outflow', () => {
  const onChange = vi.fn();
  render(
    <OtherConditionFields
      conditions={{ amount: { assetCode: 'EUR', max: '200', min: '50' }, direction: null }}
      onChange={onChange}
    />,
  );

  fireEvent.change(screen.getByLabelText('Sens'), { target: { value: 'OUT' } });
  expect(onChange).toHaveBeenCalledWith({
    amount: { assetCode: 'EUR', max: '-50', min: '-200' },
    direction: 'OUT',
  });
});
