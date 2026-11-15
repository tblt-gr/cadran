import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { i18nOptions } from './i18n';
import fr from './locales/fr/translation.json';

// Specs need the dictionary synchronously, unlike the application which loads it as a chunk.
void i18n.use(initReactI18next).init(i18nOptions({ translation: fr }));
