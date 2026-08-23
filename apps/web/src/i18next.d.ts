import 'i18next';
import fr from './locales/fr/translation.json';

declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'translation';
    resources: {
      translation: typeof fr;
    };
  }
}
