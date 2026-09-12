import type { AnalyticAxes } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import styles from './SplitAxes.module.css';

type AnalyticAxis = AnalyticAxes[number];

const AXES: AnalyticAxis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];

interface SplitAxesProps {
  /** `null` inherits `defaultAxes`; an explicit array, even empty, overrides them. */
  axes: AnalyticAxis[] | null;
  /** Defaults of the row's category, `null` while they are not known. */
  defaultAxes: AnalyticAxis[] | null;
  index: number;
  onChange: (axes: AnalyticAxis[] | null) => void;
}

/**
 * The analytic axes of one split row. Most rows keep the defaults of their
 * category, so the checkboxes stay folded behind a summary of what applies.
 */
export function SplitAxes({ axes, defaultAxes, index, onChange }: SplitAxesProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const inherited = axes === null;
  const effective = axes ?? defaultAxes;
  const names =
    effective === null
      ? null
      : effective.length === 0
        ? t('transactions.split.axesNone')
        : AXES.filter((axis) => effective.includes(axis))
            .map((axis) => t(`categories.axes.${axis}`))
            .join(', ');
  const summary = inherited
    ? names === null
      ? t('transactions.split.axesInheritedUnknown')
      : t('transactions.split.axesInherited', { axes: names })
    : names;

  function toggle(axis: AnalyticAxis) {
    const current = effective ?? [];
    onChange(
      current.includes(axis)
        ? current.filter((candidate) => candidate !== axis)
        : AXES.filter((candidate) => candidate === axis || current.includes(candidate)),
    );
  }

  return (
    <Disclosure
      compact
      meta={summary}
      onToggle={setOpen}
      open={open}
      title={t('transactions.split.axesTitle')}
    >
      <fieldset className={styles.axes}>
        <legend className="sr-only">{t('transactions.split.axesRow', { row: index + 1 })}</legend>
        {AXES.map((axis) => (
          <label key={axis}>
            <input
              checked={(effective ?? []).includes(axis)}
              onChange={() => toggle(axis)}
              type="checkbox"
            />
            <span>{t(`categories.axes.${axis}`)}</span>
          </label>
        ))}
      </fieldset>
      {inherited ? null : (
        <button className={styles.reset} onClick={() => onChange(null)} type="button">
          {t('transactions.split.axesReset')}
        </button>
      )}
    </Disclosure>
  );
}
