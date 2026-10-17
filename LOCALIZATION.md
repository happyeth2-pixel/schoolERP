# Localization — School ERP

**Goal of this document:** be exact about what "supports Amharic/Afaan Oromo/Tigrinya" actually means today, because the honest answer is *partial, and not yet reviewed by any native speaker*. This document is the process for closing that gap safely — stable translation keys, a missing-key safety net, a real reviewer workflow, and a review-status indicator the school owner can actually see — not a claim that the gap is already closed.

**Last updated:** 2026-09-25.

**Who is responsible:** same as `DISASTER_RECOVERY.md`/`MONITORING.md` — sole owner-developer. I am not a native speaker of Amharic, Afaan Oromo, or Tigrinya, and nothing in this document should be read as claiming otherwise. Every non-English, non-pre-existing string in this app was written by an AI assistant (Claude) for functional coverage, not verified for correctness, tone, or naturalness by anyone who actually speaks the language.

---

## The honest starting point

Before this pass, the app had one flat file (`resources/js/i18n.js`) with ~80 keys across 5 screens (sidebar nav, section headings, top bar, login, dashboard), in English/Amharic/Afaan Oromo/Tigrinya. Amharic's content there had been in real use with real users; Afaan Oromo and Tigrinya were already flagged in the project spec as "best effort, needs native-speaker review" — a flag that had never actually been turned into a review process. Every other screen in the app (72 React page/component files, 9 in-app notification types, PDF receipts and report cards, validation errors) was English-only.

This pass:
- Restructured translations into real per-locale JSON/PHP files with stable, namespaced keys (not a rewrite that could silently change meaning without a trace).
- Extracted and translated a **substantial, representative slice** of the app into all four languages — not everything (see [What is NOT covered](#what-is-not-covered-yet) below).
- Built a missing-key safety net so an untranslated key can never silently reach a real user.
- Fixed a real, previously-broken PDF rendering bug (Amharic/Tigrinya text printed as `???`).
- Built the actual reviewer workflow the spec always said was needed, and a way to merge a reviewer's corrections back in.
- Made review status visible to the school owner, per language, in Settings — not just documented here.

---

## Architecture

### Frontend (React/i18next)

| Piece | Location |
|---|---|
| Reference (source) language | `resources/js/lang/en.json` — every key exists here first, with a real value, never blank |
| Translations | `resources/js/lang/{am,om,ti}.json` — same key structure as English |
| Review status, per screen per language | `resources/js/lang/review-status.json` — see [Review status tracking](#review-status-tracking) |
| i18next setup | `resources/js/i18n.js` — `fallbackLng: 'en'`, dev-mode console error on a missing English key |
| Missing-key safety net (frontend) | `tests/js/i18n-parity.mjs` — run via `npm run test:i18n` |

Namespaces so far: `nav`, `section`, `layout`, `auth`, `dashboard` (pre-existing), `common`, `confirm`, `attendance`, `help` (new this pass). Later additions, all `draft` in `review-status.json`: `wizard`, `payments`, `ui`, `status`, `menu` (plain-language menu labels) and `home` (home-page heading and task tiles). Also `enroll` (registration wizard and class cards), `find` (Find a student) and `student` (profile), plus new dialog titles under `confirm`, all `draft`. Phase 4 also extended `attendance` (teacher marking screen, confirmation, class choice; singular/plural `_one`/`_other` keys), all `draft` in am/om/ti. Phase 5 added the `marks` namespace (list, one-student-at-a-time screen, finish and submit wording, locked-state explanations), all `draft`. Phase 6 added the `verify` namespace (payments to check, one-payment review, accept / reject / refund wording, proof viewer), all `draft` in am/om/ti. Phase 7 added the `gen` namespace (the create-bills workflow, its warnings and result), all `draft`. Phase 8 added the `fee` namespace (school fees list and the add / change fee workflow), all `draft` in am/om/ti. Phase 9 added the `parent` namespace (the parent's home, attendance, bill, report card and messages), all `draft`. Phase 10 added the `staff`, `perm` (permission names and descriptions), `sect`, `struct`, `teach`, `audit` (including plain-language sentences for known audit actions) and `settings` namespaces, all `draft` in am/om/ti. Phase 11 added the `promo`, `ann`, `svc`, `adj` (discount and waiver) and `exam` namespaces and extended `student` (detail groups and status dialog), all `draft`. The phone sign-in work added the `signin`, `pwreq` (choose a new password) and `cred` (hand-over of sign-in details) namespaces and extended `staff`, all `draft`; the server-side sign-in messages live in `lang/{en,am,om,ti}/auth.php` (draft in am/om/ti). The account-lifecycle refinement extended `staff` (View, Last signed in, Locked, Reactivate with a new password) and `audit` (a proper "Status changed" sentence, used app-wide), `draft`. The optional parent-account work extended `parents` (the Add a parent account wizard and directory actions), `cred` (a Children row) and `staff` (the Parent role label), all `draft`. The desk payment refinement extended `rec` (the four-line bill breakdown, notes, the repeated-reference confirmation, the recorded-payment confirmation), `draft`. The teacher home / attendance refinement extended `attendance` (class line, short progress, Done, the teacher's home wording), `draft`. The front desk redesign added the `parents` namespace (Find Parent) and extended `home` (greeting, More tile, the receptionist's task titles), `find` (Find Student) and `desk` (the student page's actions), all `draft`. The student photo work added the `photo` namespace (camera / choose / retake / use / remove wording) and extended `enroll` and `student`, all `draft`. The staff-assisted parent service work added the `desk` (Help a parent page) and `rec` (record payment received) namespaces and extended `find`, `parent` and `audit`, all `draft`. The staff account lifecycle work extended `staff` (three-step account form, disable / reactivate) and `cred` (account-created screen, print / copy), all `draft`. The login-protection work added the `secevt` namespace (sign-in security log) and extended `staff` and `audit`, all `draft`; `lang/*/auth.php` gained the generic failure and wait messages. Note: the *English* wording of some sidebar labels was simplified (e.g. "Grades & Sections" → "Classes"); those renamed labels now use draft `menu.*` translations in am/om/ti, while unrenamed labels keep their trusted `nav.*` Amharic. A native review of the sidebar should cover both. 198 keys total, identical across all four locale files (enforced by `test:i18n`).

### Backend (Laravel)

| Piece | Location |
|---|---|
| Locale resolution per request | `App\Http\Middleware\SetLocale` (in the web middleware stack, `bootstrap/app.php`) — reads the user's `locale` column, falls back to the school's first `enabled_languages` entry, falls back to English |
| Reference + translations | `lang/en/*.php`, `lang/{am,om,ti}/*.php` (`notifications.php`, `attendance.php` so far) |
| Recipient-correct notification locale | `App\Support\Localization\NotificationLocale` — see below |
| Missing-key safety net (backend) | `tests/Unit/NotificationLocalizationTest.php` |

**A real bug this pass found and fixed, not just translated around:** a Notification's `toArray()`/`toMail()` runs in the *current* request — the person doing the action (a staff member marking attendance) — not a queued job for the recipient. Using `app()->getLocale()` inside a notification would show it in the *actor's* language, not the *recipient's*, which is simply wrong the moment they differ (an Amharic-speaking staff member marks a student absent; the guardian reads Afaan Oromo). `NotificationLocale::for($notifiable)` resolves the recipient's own locale explicitly (their `locale` column, or their school's first enabled language, or English), and all 9 notification classes use it instead of the ambient app locale. This is exactly the class of bug "just translate the strings" would never catch — it's a wiring problem, not a wording problem.

### `app()->setLocale()` wiring

`SetLocale` middleware runs early in the web stack and sets the backend locale once per request; `HandleInertiaRequests` reuses `app()->getLocale()` for the `locale` prop it already shared with the frontend, so the two can never disagree.

---

## Review status tracking

`resources/js/lang/review-status.json` tracks status **per screen/namespace, per language** — not per individual key (123 individual flags would be unmaintainable; a screen is also the unit a reviewer actually works through). Three states:

- **`trusted`** — in real production use, presumed correct. Only Amharic's original 5 namespaces (`nav`, `section`, `layout`, `auth`, `dashboard`) qualify, because only those predate this pass and have had real users looking at them.
- **`draft`** — functional, written for coverage, **not checked by a native speaker**. This is every Afaan Oromo and Tigrinya namespace (all of them, including the pre-existing ones — the spec always said they needed review, and nothing has changed that), plus every *new* Amharic namespace (`common`, `confirm`, `attendance`) — new Amharic content does not inherit the trust the *old* Amharic content earned from real use.
- **`reviewed`** — a named native speaker actually checked it. **Nothing is at this status yet.**

This file is not just documentation — `resources/js/Pages/Settings/School.jsx`'s language picker reads it live and shows the owner an honest, current sentence per language (e.g. *"5 of 8 screens are established or native-checked; the other 3 are early translations pending native-speaker review"*), so turning on a language is an informed choice, not a guess. Nothing here can go stale silently: the message is computed from the same file a reviewer's sign-off would update.

---

## The reviewer workflow

1. **Export a review sheet per language:**
   ```
   node scripts/localization/export-review-sheets.mjs
   ```
   Writes `storage/app/localization-review/review-{am,om,ti}.csv` (gitignored, regenerable). Each row has: `namespace`, `key`, `screen_context` (a real sentence, e.g. *"Taking attendance (daily roster) and the attendance report screen"*), `string_type` (button / confirmation dialog / status label / table header / text), `english`, `current_translation`, `review_status`, and two blank columns — `reviewer_translation`, `reviewer_notes` — for the reviewer to fill in. Opens fine in Excel, Google Sheets, or LibreOffice; Ge'ez and Latin script both render correctly (UTF-8).

2. **Hand the file to an actual native speaker** of that language — an Oromo speaker reviews the Oromo file, a Tigrinya speaker the Tigrinya file, an Amharic speaker the Amharic file (the `common`/`confirm`/`attendance` rows at minimum; the pre-existing `nav`/`section`/`layout`/`auth`/`dashboard` rows are already trusted but a reviewer is welcome to check them too). They leave `reviewer_translation` blank where the current text is already correct, and fill it in where it's wrong, awkward, or too literal — with the `screen_context`/`string_type` columns telling them whether they're looking at a button, an error, or a heading, since the same phrase can need different wording for each.

3. **Merge their corrections back:**
   ```
   node scripts/localization/import-review-sheet.mjs <am|om|ti> <path-to-filled-csv>
   ```
   Applies every non-blank `reviewer_translation` to `resources/js/lang/<locale>.json`. Blank rows are left untouched (blank means "already correct," not "delete this"). Round-trip tested for real: exported a sheet, edited a row, imported it back, confirmed the JSON updated and nothing else moved.

4. **Deciding "reviewed" is a human judgment call, on purpose.** The import script deliberately never touches `review-status.json` — whether a reviewer actually covered every row of a namespace (versus skimming a few) isn't something a script can know. After a real review, update `review-status.json` yourself for the namespaces that reviewer actually covered, then run `npm run test:i18n && npm run build`.

**What this workflow has NOT yet done:** no native speaker has been given these files. `review-status.json` has zero `reviewed` entries. This document describes a working process, not a completed review.

---

## PDF rendering (fonts fixed, content not yet localized)

dompdf shipped no font with Ethiopic (Ge'ez) glyphs — Amharic and Tigrinya text in generated PDFs rendered as literal `???`. Confirmed by actually generating a PDF with real Amharic/Tigrinya text and reading the output, not assumed. Fixed by registering **Noto Sans Ethiopic** (Google's free/open Ethiopic typeface, committed at `resources/fonts/`) with dompdf via `php artisan pdf:install-fonts` (run on every deploy, see `Dockerfile`), and setting `font-family: 'DejaVu Sans', 'Noto Sans Ethiopic', sans-serif` in both PDF views. Confirmed dompdf's per-glyph font-family fallback actually works (not assumed) by generating a test PDF with mixed Amharic+Latin+numeral text on one line and visually confirming both scripts render correctly together.

**What's NOT done:** `resources/views/receipt/pdf.blade.php` and `resources/views/reportcard/pdf.blade.php` still have hardcoded English *text*. The font can now display Ethiopic glyphs; nothing in those two views actually asks for a translated string yet.

---

## What is NOT covered yet

Being exact, not rounding up:

- **No native-speaker review has happened.** Every Afaan Oromo/Tigrinya string, and every *new* Amharic string, is an AI-assisted draft. See [Review status tracking](#review-status-tracking).
- **Most of the app's ~72 page/component files are still English-only.** This pass fully wired: `SearchInput`, `AttendanceStatusBadge`, `Attendance/Take.jsx`, `Attendance/Report.jsx`, `Help/Index.jsx` (every role-conditional section, diagram label, and step list), the Settings language picker's review-status note, and all 16 `window.confirm()` destructive-action dialogs across 12 admin/settings screens. It did **not** touch: invoice screens' own body text, payment screens, complaint screens, announcement screens, report card screens, student/staff CRUD forms, or most buttons/labels outside the `confirm` namespace — these still have hardcoded English JSX text.
- **Notifications are fully localized** (all 9 classes, both `database` and `mail` channels) — this was the one category taken to completion, because it was explicitly named and bounded.
- **Backend validation messages are not localized.** `lang/{am,om,ti}/validation.php` do not exist; Laravel's stock validation errors are English-only regardless of the user's chosen locale.
- **PDF content is English-only** (fonts are fixed; see above).
- **Text expansion has not been tested.** Amharic/Tigrinya/Oromo phrases often run longer than their English source (the `confirm.teaching_assignments_replace_teacher` dialog is a good stress case) — no pass has been made checking that longer translated strings don't break button widths, table columns, or modal layouts.
- **Mixed-language names and numbers** have only been checked for the one PDF font-rendering test (Amharic/Tigrinya + Latin + digits on one line, in a PDF). Broader testing — Ethiopian names entered in Ge'ez vs. Latin script, number formatting consistency across locales — has not been done.

None of this is hidden in the code: every draft string, every unwired screen, is exactly what it looks like — functional English-language coverage with best-effort machine-assisted translations layered on top of the four screens that were already trusted, ready for the review process above to actually happen.

## Missing-key safety net

Two independent checks, matching the two places a key can be missing:

- `npm run test:i18n` (`tests/js/i18n-parity.mjs`) — every key in `en.json` must exist in `am.json`/`om.json`/`ti.json`, and vice versa (catches a stale key left behind after English changed, not just a missing one).
- `php artisan test --filter=NotificationLocalizationTest` — same check for `lang/{locale}/notifications.php` and `attendance.php`, plus unit tests proving `NotificationLocale` actually resolves the recipient's language, not the ambient one.

In production, a genuinely missing key still falls back to English rather than showing a raw key name to a parent or teacher (`fallbackLng: 'en'` in `i18n.js`) — but that fallback is a deliberate last resort, not a substitute for these two tests passing in CI/before every deploy.
