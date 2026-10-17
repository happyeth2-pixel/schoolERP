#!/usr/bin/env node
// Merges a native speaker's filled-in review CSV (from export-review-sheets.mjs) back into
// resources/js/lang/<locale>.json. See LOCALIZATION.md.
//
// Run: node scripts/localization/import-review-sheet.mjs <locale> <path-to-filled-csv>
//
// Deliberately does NOT touch resources/js/lang/review-status.json. Whether a namespace is
// now "reviewed" is a judgment call (did the reviewer actually cover every row, or skim it?)
// that a human -- not this script -- has to make explicitly. This is the one guardrail that
// keeps "a native speaker looked at a spreadsheet" from silently turning into "this is a
// verified translation" without anyone deciding that on purpose.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const [, , locale, csvPath] = process.argv;

if (!locale || !csvPath || !['am', 'om', 'ti'].includes(locale)) {
    console.error('Usage: node scripts/localization/import-review-sheet.mjs <am|om|ti> <path-to-filled-csv>');
    process.exit(1);
}

// A small, dependency-free CSV parser -- good enough for the fixed shape
// export-review-sheets.mjs produces (quoted fields, "" escaping, embedded commas/newlines).
function parseCsv(text) {
    const rows = [];
    let row = [];
    let field = '';
    let inQuotes = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (inQuotes) {
            if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
            else if (c === '"') { inQuotes = false; }
            else { field += c; }
        } else if (c === '"') {
            inQuotes = true;
        } else if (c === ',') {
            row.push(field); field = '';
        } else if (c === '\n') {
            row.push(field); field = ''; rows.push(row); row = [];
        } else if (c === '\r') {
            // skip; \r\n is handled by the following \n
        } else {
            field += c;
        }
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    return rows;
}

function setPath(obj, dottedKey, value) {
    const parts = dottedKey.split('.');
    let node = obj;
    for (let i = 0; i < parts.length - 1; i++) {
        node[parts[i]] ??= {};
        node = node[parts[i]];
    }
    node[parts[parts.length - 1]] = value;
}

const csvText = readFileSync(csvPath, 'utf8');
const rows = parseCsv(csvText);
const header = rows.shift();
const col = Object.fromEntries(header.map((name, i) => [name, i]));

for (const required of ['namespace', 'key', 'reviewer_translation']) {
    if (!(required in col)) {
        console.error(`Missing expected column "${required}" -- is this a file from export-review-sheets.mjs?`);
        process.exit(1);
    }
}

const localePath = path.join(ROOT, `resources/js/lang/${locale}.json`);
const data = JSON.parse(readFileSync(localePath, 'utf8'));

let applied = 0;
let skippedBlank = 0;
const touchedNamespaces = new Set();

for (const row of rows) {
    if (row.length < header.length && row.every((f) => f === '')) continue; // trailing blank line
    const namespace = row[col.namespace];
    const key = row[col.key];
    const reviewerTranslation = row[col.reviewer_translation];

    if (!reviewerTranslation || reviewerTranslation.trim() === '') {
        skippedBlank++;
        continue; // blank means "current translation was already fine" -- leave it alone
    }

    setPath(data, `${namespace}.${key}`, reviewerTranslation);
    touchedNamespaces.add(namespace);
    applied++;
}

writeFileSync(localePath, JSON.stringify(data, null, 4) + '\n', 'utf8');

console.log(`Applied ${applied} correction(s) to ${localePath}.`);
console.log(`${skippedBlank} row(s) had no reviewer_translation and were left unchanged.`);
if (touchedNamespaces.size) {
    console.log(`\nNamespaces with at least one correction: ${[...touchedNamespaces].join(', ')}`);
}
console.log('\nThis script did NOT change resources/js/lang/review-status.json.');
console.log('If this reviewer covered EVERY row for a namespace (not just the ones they corrected),');
console.log('update that namespace to "reviewed" for this language yourself -- see LOCALIZATION.md.');
console.log('Then run: npm run test:i18n && npm run build');
