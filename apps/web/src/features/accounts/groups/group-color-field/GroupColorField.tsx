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
  // Custom is reached through the color input itself, so only inherited modes are radio options.
  const modes: ColorMode[] = root ? [] : ['AUTO', 'PARENT'];
  const customColor = canonicalGroupColor(color) ?? customStart ?? NEW_GROUP_COLOR;
  const inputColor =
    mode === 'CUSTOM' ? customColor : (canonicalGroupColor(swatches[mode]) ?? customColor);

  function optionLabel(option: ColorMode) {
    return t(
      `accountGroups.color.modes.${option === 'AUTO' ? (root ? 'AUTO_ROOT' : 'AUTO_CHILD') : option}`,
    );
  }

  function swatchColor(option: ColorMode): string | null {
    return canonicalGroupColor(swatches[option as 'AUTO' | 'PARENT']);
  }

  return (
    <fieldset
      aria-describedby={invalid ? 'account-group-color-error' : undefined}
      className={styles.field}
      disabled={disabled}
    >
      <legend>{t('accountGroups.color.legend')}</legend>
      {modes.length > 0 ? (
        <div className={styles.options}>
          {modes.map((option) => {
            const swatch = swatchColor(option);

            return (
              <label className={styles.option} key={option}>
                <input
                  checked={mode === option}
                  name="account-group-color-mode"
                  onChange={() => onChange({ mode: option, color: null })}
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
      ) : null}
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
          value={inputColor}
        />
        {mode === 'CUSTOM' ? (
          <button
            className={styles.reset}
            onClick={() => onChange({ mode: defaultMode, color: null })}
            type="button"
          >
            {t('accountGroups.form.resetColor')}
          </button>
        ) : null}
      </div>
      {invalid ? (
        <small id="account-group-color-error">{t('accountGroups.errors.color')}</small>
      ) : null}
      <p>{t('accountGroups.form.colorHelp')}</p>
    </fieldset>
  );
}
