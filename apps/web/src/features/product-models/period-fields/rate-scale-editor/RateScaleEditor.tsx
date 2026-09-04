import type { RateApplication } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import {
  emptyBracket,
  type BracketValues,
} from '@/features/product-models/period-fields/periodValues';
import styles from './RateScaleEditor.module.css';

const APPLICATIONS: RateApplication[] = ['MARGINAL', 'FLAT_BY_BRACKET'];
const MAX_BRACKETS = 20;

interface RateScaleEditorProps {
  scaleShape: 'single' | 'tiered';
  rateApplication: RateApplication;
  singleRate: string;
  brackets: BracketValues[];
  invalid: boolean;
  onScaleShapeChange: (shape: 'single' | 'tiered') => void;
  onRateApplicationChange: (application: RateApplication) => void;
  onSingleRateChange: (value: string) => void;
  onBracketsChange: (brackets: BracketValues[]) => void;
}

/**
 * The scale a rate period is made of. A single rate is one open-ended bracket;
 * a tiered scale is an ordered list of them with an explicit application mode,
 * because the same brackets pay a different interest depending on whether the
 * bracket reached applies to the whole balance or only to the slice it covers.
 * The editor keeps the choice explicit and never picks a mode on the holder's
 * behalf.
 */
export function RateScaleEditor({
  scaleShape,
  rateApplication,
  singleRate,
  brackets,
  invalid,
  onScaleShapeChange,
  onRateApplicationChange,
  onSingleRateChange,
  onBracketsChange,
}: RateScaleEditorProps) {
  const { t } = useTranslation();

  function updateBracket(index: number, patch: Partial<BracketValues>) {
    onBracketsChange(
      brackets.map((bracket, i) => (i === index ? { ...bracket, ...patch } : bracket)),
    );
  }

  return (
    <div className={styles.editor}>
      <fieldset className={styles.shape}>
        <legend>{t('productModels.period.scale.shapeLegend')}</legend>
        <label>
          <input
            checked={scaleShape === 'single'}
            name="scale-shape"
            onChange={() => onScaleShapeChange('single')}
            type="radio"
          />
          <span>{t('productModels.period.scale.single')}</span>
        </label>
        <label>
          <input
            checked={scaleShape === 'tiered'}
            name="scale-shape"
            onChange={() => onScaleShapeChange('tiered')}
            type="radio"
          />
          <span>{t('productModels.period.scale.tiered')}</span>
        </label>
      </fieldset>

      {scaleShape === 'single' ? (
        <label className={styles.singleRate}>
          <span>{t('productModels.period.scale.rate')}</span>
          <input
            aria-invalid={invalid ? true : undefined}
            inputMode="decimal"
            onChange={(event) => onSingleRateChange(event.target.value)}
            value={singleRate}
          />
        </label>
      ) : (
        <>
          <fieldset className={styles.application}>
            <legend>{t('productModels.period.scale.applicationLegend')}</legend>
            {APPLICATIONS.map((application) => (
              <label key={application}>
                <input
                  checked={rateApplication === application}
                  name="rate-application"
                  onChange={() => onRateApplicationChange(application)}
                  type="radio"
                />
                <span>{t(`accounts.rules.applications.${application}`)}</span>
              </label>
            ))}
          </fieldset>

          <div className={styles.tableScroll}>
            <table className={styles.brackets}>
              <thead>
                <tr>
                  <th scope="col">{t('productModels.period.scale.lowerBound')}</th>
                  <th scope="col">{t('productModels.period.scale.upperBound')}</th>
                  <th scope="col">{t('productModels.period.scale.percentage')}</th>
                  <th scope="col">
                    <span className="sr-only">{t('productModels.period.scale.rowActions')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {brackets.map((bracket, index) => (
                  <tr key={index}>
                    <td>
                      <input
                        aria-invalid={invalid ? true : undefined}
                        aria-label={t('productModels.period.scale.lowerBoundRow', {
                          row: index + 1,
                        })}
                        inputMode="decimal"
                        onChange={(event) =>
                          updateBracket(index, { lowerBound: event.target.value })
                        }
                        value={bracket.lowerBound}
                      />
                    </td>
                    <td>
                      <input
                        aria-invalid={invalid ? true : undefined}
                        aria-label={t('productModels.period.scale.upperBoundRow', {
                          row: index + 1,
                        })}
                        inputMode="decimal"
                        onChange={(event) =>
                          updateBracket(index, { upperBound: event.target.value })
                        }
                        placeholder={t('productModels.period.scale.noLimit')}
                        value={bracket.upperBound}
                      />
                    </td>
                    <td>
                      <input
                        aria-invalid={invalid ? true : undefined}
                        aria-label={t('productModels.period.scale.percentageRow', {
                          row: index + 1,
                        })}
                        inputMode="decimal"
                        onChange={(event) =>
                          updateBracket(index, { percentage: event.target.value })
                        }
                        value={bracket.percentage}
                      />
                    </td>
                    <td>
                      <button
                        aria-label={t('productModels.period.scale.removeRow', { row: index + 1 })}
                        className="icon-button"
                        disabled={brackets.length <= 1}
                        onClick={() => onBracketsChange(brackets.filter((_, i) => i !== index))}
                        type="button"
                      >
                        <Icon name="close" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <button
            className="secondary-action"
            disabled={brackets.length >= MAX_BRACKETS}
            onClick={() => onBracketsChange([...brackets, emptyBracket()])}
            type="button"
          >
            {t('productModels.period.scale.addBracket')}
          </button>
        </>
      )}

      <p className={styles.hint}>{t('productModels.period.scale.hint')}</p>
    </div>
  );
}
