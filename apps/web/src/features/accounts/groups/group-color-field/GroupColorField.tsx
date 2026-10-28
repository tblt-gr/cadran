import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { canonicalGroupColor, NEW_GROUP_COLOR } from '@/features/accounts/group-color';
import styles from './GroupColorField.module.css';

type ColorMode = AccountGroup['colorMode'];

export interface GroupColorChange {
  mode: ColorMode;
  color: string | null;
}

interface GroupColorFieldProps {
  mode: ColorMode;
  color: string | null;
  /** Colors the non custom modes produce; null when they are not known before saving. */
  swatches: { AUTO: string | null; PARENT: string | null };
  /** A root has no parent: it is offered neither the shade nor the parent color. */
  root: boolean;
  /** Color a switch to custom mode starts from. */
  customStart?: string;
  disabled: boolean;
  invalid?: boolean;
  onChange: (change: GroupColorChange) => void;
}

export function GroupColorField({
  mode,
  color,
  swatches,
  root,
  customStart,
  disabled,
  invalid,
  onChange,
}: GroupColorFieldProps) {
  const { t } = useTranslation();
  const defaultMode: ColorMode = 'AUTO';
  const modes: ColorMode[] = root ? ['AUTO', 'CUSTOM'] : ['AUTO', 'PARENT', 'CUSTOM'];
  const customColor = canonicalGroupColor(color) ?? customStart ?? NEW_GROUP_COLOR;

  function optionLabel(option: ColorMode) {
    return t(
      `accountGroups.color.modes.${option === 'AUTO' ? (root ? 'AUTO_ROOT' : 'AUTO_CHILD') : option}`,
    );
  }

  function swatchColor(option: ColorMode): string | null {
    const value = option === 'CUSTOM' ? customColor : swatches[option];

    return canonicalGroupColor(value);
  }

  return (
    <fieldset
      aria-describedby={invalid ? 'account-group-color-error' : undefined}
      className={styles.field}
      disabled={disabled}
    >
      <legend>{t('accountGroups.color.legend')}</legend>
      <div className={styles.options}>
        {modes.map((option) => {
          const swatch = swatchColor(option);

          return (
            <label className={styles.option} key={option}>
              <input
                checked={mode === option}
                name="account-group-color-mode"
                onChange={() =>
                  onChange({ mode: option, color: option === 'CUSTOM' ? customColor : null })
                }
                type="radio"
                value={option}
              />
              <span>{optionLabel(option)}</span>
              {swatch ? (
                <span
                  aria-hidden="true"
                  className={styles.swatch}
                  data-swatch
                  style={{ backgroundColor: swatch }}
                />
              ) : option === 'AUTO' && !root ? (
                <small>{t('accountGroups.color.pendingShade')}</small>
              ) : null}
            </label>
          );
        })}
      </div>
      {mode === 'CUSTOM' ? (
        <div className={styles.controls}>
          <input
            aria-invalid={invalid || undefined}
            aria-describedby={invalid ? 'account-group-color-error' : undefined}
            aria-label={t('accountGroups.fields.color')}
            id="account-group-color"
            onChange={(event) =>
              onChange({ mode: 'CUSTOM', color: canonicalGroupColor(event.target.value) })
            }
            type="color"
            value={customColor}
          />
          <button
            className={styles.reset}
            onClick={() => onChange({ mode: defaultMode, color: null })}
            type="button"
          >
            {t('accountGroups.form.resetColor')}
          </button>
        </div>
      ) : null}
      {invalid ? (
        <small id="account-group-color-error">{t('accountGroups.errors.color')}</small>
      ) : null}
      <p>{t('accountGroups.form.colorHelp')}</p>
    </fieldset>
  );
}
