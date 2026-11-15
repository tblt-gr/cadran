import i18n from 'i18next';
import type { Resource } from 'i18next';
import { initReactI18next } from 'react-i18next';

/** Options shared by the application start-up and the Vitest setup. */
export function i18nOptions(fr: Resource[string]) {
  return {
    resources: { fr },
    lng: 'fr',
    fallbackLng: 'fr',
    showSupportNotice: false,
    interpolation: {
      escapeValue: false,
    },
  };
}

/**
 * Loads the dictionary as its own hashed chunk so a changed label does not invalidate the
 * entry chunk. Nothing may render before this resolves.
 */
export async function initI18n(): Promise<void> {
  const { default: fr } = await import('./locales/fr/translation.json');
  await i18n.use(initReactI18next).init(i18nOptions({ translation: fr }));
}

export default i18n;
