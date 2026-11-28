import { useTranslation } from 'react-i18next';

const BUILT_IN_VERSION = 1;

/**
 * Names a metric policy: the built-in version is translated, a stored version keeps its own label,
 * and a version without a label is one whose definition could not be loaded. Null when no single
 * version applies.
 */
export function useMetricPolicyLabel(): (policy: {
  version: number | null;
  label?: string | null;
}) => string | null {
  const { t } = useTranslation();

  return ({ version, label }) => {
    if (version === BUILT_IN_VERSION) {
      return t('metricPolicy.builtInLabel');
    }
    if (label) {
      return label;
    }
    return version === null ? null : t('metricPolicy.unknownLabel');
  };
}
