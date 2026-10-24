import type { CreateCategoryRequest } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './CategoryAxesField.module.css';

type AnalyticAxis = CreateCategoryRequest['defaultAnalyticAxes'][number];

/** Each pair answers one question, so the three groups are independent of one another. */
const AXIS_GROUPS: { id: 'need' | 'regularity' | 'usage'; axes: [AnalyticAxis, AnalyticAxis] }[] = [
  { id: 'need', axes: ['ESSENTIAL', 'DISCRETIONARY'] },
  { id: 'regularity', axes: ['FIXED', 'VARIABLE'] },
  { id: 'usage', axes: ['PERSONAL', 'PROFESSIONAL'] },
];

interface CategoryAxesFieldProps {
  value: AnalyticAxis[];
  onToggle: (axis: AnalyticAxis) => void;
}

export function CategoryAxesField({ onToggle, value }: CategoryAxesFieldProps) {
  const { t } = useTranslation();
  const hintId = useId();

  return (
    <fieldset aria-describedby={hintId} className={styles.axes}>
      <legend>{t('categories.fields.axes')}</legend>
      <p className={styles.hint} id={hintId}>
        {t('categories.fields.axesHint')}
      </p>
      {AXIS_GROUPS.map((group) => (
        <div className={styles.group} key={group.id}>
          <p className={styles.groupTitle}>{t(`categories.axisGroups.${group.id}`)}</p>
          {group.axes.map((axis) => (
            <label key={axis}>
              <input
                checked={value.includes(axis)}
                onChange={() => onToggle(axis)}
                type="checkbox"
              />
              <span>{t(`categories.axes.${axis}`)}</span>
            </label>
          ))}
        </div>
      ))}
    </fieldset>
  );
}
