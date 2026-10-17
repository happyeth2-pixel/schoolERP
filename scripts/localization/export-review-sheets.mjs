#!/usr/bin/env node
// Builds one spreadsheet-ready CSV per non-English language, for handing to an actual
// native speaker to review -- see LOCALIZATION.md. Never claims anything is reviewed by
// itself: every row's "review_status" column comes straight from
// resources/js/lang/review-status.json (drafts stay "draft" until a human flips that file,
// which only happens after a real review -- see import-review-sheet.mjs).
//
// Run: node scripts/localization/export-review-sheets.mjs
// Output: storage/app/localization-review/review-<locale>.csv (one per am/om/ti)
//
// Columns: namespace, key, screen_context, string_type, english, current_translation,
// review_status, reviewer_translation (blank -- theirs to fill in), reviewer_notes (blank).
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const LOCALES = ['am', 'om', 'ti'];
const LOCALE_NAMES = { am: 'Amharic', om: 'Afaan Oromo', ti: 'Tigrinya' };

// One sentence of real screen context per namespace -- "give reviewers the actual
// screen/context" (see the original request in LOCALIZATION.md). Update this whenever a
// new namespace is added to resources/js/lang/en.json.
const NAMESPACE_CONTEXT = {
    nav: 'Sidebar navigation menu -- one label per page link.',
    section: 'Sidebar section group headings (groups of nav links).',
    layout: 'Top bar: site search placeholder, profile menu, language switcher.',
    auth: 'Login screen: form field labels and the log-in button.',
    dashboard: 'Dashboard home screen: welcome message, stat cards, quick links.',
    common: 'Generic building blocks reused across many screens (buttons, search box, empty/loading states).',
    confirm: 'Confirmation pop-up (browser confirm() dialog) shown before a destructive or important action -- the key name says which screen/action it is for.',
    attendance: 'Taking attendance (daily roster) and the attendance report screen.',
};

function typeOf(namespace, key) {
    if (namespace === 'confirm') return 'confirmation dialog';
    if (key.startsWith('status_')) return 'status label (short, shown in a colored badge)';
    if (key.startsWith('progress_')) return 'status label (short, shown in a colored badge)';
    if (key.includes('header') || key.startsWith('col_') || key.startsWith('stat_')) return 'table column header / stat label';
    if (key.includes('placeholder')) return 'input placeholder';
    if (['save', 'cancel', 'edit', 'remove', 'add', 'close', 'back', 'yes', 'no'].includes(key)) return 'button label';
    if (key === 'page_header' || key.startsWith('report_title')) return 'page heading';
    return 'text';
}

function flatten(obj, prefix = '') {
    let rows = [];
    for (const [k, v] of Object.entries(obj)) {
        const full = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) {
            rows = rows.concat(flatten(v, full));
        } else {
            rows.push([full, v]);
        }
    }
    return rows;
}

function csvField(value) {
    const s = String(value ?? '');
    if (/[",\n]/.test(s)) {
        return `"${s.replace(/"/g, '""')}"`;
    }
    return s;
}

const en = JSON.parse(readFileSync(path.join(ROOT, 'resources/js/lang/en.json'), 'utf8'));
const enRows = flatten(en); // [ [ "nav.dashboard", "Dashboard" ], ... ]
const reviewStatus = JSON.parse(readFileSync(path.join(ROOT, 'resources/js/lang/review-status.json'), 'utf8'));

const outDir = path.join(ROOT, 'storage/app/localization-review');
mkdirSync(outDir, { recursive: true });

for (const locale of LOCALES) {
    const data = JSON.parse(readFileSync(path.join(ROOT, `resources/js/lang/${locale}.json`), 'utf8'));
    const dataFlat = Object.fromEntries(flatten(data));
    const statusMap = reviewStatus[locale] ?? {};

    const header = ['namespace', 'key', 'screen_context', 'string_type', 'english', 'current_translation', 'review_status', 'reviewer_translation', 'reviewer_notes'];
    const lines = [header.map(csvField).join(',')];

    for (const [fullKey, englishValue] of enRows) {
        const namespace = fullKey.split('.')[0];
        const key = fullKey.slice(namespace.length + 1);
        const current = dataFlat[fullKey] ?? '(MISSING -- not translated at all yet)';
        const status = statusMap[namespace] ?? 'draft';

        lines.push([
            namespace,
            key,
            NAMESPACE_CONTEXT[namespace] ?? '(no context written yet -- ask the developer)',
            typeOf(namespace, key),
            englishValue,
            current,
            status,
            '', // reviewer_translation -- theirs to fill in; leave blank to keep current_translation
            '', // reviewer_notes
        ].map(csvField).join(','));
    }

    const outPath = path.join(outDir, `review-${locale}.csv`);
    writeFileSync(outPath, lines.join('\n') + '\n', 'utf8');
    console.log(`Wrote ${outPath} (${enRows.length} rows, ${LOCALE_NAMES[locale]})`);
}

console.log('\nHand each file to a native speaker of that language. They should:');
console.log('  1. Open it in Excel/Google Sheets/LibreOffice (UTF-8; Ge\'ez and Latin script both render fine).');
console.log('  2. Read "screen_context" and "string_type" before judging a translation -- the same phrase can need');
console.log('     different wording as a button vs. an error vs. a heading.');
console.log('  3. Leave "reviewer_translation" BLANK if "current_translation" is already correct.');
console.log('  4. Fill in "reviewer_translation" with corrected text if it is wrong, awkward, or too literal.');
console.log('  5. Use "reviewer_notes" for anything that needs the developer\'s attention (e.g. "this needs a');
console.log('     different word depending on the student\'s gender, English doesn\'t distinguish").');
console.log('\nThen merge their edits back with: node scripts/localization/import-review-sheet.mjs <locale> <path-to-filled-csv>');
