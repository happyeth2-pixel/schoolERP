import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import am from './lang/am.json';
import en from './lang/en.json';
import om from './lang/om.json';
import ti from './lang/ti.json';

// English is the source/reference language: every key that exists anywhere exists here first,
// with a real value -- never blank, never a raw key name. See LOCALIZATION.md for the full
// process (how strings get here, how they get reviewed, coverage by area).
//
// Amharic, Afaan Oromo and Tigrinya are NOT all held to the same trust level:
//   - Amharic's original (nav/dashboard/login) content has been in real use and is trusted.
//   - Everything else -- every language, including new Amharic content -- is DRAFT: written for
//     functional coverage, not verified by a native speaker. reviewStatus.json records this
//     key-by-key so it is never silently presented as more finished than it is (see
//     resources/js/reviewStatus.js and the School Settings language picker, which shows it).
//
// A school only sees Afaan Oromo/Tigrinya as choices at all if its Owner explicitly turns them on
// in School Settings, which says so plainly.
const resources = {
    en: { translation: en },
    am: { translation: am },
    om: { translation: om },
    ti: { translation: ti },
};

// Fails loudly in dev (console.error, impossible to miss) the moment a component asks for a key
// that does not exist in English -- the one thing that must never happen, since English is the
// reference every other language is checked against (see tests/js/i18n-parity.mjs, which checks
// the other direction: every English key exists, in some form, in every other language file).
// In production this still falls back to English silently rather than showing a raw key to a
// parent or teacher -- "missing key" is a build-time/test-time failure (see package.json's
// test:i18n), never a thing a real user is meant to ever see.
function warnOnMissingKey(locales, namespace, key) {
    if (import.meta.env.DEV && locales.includes('en')) {
        // eslint-disable-next-line no-console
        console.error(`[i18n] Missing English key: "${key}" -- add it to resources/js/lang/en.json.`);
    }
}

i18n.use(initReactI18next).init({
    resources,
    lng: 'en',
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
    saveMissing: true,
    missingKeyHandler: warnOnMissingKey,
});

export default i18n;
