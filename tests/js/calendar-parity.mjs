// Checks resources/js/calendar.js against the fixture generated from PHP, so the
// browser and the server can never disagree about a date. Run: npm run test:calendar
import { readFileSync } from 'node:fs';
import { ethiopianToIso, formatDate, isoToEthiopian } from '../../resources/js/calendar.js';

const { rows } = JSON.parse(readFileSync(new URL('../fixtures/calendar-conversions.json', import.meta.url), 'utf8'));
let failures = 0;
const fail = (msg) => { failures++; if (failures <= 15) console.error('FAIL', msg); };

for (const [iso, y, m, d, both, ethAm, greg] of rows) {
    const e = isoToEthiopian(iso);
    if (!e || e.year !== y || e.month !== m || e.day !== d) fail(`${iso} -> ${JSON.stringify(e)} expected ${y}-${m}-${d}`);
    if (ethiopianToIso(y, m, d) !== iso) fail(`${y}-${m}-${d} -> ${ethiopianToIso(y, m, d)} expected ${iso}`);
    if (formatDate(iso, 'both', 'en') !== both) fail(`both/en ${iso}: "${formatDate(iso, 'both', 'en')}" vs "${both}"`);
    if (formatDate(iso, 'ethiopian', 'am') !== ethAm) fail(`ethiopian/am ${iso}`);
    if (formatDate(iso, 'gregorian', 'en') !== greg) fail(`gregorian ${iso}`);
}

// invalid input is refused rather than guessed
const refusals = [ethiopianToIso(2018, 13, 6), ethiopianToIso(2018, 14, 1), ethiopianToIso(2018, 1, 31), isoToEthiopian('2026-02-30'), isoToEthiopian('nope')];
if (refusals.some((r) => r !== null)) fail(`invalid dates were not refused: ${JSON.stringify(refusals)}`);
if (ethiopianToIso(2019, 13, 6) === null) fail('valid leap Pagume 6 refused');

if (failures) { console.error(`\n${failures} mismatch(es) between JS and PHP`); process.exit(1); }
console.log(`calendar parity OK: ${rows.length} dates match PHP exactly (conversion both ways + 3 display formats).`);
