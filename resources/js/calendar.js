// Gregorian <-> Ethiopian date conversion for the browser.
//
// This is a line-for-line mirror of app/Services/EthiopianCalendar.php (PHP). The
// two are kept in step by tests/fixtures/calendar-conversions.json: a PHP test
// checks the fixture against PHP, and `npm run test:calendar` checks the same
// fixture against this file.
//
// Dates are ALWAYS stored and sent to the server as Gregorian 'YYYY-MM-DD'.
// Ethiopian dates exist only on screen: this file converts at the edges.

const EPOCH_JDN = 1724221;

export const MONTHS = {
    en: ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'],
    am: ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜን'],
    ti: ['መስከረም', 'ጥቅምቲ', 'ሕዳር', 'ታሕሳስ', 'ጥሪ', 'ለካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰነ', 'ሓምለ', 'ነሓሰ', 'ጳጉሜን'],
    om: ['Fuulbana', 'Onkoloolessa', 'Sadaasa', 'Muddee', 'Amajjii', 'Guraandhala', 'Bitootessa', 'Ebla', 'Caamsaa', 'Waxabajjii', 'Adoolessa', 'Hagayya', 'Qaammee'],
};

const GREGORIAN_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const div = (a, b) => Math.floor(a / b);
const pad = (n, w) => String(n).padStart(w, '0');

export function isLeapYear(year) {
    return year % 4 === 3;
}

export function daysInMonth(year, month) {
    return month === 13 ? (isLeapYear(year) ? 6 : 5) : 30;
}

export function isValidEthiopian(year, month, day) {
    return year >= 1 && month >= 1 && month <= 13 && day >= 1 && day <= daysInMonth(year, month);
}

function isValidGregorian(y, m, d) {
    const date = new Date(Date.UTC(y, m - 1, d));
    return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}

function gregorianToJdn(year, month, day) {
    const a = div(14 - month, 12);
    const y = year + 4800 - a;
    const m = month + 12 * a - 3;
    return day + div(153 * m + 2, 5) + 365 * y + div(y, 4) - div(y, 100) + div(y, 400) - 32045;
}

function jdnToGregorian(jdn) {
    const a = jdn + 32044;
    const b = div(4 * a + 3, 146097);
    const c = a - div(146097 * b, 4);
    const d = div(4 * c + 3, 1461);
    const e = c - div(1461 * d, 4);
    const m = div(5 * e + 2, 153);
    return {
        day: e - div(153 * m + 2, 5) + 1,
        month: m + 3 - 12 * div(m, 10),
        year: 100 * b + d - 4800 + div(m, 10),
    };
}

/** 'YYYY-MM-DD' (Gregorian) -> { year, month, day } (Ethiopian). Returns null for an invalid date. */
export function isoToEthiopian(iso) {
    const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
    if (!y || !m || !d || !isValidGregorian(y, m, d)) return null;

    const days = gregorianToJdn(y, m, d) - (EPOCH_JDN - 365);
    const r = days % 1461;
    const n = (r % 365) + 365 * div(r, 1460);

    return { year: 4 * div(days, 1461) + div(r, 365) - div(r, 1460), month: div(n, 30) + 1, day: (n % 30) + 1 };
}

/** Ethiopian y/m/d -> 'YYYY-MM-DD' (Gregorian), the form that is stored. Returns null if invalid. */
export function ethiopianToIso(year, month, day) {
    if (!isValidEthiopian(year, month, day)) return null;

    const jdn = EPOCH_JDN + 365 * (year - 1) + div(year, 4) + 30 * (month - 1) + (day - 1);
    const g = jdnToGregorian(jdn);

    return `${pad(g.year, 4)}-${pad(g.month, 2)}-${pad(g.day, 2)}`;
}

export function monthName(month, locale = 'en') {
    return (MONTHS[locale] ?? MONTHS.en)[month - 1];
}

function gregorianText(iso) {
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
    return `${GREGORIAN_SHORT[m - 1]} ${d}, ${y}`;
}

/** Same output as PHP SchoolCalendar::date(): "1 Meskerem 2018", "Sep 11, 2025" or both. */
export function formatDate(iso, preference = 'both', locale = 'en') {
    if (!iso) return '';
    const e = isoToEthiopian(iso);
    if (!e) return String(iso);

    const ethiopian = `${e.day} ${monthName(e.month, locale)} ${e.year}`;
    const gregorian = gregorianText(String(iso));

    if (preference === 'gregorian') return gregorian;
    if (preference === 'ethiopian') return ethiopian;
    return `${ethiopian} (${gregorian})`;
}

/** Today's date for a school day, in Addis Ababa time (not the browser's or UTC's). */
export function todayISO() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Addis_Ababa', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}
