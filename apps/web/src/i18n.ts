import i18n from 'i18next';
import type { Resource } from 'i18next';
import { initReactI18next } from 'react-i18next';

const LANGUAGES = ['fr', 'en'] as const;
export type Language = (typeof LANGUAGES)[number];
export const DEFAULT_LANGUAGE: Language = 'fr';

const STORAGE_KEY = 'cadran.language';

export function isLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (LANGUAGES as readonly string[]).includes(value);
}

/** Stored choice, then the browser language when English, then French. Storage may be blocked. */
export function initialLanguage(): Language {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (isLanguage(stored)) return stored;
  } catch {
    // Private window or blocked site data: fall through to the browser language.
  }
  return navigator.language.toLowerCase().startsWith('en') ? 'en' : DEFAULT_LANGUAGE;
}

function rememberLanguage(language: Language): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, language);
  } catch {
    // The mirror is a convenience only.
  }
}

/** Each dictionary is its own hashed chunk; only the active one is requested. */
async function loadDictionary(language: Language): Promise<Resource[string]> {
  const { default: translation } =
    language === 'en'
      ? await import('./locales/en/translation.json')
      : await import('./locales/fr/translation.json');
  return { translation };
}

/** Options shared by the application start-up and the Vitest setup. */
export function i18nOptions(resource: Resource[string], language: Language = DEFAULT_LANGUAGE) {
  return {
    resources: { [language]: resource },
    lng: language,
    fallbackLng: DEFAULT_LANGUAGE,
    interpolation: {
      escapeValue: false,
    },
  };
}

/**
 * Loads the active dictionary as its own hashed chunk so a changed label does not invalidate
 * the entry chunk. Nothing may render before this resolves.
 */
export async function initI18n(): Promise<void> {
  const language = initialLanguage();
  document.documentElement.lang = language;
  await i18n.use(initReactI18next).init(i18nOptions(await loadDictionary(language), language));
}

let latestRequest = 0;

/** Switches the whole interface at once, without reload, and remembers the choice. */
export async function setLanguage(language: Language): Promise<void> {
  const request = ++latestRequest;
  if (!i18n.hasResourceBundle(language, 'translation')) {
    const { translation } = await loadDictionary(language);
    i18n.addResourceBundle(language, 'translation', translation);
  }
  // A quicker, later choice already won: do not overwrite it with this older one.
  if (request !== latestRequest) return;
  await i18n.changeLanguage(language);
  document.documentElement.lang = language;
  rememberLanguage(language);
}

export default i18n;
