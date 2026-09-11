import type {
  Category,
  CategoryType,
  CreateCategoryRequest,
  UpdateCategoryRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { CategoryColorPicker } from '@/features/categories/category-color-picker/CategoryColorPicker';
import { CategoryIconPicker } from '@/features/categories/category-icon-picker/CategoryIconPicker';
import { categoryIcon } from '@/features/categories/category-icons';
import { CategoryIdentityPreview } from '@/features/categories/category-identity-preview/CategoryIdentityPreview';
import type { CategoryErrorKind } from '@/features/categories/categoryError';
import { ParentCategoryField } from './parent-category-field/ParentCategoryField';
import styles from './CategoryForm.module.css';

type AnalyticAxis = CreateCategoryRequest['defaultAnalyticAxes'][number];

const AXES: AnalyticAxis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];

interface CategoryFormProps {
  category?: Category;
  pending: boolean;
  submitError: CategoryErrorKind | null;
  onCancel: () => void;
  onSubmit: (body: CreateCategoryRequest | UpdateCategoryRequest) => void;
}

export function CategoryForm({
  category,
  pending,
  submitError,
  onCancel: _onCancel,
  onSubmit,
}: CategoryFormProps) {
  const { t } = useTranslation();
  const [type, setType] = useState<CategoryType>(category?.type ?? 'EXPENSE');
  const [label, setLabel] = useState(category?.label ?? '');
  const [parentId, setParentId] = useState(category?.parentId ?? '');
  const [icon, setIcon] = useState(category?.icon ?? '');
  const [color, setColor] = useState(category?.color?.toUpperCase() ?? '');
  const [axes, setAxes] = useState<AnalyticAxis[]>(category?.defaultAnalyticAxes ?? []);
  const [budgetIncluded, setBudgetIncluded] = useState(category?.budgetIncluded ?? true);
  const [sortOrder, setSortOrder] = useState(String(category?.sortOrder ?? 0));
  const [showErrors, setShowErrors] = useState(false);

  const cleanLabel = label.trim();
  const parsedOrder = Number.parseInt(sortOrder, 10);
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const orderInvalid = !/^\d{1,5}$/.test(sortOrder) || parsedOrder < 0 || parsedOrder > 32767;
  const iconInvalid = icon !== '' && !categoryIcon(icon);
  const colorInvalid = color !== '' && canonicalCategoryColor(color) === null;

  function toggleAxis(axis: AnalyticAxis) {
    setAxes((current) =>
      current.includes(axis) ? current.filter((value) => value !== axis) : [...current, axis],
    );
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (labelInvalid || orderInvalid || iconInvalid || colorInvalid) {
      setShowErrors(true);
      return;
    }

    const common = {
      type,
      label: cleanLabel,
      icon: icon === '' ? null : icon,
      color: color === '' ? null : color.toUpperCase(),
      defaultAnalyticAxes: axes,
      budgetIncluded,
      sortOrder: parsedOrder,
    };

    onSubmit(
      category
        ? { ...common, version: category.version }
        : { ...common, parentId: parentId === '' ? null : parentId },
    );
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`categories.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <label>
          <span>{t('categories.fields.type')}</span>
          <select
            aria-describedby={
              category && !category.typeEditable ? 'category-type-reason' : undefined
            }
            aria-label={t('categories.fields.type')}
            disabled={category ? !category.typeEditable : false}
            onChange={(event) => {
              setType(event.target.value as CategoryType);
              setParentId('');
            }}
            value={type}
          >
            <option value="EXPENSE">{t('categories.types.EXPENSE')}</option>
            <option value="INCOME">{t('categories.types.INCOME')}</option>
          </select>
          {category && !category.typeEditable ? (
            <small id="category-type-reason">
              {t(`categories.form.typeReasons.${category.typeEditReason ?? 'USED'}`)}
            </small>
          ) : null}
        </label>

        <label>
          <span>{t('categories.fields.label')}</span>
          <input
            aria-invalid={showErrors && labelInvalid ? true : undefined}
            autoComplete="off"
            data-autofocus
            maxLength={80}
            onChange={(event) => setLabel(event.target.value)}
            required
            value={label}
          />
          {showErrors && labelInvalid ? <small>{t('categories.validation.label')}</small> : null}
        </label>

        {!category ? (
          <ParentCategoryField onChange={setParentId} type={type} value={parentId} />
        ) : null}

        <label>
          <span>{t('categories.fields.order')}</span>
          <input
            aria-invalid={showErrors && orderInvalid ? true : undefined}
            inputMode="numeric"
            onChange={(event) => setSortOrder(event.target.value)}
            value={sortOrder}
          />
          {showErrors && orderInvalid ? <small>{t('categories.validation.order')}</small> : null}
        </label>
      </div>

      <CategoryIdentityPreview color={color} icon={icon} label={cleanLabel} />
      <CategoryColorPicker onChange={setColor} value={color} />
      <CategoryIconPicker onChange={setIcon} value={icon} />
      {showErrors && iconInvalid ? <p role="alert">{t('categories.validation.icon')}</p> : null}

      <fieldset aria-describedby="category-axes-hint" className={styles.axes}>
        <legend>{t('categories.fields.axes')}</legend>
        <p className={styles.axesHint} id="category-axes-hint">
          {t('categories.fields.axesHint')}
        </p>
        {AXES.map((axis) => (
          <label key={axis}>
            <input
              checked={axes.includes(axis)}
              onChange={() => toggleAxis(axis)}
              type="checkbox"
            />
            <span>{t(`categories.axes.${axis}`)}</span>
          </label>
        ))}
      </fieldset>

      <label className={styles.check}>
        <input
          checked={budgetIncluded}
          onChange={(event) => setBudgetIncluded(event.target.checked)}
          type="checkbox"
        />
        <span>{t('categories.fields.budgetIncluded')}</span>
      </label>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'categories.form.saving' : 'categories.form.save')}
        </button>
      </div>
    </form>
  );
}
