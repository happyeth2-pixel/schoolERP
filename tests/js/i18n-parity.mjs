// The safety net for "don't allow missing translation keys to silently appear in
// production" (see LOCALIZATION.md): fails the build the moment a key exists in English
// (the reference language -- see resources/js/i18n.js) but not in one of the other three
// locale files, or vice versa (a stale/renamed key left behind in a translation file after
// English moved on). Run: npm run test:i18n
import { readFileSync } from 'node:fs';

const LOCALES = ['am', 'om', 'ti'];

function loadJson(locale) {
    const url = new URL(`../../resources/js/lang/${locale}.json`, import.meta.url);
    return JSON.parse(readFileSync(url, 'utf8'));
}

// Flattens { a: { b: 'x', c: { d: 'y' } } } to ['a.b', 'a.c.d'] so a namespace nested
// arbitrarily deep is still checked key-by-key, not just at the top level.
function flattenKeys(obj, prefix = '') {
    let keys = [];
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            keys = keys.concat(flattenKeys(value, path));
        } else {
            keys.push(path);
        }
    }
    return keys;
}

const en = loadJson('en');
const enKeys = new Set(flattenKeys(en));

let failures = 0;
const fail = (msg) => {
    failures++;
    console.error('FAIL', msg);
};

for (const locale of LOCALES) {
    const data = loadJson(locale);
    const localeKeys = new Set(flattenKeys(data));

    for (const key of enKeys) {
        if (!localeKeys.has(key)) {
            fail(`${locale}.json is missing key "${key}" (present in en.json)`);
        }
    }
    for (const key of localeKeys) {
        if (!enKeys.has(key)) {
            fail(`${locale}.json has key "${key}" that no longer exists in en.json -- a stale leftover, or English needs updating too`);
        }
    }
}

if (failures) {
    console.error(`\n${failures} i18n key mismatch(es). Every locale must have exactly the keys English has (see resources/js/i18n.js and LOCALIZATION.md) -- a missing key falls back to English silently in production, which is by design, but it must never happen by ACCIDENT.`);
    process.exit(1);
}

console.log(`i18n parity OK: en/am/om/ti all define the same ${enKeys.size} keys.`);
