# School ERP — Complete Technical & Functional Specification

**As of 2026-10-16.** This file is the single, complete source of truth for the project. Sections 1–19 and 31–35 describe the "core" system (people, academics, finance, attendance, exams/marks, audit, complaints, uploads, announcements, parent portal, ID cards, i18n, dashboards, security, testing, codebase map, changelog) as it stood in late September. Sections 20–30 document eleven whole modules — Human Resources & Payroll, Inventory & Assets, Procurement & Suppliers, a double-entry Accounting engine, Reports, Library, Timetable/Periods/Rooms, Period Attendance, School Calendar events, Student Discipline & Medical Records, and Security Log/Student Desk/Assignments — built since then. This edition additionally folds in a full production-hardening pass completed 2026-10-16 (audit against a 33-point readiness checklist): real bugs found and fixed (a cross-timezone greeting bug, two un-transacted multi-row attendance writes, a branch-deletion cascade chain that would have silently deleted unrelated inventory/asset/calendar records, a dead scheduler task), new small features added where a genuine, previously undocumented gap was found (a supplier-payment reversal workflow, a library overdue-loan reminder, a dashboard Procurement block, a DB-level SKU/barcode uniqueness guarantee, a supplier link on Assets), and a handful of corrections to earlier drafts of this very document. See `PRODUCTION_READINESS_REPORT.md` for the full session-by-session account of what was tested, fixed, verified-but-not-fixed, or flagged as requiring real-world (non-code) verification. Written for Ethiopian K-12 schools; multi-branch, multi-role, real testers already using it.

---

## Table of contents

1. Overview & Purpose
2. Tech Stack & Environment
3. Actors, Roles & Permissions
4. Full Database Schema (every table)
5. Full Route Surface
6. People, Organization & Academic Structure
7. Academics — Attendance, Exams, Marks, Report Cards
8. Finance — Fees, Invoices, Payments, Refunds, Discounts
9. Calendar, Age, Names & Phone Numbers
10. Enrollment Safety — Promotion & Section Capacity
11. Data Integrity — Deletion Rules, Year Switching, Term Dates
12. Audit Trail
13. Complaints & Their Privacy Model
14. File Uploads & Content Security
15. Communication — Announcements & Notifications
16. Family (Parent) Portal & the Student-Login Decision
17. Identity — ID Cards
18. Multi-language
19. Dashboards
20. Human Resources & Payroll
21. Inventory & Assets
22. Procurement & Suppliers
23. Accounting Engine (Double-Entry Ledger)
24. Financial & Operational Reports
25. Library
26. Timetable, Periods & Rooms
27. Period Attendance
28. School Calendar (Events)
29. Student Discipline & Medical Records
30. Security Log, Student Desk & Assignments/Homework
31. Security & Privacy — Consolidated View
32. Automated Testing
33. Known Gaps / Honest Limitations
34. Codebase Map (every file, by purpose)
35. Architecture-Review Changelog

---

## 1. Overview & Purpose

A School ERP for Ethiopian K-12 schools, built as the sole product for one owner-developer working with real school testers already on the system. It runs one school per install (multi-**branch** within that school — e.g. a main campus plus satellite campuses — not multi-tenant across unrelated schools), and covers the full academic, financial, operational, and back-office cycle:

- Enrolling and tracking students through their whole life at the school (admission → promotion each year → graduation, or withdrawal/transfer along the way).
- Daily operations: attendance (daily homeroom and now per-period), marks/results with an approval workflow, report cards, homework/assignments, a weekly timetable, a school calendar.
- Money: fee structures, generated invoices, manual bank/mobile-money payments verified by staff, discounts, waivers, refunds — plus, now, a full double-entry accounting engine, supplier procurement, and payroll.
- People: staff accounts with fine-grained permissions, parent accounts linked to their children, an audit trail of who changed what, plus a full HR module (employees, contracts, staff attendance, leave, performance reviews, payroll) separate from login/permission management.
- Back office: inventory & consumable stock, fixed assets, a supplier procurement pipeline (request → quotation → purchase order → goods receipt → bill → payment), a library, student discipline records, student medical records.
- Communication: complaints/feedback, announcements, in-app notifications, calendar events.
- Identity: printable/QR-verifiable student ID cards.
- Ethiopian context throughout: Ethiopian names (patronymic, not first/last), the Ethiopian calendar as a display/billing option, Ethiopian phone number formats, ETB currency, Ethiopian payroll tax/pension schedules, Amharic/Afaan Oromo/Tigrinya alongside English.

The system is deliberately **not** a generic multi-tenant SaaS with public self-signup: every account is provisioned by an admin or created automatically when a student is enrolled (their guardian). There is no public registration page.

---

## 2. Tech Stack & Environment

**Backend:** Laravel 12 (PHP), MySQL in production (Render-hosted) and locally (XAMPP MySQL / MariaDB), SQLite in-memory for the automated test suite. Key packages: `inertiajs/inertia-laravel` (server-driven SPA, no separate JSON API except one lookup endpoint), `spatie/laravel-permission` (roles/permissions), `barryvdh/laravel-dompdf` (report card & receipt PDFs), `maatwebsite/excel` (every "Export to Excel" button), `laravel/sanctum` (session auth), `tightenco/ziggy` (Laravel named routes usable from JS).

**Frontend:** React 18 + Inertia.js v2 (pages are plain React components rendered server-side-routed, no client router), Vite 7 build, Tailwind CSS, `i18next`/`react-i18next` for the four languages, `@heroicons/react` for icons.

**Testing:** PHPUnit/Laravel feature & unit tests on an in-memory SQLite database, a small Node script (`tests/js/calendar-parity.mjs`, run via `npm run test:calendar`) that checks the PHP and JS Ethiopian-calendar implementations agree on 4,372 dates, and ad-hoc Playwright (Python) browser checks under `tests/e2e/` used during development — now covering every new module too (see §32).

**Storage:** two local disks — `public` (served at `/storage`, for photos/logos anyone with the URL can view) and `local`/private (`storage/app/private`, for payment proofs, contract/leave/discipline documents, etc.; served only through an authorized controller action, never a direct URL).

**Timezones & dates:** the application timezone is UTC; all `DATE`/`DATETIME` columns are stored in UTC/Gregorian, always. A separate "business timezone" (`Africa/Addis_Ababa`, `config('app.business_timezone')`) decides what "today" means for attendance, withdrawal deadlines, enrollment defaults, dashboards and audit-log day filters. See section 9 for the full calendar model.

**Mail:** `MAIL_MAILER=log` in this deployment — no real email is sent; instead, one-time invitation links and similar are shown directly to the staff member who triggers them, to hand over in person or via WhatsApp/SMS. Every notification in every module (including all eleven new ones) is **database/in-app only** — no module adds an email/SMS channel.

**HTTPS:** `config('app.force_https')` / `FORCE_HTTPS` env var (defaults to true only when `APP_ENV=production`) makes `EnforceHttps` middleware 301-redirect HTTP→HTTPS and send HSTS; Render terminates TLS at its edge and forwards plain HTTP, so `trustProxies(at: '*', …)` is configured in `bootstrap/app.php` so Laravel still generates `https://` URLs.

**Backups & disaster recovery:** the production database and every uploaded file are backed up daily — encrypted, uploaded off-server via `rclone` to an S3-compatible bucket, and retained on a 14-day/60-day/400-day daily/weekly/monthly rotation. Full detail in **`DISASTER_RECOVERY.md`**.

**Task scheduling:** Laravel's own scheduler (`routes/console.php`, `Schedule::command()`) registers every recurring job — attendance-correction reminders, contract-expiry checks (§20.4), overdue-library-loan reminders (§25.6), etc. — but registering a task there only means it fires *if* something invokes `php artisan schedule:run` every minute; in production that invocation comes from a dedicated **`schoolerp-scheduler` Render Cron Job**, documented alongside the backup jobs in `DISASTER_RECOVERY.md`. Backup/verify commands (`backup:run`, `backup:verify`) are deliberately **not** registered in `routes/console.php` — they run only via their own direct Render Cron Job entries, so a scheduler misconfiguration can never silently also break backups. A 2026-10-16 audit found four real application tasks that were implemented as commands but never actually registered anywhere (so they only ever ran if someone invoked them by hand) — all four are now registered; see §20.4/§25.6 for which ones.

**Monitoring & error reporting:** Sentry for backend and frontend exceptions, with unconditional PII/payment/auth scrubbing and a pseudonymous actor tag. Full detail in **`MONITORING.md`**.

**Usability testing with real users:** a documented protocol in **`USABILITY_TESTING.md`**; findings (if any) in **`UAT.md`**.

**UX for low-literacy users:** a phase-by-phase redesign toward guided, one-decision-at-a-time flows. Full detail in **`UX_GUIDELINES.md`**. The same wizard/dialog/confirm patterns (`WizardShell`/`useWizard`/`ReviewList`, `useConfirm`, `ChoiceGroup`) are reused consistently across every new module documented in §20–§30 — Requisitions, Purchase Requests, and Employee onboarding all use the shared `WizardShell`; every destructive action across every new module (remove account, remove copy, cancel PO, reject request) goes through the shared `useConfirm()` dialog, never a native `window.confirm`.

---

## 3. Actors, Roles & Permissions

**7 real actor tiers** (`RolesAndPermissionsSeeder::ROLES`): `owner`, `branch_admin`, `teacher`, `accountant`, `parent`, `student`, `auditor`. Job titles a school actually uses (Vice Principal, Registrar, Librarian, Storekeeper, Procurement Officer, HR Officer, Accountant, Dean of Students, …) are **not** separate roles — they are all `branch_admin` accounts with a free-text display `title` and their own individually toggled permissions. This "a title is just a label, permissions are toggled per account" shape, established by the original 24 permissions, is exactly how every one of the eleven new modules is wired up too — none of them introduce a new Spatie role.

**Permissions live on the user, not the role.** Spatie roles here carry **zero** permissions of their own; they exist only to identify the tier (`hasRole()` checks, sidebar grouping, dashboard variant). If a role carried real permissions, every account of that role would inherit them automatically through Spatie's role-permission link, which would silently defeat per-account permission toggling for `branch_admin`.

**The permission catalogue has grown from 24 to 66**, across 14 groups (`RolesAndPermissionsSeeder::PERMISSIONS` / `PERMISSION_GROUPS`):

| Group | Permissions |
|---|---|
| Organization / setup (`school`) | `manage-branches`, `manage-school-settings`, `manage-grading-scale`, `manage-staff` |
| People (`people`) | `manage-students`, `manage-parents`, `manage-teachers`, `manage-classes`, `override-section-capacity` |
| Medical (`medical`) | `medical-records-view`, `medical-records-manage` — deliberately separate from `manage-students` (§29) |
| Daily academics (`academics`) | `mark-attendance`, `view-attendance-reports`, `enter-marks`, `approve-results`, `publish-results`, `manage-assignments` |
| Finance (`money`) | `manage-fee-structures`, `manage-invoices`, `verify-payments`, `view-financial-reports`, `refund-payments`, `approve-large-discounts` |
| Communication (`messages`) | `manage-announcements`, `manage-complaints`, `manage-calendar` |
| Oversight (`oversight`) | `view-audit-log` |
| Inventory (`inventory`) | `inventory-view`, `inventory-manage`, `stock-receive`, `stock-issue`, `stock-transfer`, `stock-adjust`, `requisition-create`, `requisition-approve`, `requisition-issue`, `asset-view`, `asset-manage` |
| Procurement (`procurement`) | `supplier-manage`, `purchase-request-create`, `purchase-approve`, `purchase-approve-large`, `quotation-manage`, `purchase-order-manage`, `goods-receipt-manage`, `supplier-bill-manage`, `supplier-payment-manage` |
| Accounting (`accounting`) | `accounting-view`, `accounting-manage`, `accounting-period-manage`, `accounting-period-reopen` |
| Human Resources (`hr`) | `employee-view`, `employee-manage`, `contract-view`, `contract-manage`, `staff-attendance-view`, `staff-attendance-manage`, `leave-view`, `leave-request`, `leave-approve`, `performance-view`, `performance-manage`, `payroll-view`, `payroll-manage` |
| Timetable (`timetable`) | `timetable-view`, `timetable-manage`, `timetable-override` |
| Library (`library`) | `library-view`, `library-manage` |
| Discipline (`discipline`) | `discipline-view`, `discipline-manage` |

Every permission belongs to exactly one group (a test pins this). The 9 new groups were added incrementally, each in its own dated migration that grants the new permission names **only to existing `owner` accounts** at the time it ran (new accounts created after each migration rely purely on the seeder's `DEFAULT_PERMISSIONS` below):

- `2026_10_01_150000`/`160000` — Inventory Phase A: `inventory-view`/`-manage`, `stock-receive`/`-issue`/`-transfer`/`-adjust`, plus `schools.allow_negative_stock` (boolean, default false).
- `2026_10_02_130000` — Inventory Phase B: `requisition-create`/`-approve`/`-issue`.
- `2026_10_03_120000` — Inventory Phase C: `asset-view`/`asset-manage`.
- Inventory Phase D (Stocktakes) added no new permission — reuses `stock-adjust`.
- `2026_10_05_130000` — Procurement Phase 1: `supplier-manage`, `purchase-request-create`, `purchase-approve`, `purchase-approve-large`, plus `schools.purchase_approval_threshold` (nullable decimal).
- `2026_10_06_120000` — Procurement Phase 2: `quotation-manage`.
- `2026_10_07_120000` — Procurement Phase 3: `purchase-order-manage`.
- `2026_10_08_130000` — Procurement Phase 4: `goods-receipt-manage`.
- `2026_10_09_1*` — Accounting: `accounting-view`/`-manage`, `accounting-period-manage`, `accounting-period-reopen`.
- `2026_10_11_130000` — Procurement (payable side): `supplier-bill-manage`, `supplier-payment-manage` — deliberately kept in the `procurement` group, not `accounting`, "the same way `goods-receipt-manage` did."
- `2026_10_02_140000`/`03_110000`/`07_120000`/`09_130000`/`12_120000`/`13_140000` — HR Phases A–F: `employee-view`/`-manage` → `contract-view`/`-manage` → `staff-attendance-view`/`-manage` → `leave-view`/`-request`/`-approve` (`leave-request` alone is **also** pushed to existing `teacher` and `accountant` accounts, not just owner/branch_admin — the one HR permission deliberately available to every staff tier) → `performance-view`/`-manage` → `payroll-view`/`-manage`, plus `schools.pension_employee_rate`/`pension_employer_rate` and five `default_*_account_id` columns.
- `2026_10_14_1*` — Timetable: `timetable-view`/`-manage`/`-override`.
- `2026_10_16_*` — Calendar: `manage-calendar` (added to the existing `messages` group, not a new group).
- `2026_10_17_*` — Medical: `medical-records-view`/`-manage` (added to People's "medical" sub-concern).
- `2026_10_18_*` — Library: `library-view`/`-manage`, plus `schools.library_loan_days`/`library_fine_per_day`.
- `2026_10_19_*` — Discipline: `discipline-view`/`-manage`.

**Default permission set granted to a brand-new account, per tier** (`DEFAULT_PERMISSIONS`; a `branch_admin` account can then have individual permissions toggled off/on afterward, others cannot):

- **Owner** — every one of the 66 permissions. Also the only tier that is "org-wide" by default (`branch_id = null`) — `User::isOrgWide()` is what every branch-scoping check in the app tests against.
- **Branch Admin** — everything from the original 24-permission set, **plus** the full `medical`, `discipline`, `hr`, `timetable`, and `manage-calendar` groups by default. **Not** included by default: any `inventory-*`, `procurement-*`, `accounting-*`, or `library-*` permission — these are "Storekeeper"/"Procurement Officer"/"Accountant"/"Librarian" title-specific toggles, turned on per account deliberately, confirmed explicitly by several migration docblocks ("granted only to existing owners... everyone else is turned on per account, deliberately").
- **Teacher** — `mark-attendance`, `enter-marks`, `manage-assignments`, plus **`leave-request`** (self-service leave, new).
- **Accountant** — `manage-fee-structures`, `manage-invoices`, `verify-payments`, `view-financial-reports`, plus **`leave-request`**.
- **Parent** — none of the module permissions; sees only their own linked children's data, entirely through relationships.
- **Student** — none (student-role login is refused outright — see §16).
- **Auditor** — read-only everywhere. The explicit permission grant is still only `view-attendance-reports`, `view-financial-reports`, `view-audit-log`, but **every controller across every one of the eleven new modules independently checks `$user->hasRole('auditor')`** (via a shared `canViewOrManage()` helper on `User`, or an equivalent inline check) as a blanket read-everywhere bypass — so an auditor can view (never write) Inventory, Procurement, Accounting, Library, HR, Timetable, Discipline, and Medical Records screens without ever holding a single one of their `-view` permissions directly.

**Branch scoping** continues to apply everywhere a record has a `branch_id`: org-wide accounts see everything in the school; everyone else sees only their own branch. Across the new modules, `branch_id` is nullable (meaning "shared/visible to every branch") with varying delete behavior per table — mostly `cascadeOnDelete()`, but several Procurement tables (`purchase_orders.branch_id` and siblings) use `nullOnDelete()` instead, flagged as a possible inconsistency worth double-checking in §33.

**No route in the entire application — old or new — carries permission middleware.** Every permission check, in every one of the 73 controllers, happens inside the controller method via `abort_unless($user->can(...))` or the shared `canViewOrManage()` helper. This was already true of the original system and remains the uniform pattern for all eleven new modules.

**Parents and students get 404s, not 403s**, for anything not theirs. Staff get an ordinary 403 with an explanation.

---

## 4. Full Database Schema

All domain tables (plus Laravel's own framework tables and Spatie's 5 permission tables, not detailed here as they're stock). The original "core" schema (§6–§19) held roughly 32 domain tables; the eleven modules in §20–§30 add roughly 56 more (including the new `supplier_payment_reversals` table, §22), for a combined total around 88 domain tables. Every foreign key's delete behaviour is noted because it matters: `cascadeOnDelete` removes dependent rows, `nullOnDelete`/`restrictOnDelete` do not. In practice very few rows are ever hard-deleted at all (§11) — and this discipline carries forward into every new module: stock movements, asset events, goods receipts, supplier bills/payments/reversals, journal entries once posted, discipline incidents, and payslips are all either immutable-by-model-guard or simply have no destroy route at all.

**A real bug found and fixed during the 2026-10-16 hardening pass**: five of the new modules' `branch_id` foreign keys (`inventory_stores`, `assets`, `stocktakes`, `purchase_requests`, `calendar_events`) were originally `cascadeOnDelete()` — meaning deleting a `Branch` would have silently destroyed every inventory store, asset, stocktake, purchase request and calendar event scoped to it, none of which are supposed to disappear just because a branch record is removed (a branch itself is never the kind of thing that should drag unrelated operational history down with it — unlike, say, deleting a `Requisition` cascading its own `requisition_items`). All five were changed to `nullOnDelete()` to match the convention every other branch-scoped table in the system already uses (`payment_accounts`, `announcements`). Verified with a new `tests/Feature/BranchDeletionSafetyTest.php` (5 tests) proving each table's rows survive a branch delete with `branch_id` set to null rather than disappearing.

### Organization
- **schools** — `id`, `name`, `code` (unique), `logo_path`, `region`, `city`, `subcity`, `woreda`, `phone`, `email`, `calendar_preference` enum(`ethiopian`,`gregorian`,`both`) default `both`, `missing_mark_policy` enum(`exclude`,`zero`) default `exclude`, `enabled_languages` JSON default `["en","am"]`, `discount_limit_percent` decimal(5,2) default 10, `waiver_limit_amount` decimal(10,2) default 1000, `allow_overlapping_terms` boolean default false, **`allow_negative_stock`** boolean default false, **`purchase_approval_threshold`** nullable decimal(12,2), **`pension_employee_rate`** decimal(5,4) default `0.07`, **`pension_employer_rate`** decimal(5,4) default `0.11`, **`library_loan_days`** unsignedInteger default 14, **`library_fine_per_day`** nullable decimal(6,2) (NULL = fines disabled entirely, not a zero rate), and 12 nullable `default_*_account_id`→accounts columns (null on delete) used by the Accounting posting engine and seeded chart-of-accounts template: `default_ar_account_id`, `default_ap_account_id`, `default_cash_account_id`, `default_revenue_account_id`, `default_expense_account_id`, `default_discount_account_id`, `default_waiver_account_id`, `default_salary_expense_account_id`, `default_pension_expense_account_id`, `default_tax_payable_account_id`, `default_pension_payable_account_id`, `default_salary_payable_account_id`, timestamps.
- **branches** — `id`, `school_id`→schools (cascade), `name`, `code`, `address`, `is_main` boolean, timestamps. Unique(`school_id`,`code`).

### People
- **users** — `id`, `school_id`→schools (null on delete), `branch_id`→branches (null on delete), `name`, `title`, `email` (unique), `phone`, `email_verified_at`, `profile_photo_path`, `locale`, `password`, `status` enum(`active`,`inactive`) default `active`, `remember_token`, timestamps.
- **account_invitations** — one-time "set your password" links. `id`, `user_id`→users (cascade), `token_hash` char(64) unique, `expires_at` (72h), `used_at`, `used_ip`, `revoked_at`, `invited_by`→users (null on delete), `created_at`.

### Academic calendar & structure
- **academic_years**, **terms**, **grades**, **sections**, **subjects**, **teaching_assignments**, **grade_bands** — unchanged from the original spec (see §6/§7).

### Students & enrollment
- **students**, **enrollments**, **parent_student**, **student_optional_service** — unchanged (see §6/§9).

### Attendance
- **attendance_records** — unchanged (see §7). For the new, separate *per-period* attendance table, see §27's **period_attendance_records** below.

### Exams, marks, report cards
- **exams**, **mark_batches**, **marks**, **mark_batch_publications**, **report_card_snapshots** — unchanged (see §7).

### Finance
- **fee_structures**, **optional_services**, **invoices**, **invoice_items**, **payments** (now also carries a nullable **`gl_cash_account_id`**→accounts, added `2026_10_10_110000`, used only by the new Accounting posting engine — null on delete, falls back to the school's default cash account), **payment_refunds**, **payment_accounts**, **number_sequences** — otherwise unchanged (see §8). `number_sequences` is the same shared concurrency-safe counter now also minting `employee_number` (`EMP-{year}-{n}`), `asset_tag` (`AST-{year}-{n}`), and journal `entry_number` (`JE-{year}-{n}`).

### Communication & oversight
- **complaints**, **announcements**, **notifications**, **audit_logs** — unchanged (see §12–§15). For the new, separate **security_events** table (distinct from `audit_logs`), see §30 below.

### Human Resources & Payroll (new — full detail, see §20)
- **departments** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `manager_employee_id`→employees nullable (null on delete), `name`, `notes` text nullable, timestamps. Unique(`school_id`,`branch_id`,`name`). Deletion blocked while it has employees or positions.
- **positions** — "Job Title" in the UI, not a Spatie role. `id`, `school_id`→schools (cascade), `department_id`→departments nullable (null on delete), `title`, `description` text nullable, timestamps. Unique(`school_id`,`department_id`,`title`). Deletion blocked while employees hold it.
- **employees** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `user_id`→users nullable **unique** (null on delete), `employee_number` unique (`EMP-{year}-{6-digit seq}`), `full_name`, `photo_path` nullable, `phone`, `email` nullable, `address` text nullable, `emergency_contact_name`/`emergency_contact_phone` nullable, `gender` nullable, `date_of_birth` nullable date, `hire_date` date, `department_id`→departments nullable (null on delete), `position_id`→positions nullable (null on delete), `employment_status` default `active` (`active`,`on_leave`,`suspended`,`terminated`), `employment_type` default `full_time` (`full_time`,`part_time`,`contract`,`intern`), `qualifications` text nullable, `notes` text nullable, `created_by`→users (restrict), timestamps, **soft-deletes**.
- **employment_contracts** — full history, one row per contract. `id`, `employee_id`→employees (**restrict**), `contract_type` (`permanent`,`fixed_term`,`probationary`,`part_time`), `start_date`, `end_date` nullable, `status` default `draft` (`draft`,`active`,`expiring`,`expired`,`terminated`), `salary_reference` decimal(12,2) nullable — the field Payroll reads, `working_schedule` nullable, `document_path`/`document_original_name` nullable (private, PDF only), `renewal_date` nullable, `expiry_notified` boolean default false, `notes` text nullable, `created_by`→users (restrict), timestamps. Deletion blocked unless `status='draft'`.
- **staff_attendances** — `id`, `employee_id`→employees (restrict), `date`, `status` default `present` (`present`,`late`,`absent`,`early_departure`), `check_in`/`check_out` time nullable, `overtime_minutes` nullable unsignedSmallInteger, `notes` text nullable, `recorded_by`→users (restrict), timestamps. **Unique**(`employee_id`,`date`).
- **staff_attendance_corrections** — `id`, `staff_attendance_id`→staff_attendances (restrict), `new_status`, `new_check_in`/`new_check_out` nullable, `new_overtime_minutes` nullable, `reason` text required, `status` default `pending` (`pending`,`approved`,`rejected`), `requested_by`→users (restrict), `reviewed_by`→users nullable (null on delete), `reviewed_at` nullable, timestamps.
- **leave_types** — `id`, `school_id`→schools (cascade), `name`, `default_annual_days` nullable decimal(5,1) (a suggestion only, never auto-applied), `requires_document` boolean default false, `notes` text nullable, timestamps. Unique(`school_id`,`name`).
- **leave_allocations** — `id`, `employee_id`→employees (restrict), `leave_type_id`→leave_types (restrict), `year` unsignedSmallInteger, `allocated_days` decimal(5,1), `notes` text nullable, `created_by`→users (restrict), timestamps. **Unique**(`employee_id`,`leave_type_id`,`year`). Balances/usage are never cached — always summed live from approved requests.
- **leave_requests** — `id`, `employee_id`→employees (restrict), `leave_type_id`→leave_types (restrict), `start_date`/`end_date`, `days_count` decimal(5,1), `reason` text required, `document_path`/`document_original_name` nullable (private, PDF only), `status` default `draft` (`draft`,`submitted`,`approved`,`rejected`), `requested_by`→users (restrict), `reviewed_by`→users nullable (null on delete), `reviewed_at` nullable, `rejection_reason` text nullable, timestamps. App-level overlap check blocks overlapping submitted/approved requests; deletion blocked past `draft`.
- **performance_review_cycles** — `id`, `school_id`→schools (cascade), `name`, `start_date`/`end_date`, `notes` text nullable, timestamps. Unique(`school_id`,`name`).
- **performance_reviews** — `id`, `employee_id`→employees (restrict), `cycle_id`→performance_review_cycles (restrict), `rating` unsignedTinyInteger (1–5), `comments` text required, `status` default `draft` (`draft`,`finalized`), `reviewed_by`→users (restrict), `finalized_at` nullable, timestamps. **Unique**(`employee_id`,`cycle_id`). No self-review permission exists anywhere; deletion blocked once finalized.
- **payroll_tax_brackets** — `id`, `school_id`→schools (cascade), `min_amount`/`max_amount` (nullable = unbounded top bracket) decimal(12,2), `rate` decimal(5,4) (a fraction; UI shows/edits as a percentage), `deduction` decimal(12,2) default 0, timestamps. Seeded per school with the Ethiopian Income Tax Proclamation (Amendment) No. 1395/2025 schedule (effective 8 Jul 2025): 0–2000 @0%; 2000.01–4000 @15% −300; 4000.01–7000 @20% −500; 7000.01–10000 @25% −850; 10000.01–14000 @30% −1350; 14000.01–∞ @35% −2050. Flagged throughout the UI as unverified against current law — a deliberate, disclosed product decision.
- **payroll_runs** — `id`, `school_id`→schools (cascade), `branch_id`→branches (**restrict**), `year`/`month`, `status` default `draft` (`draft`,`finalized`), `generated_by`→users (restrict), `finalized_by`→users nullable (**restrict**, not null-on-delete), `finalized_at` nullable, `journal_entry_id`→journal_entries nullable (null on delete), timestamps. **Unique**(`branch_id`,`year`,`month`). Deletion blocked once finalized.
- **payslips** — `id`, `payroll_run_id`→payroll_runs (**cascade**), `employee_id`→employees (restrict), `basic_salary`/`gross_pay`/`taxable_income`/`income_tax`/`employee_pension`/`employer_pension`/`net_pay` decimal(12,2), timestamps. **Unique**(`payroll_run_id`,`employee_id`). A permanent snapshot, never re-derived after a later salary change.

### Inventory & Assets (new — full detail, see §21)
- **inventory_categories** — `id`, `school_id`→schools (cascade), `kind` enum(`consumable`,`asset`), `name`, timestamps, soft-deletes.
- **inventory_stores** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (**null on delete**, changed from cascade — see note below) — null = shared, `name`, `code` nullable, timestamps, soft-deletes.
- **inventory_items** — `id`, `school_id`→schools (cascade), `category_id`→inventory_categories nullable (null on delete), `name`, `sku`/`barcode` nullable, **`unique(['school_id','sku'])`/`unique(['school_id','barcode'])` at the DB level** (added `2026_10_16`, migration does a defensive duplicate check first so it can't fail on pre-existing data), `unit` default `pcs`, `description` text nullable, `min_stock_level`/`reorder_level` unsignedInt default 0, timestamps, soft-deletes. On soft-delete, a `booted()` hook rewrites `sku`/`barcode` to `{value}#archived-{id}` so an archived item's old code can immediately be reused by a new one without colliding against the unique index.
- **stock_balances** — `id`, `item_id`→inventory_items (cascade), `store_id`→inventory_stores (cascade), `quantity` signed int default 0, timestamps. **Unique**(`item_id`,`store_id`). Never written to outside `App\Services\Stock`.
- **stock_movements** — append-only, model-guarded immutable. `id`, `school_id`→schools (cascade), `item_id`→inventory_items (cascade), `store_id`→inventory_stores (cascade), `type` (INCREASE: `opening`,`purchase_receipt`,`return`,`transfer_in`,`adjustment_increase`; DECREASE: `issue`,`transfer_out`,`damaged`,`lost`,`adjustment_decrease`), `quantity` unsigned int (always positive, direction implied by `type`), `reference_number`/`reason` nullable, `related_movement_id`→stock_movements nullable self-FK (null on delete, links a transfer's two rows), `performed_by`→users (**restrict**), plus three later-added nullable/null-on-delete traceability FKs — `related_requisition_item_id`→requisition_items, `related_stocktake_line_id`→stocktake_lines, `related_goods_receipt_item_id`→goods_receipt_items, timestamps.
- **requisitions** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (cascade), `store_id`→inventory_stores (**restrict**), `requester_id`→users (**restrict**), `number` unique, `department` nullable, `purpose` required, `priority` (`low`,`normal`,`urgent`) default `normal`, `status` default `draft` (`draft`,`submitted`,`approved`,`rejected`,`partially_issued`,`fully_issued`,`completed`), `reviewed_by`→users nullable (null on delete), `reviewed_at`/`rejection_reason`/`completed_at` nullable, timestamps, soft-deletes. Hard-delete blocked past `draft`.
- **requisition_items** — `id`, `requisition_id`→requisitions (cascade), `item_id`→inventory_items (**restrict**), `quantity_requested` unsignedInt, `quantity_approved` nullable unsignedInt, `quantity_issued` unsignedInt default 0, `notes` nullable, timestamps. No price fields — requisitions are never priced.
- **assets** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (**null on delete**, changed from cascade — see §4's branch-deletion note below), `category_id`→inventory_categories nullable (null on delete, `kind='asset'` rows only), `asset_tag` unique (`AST-{year}-{seq}`), `name`, `serial_number`/`barcode` nullable, `purchase_date` nullable, `acquisition_cost` nullable decimal(12,2), `supplier` nullable **plain string** (free text, independent of the structured link below — kept for suppliers never formally onboarded into the Procurement module), **`supplier_id`→suppliers nullable** (null on delete, added `2026_10_16`) — an optional *structured* link alongside the pre-existing free-text field; the Create form lets a user pick an existing Supplier record or type a brand-new name (choosing "other" leaves `supplier_id` null and only fills the free-text field), `current_store_id`→inventory_stores nullable (null on delete), `assigned_to`→users nullable (null on delete), `condition` default `good` (`good`,`fair`,`poor`), `status` default `available` (`available`,`assigned`,`under_repair`,`lost`,`damaged`,`retired`,`disposed`), `warranty_expiry` nullable, `notes` text nullable, `attachments` json nullable, timestamps, soft-deletes.
- **asset_events** — append-only, immutable. `id`, `asset_id`→assets (cascade), `event_type` (`assigned`,`reassigned`,`transferred`,`repair_started`,`repair_completed`,`returned`,`retired`,`disposed`,`marked_lost`,`marked_damaged`), `from_user_id`/`to_user_id`→users nullable (null on delete), `from_store_id`/`to_store_id`→inventory_stores nullable (null on delete), `notes` nullable, `performed_by`→users (**restrict**), timestamps.
- **stocktakes** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (**null on delete**, changed from cascade — see note below), `store_id`→inventory_stores (**restrict**), `status` default `in_progress` (`in_progress`,`completed`), `started_by`→users (restrict), `started_at`, `completed_by`→users nullable (null on delete), `completed_at`/`notes` nullable, timestamps.
- **stocktake_lines** — `id`, `stocktake_id`→stocktakes (cascade), `item_id`→inventory_items (**restrict**), `system_quantity` signed int (snapshot at start), `physical_quantity` nullable signed int, `reason` nullable, timestamps. The final adjustment compares against the *live* balance at completion time, not this stale snapshot.

### Procurement & Suppliers (new — full detail, see §22)
- **suppliers** — `id`, `school_id`→schools (cascade), `code` unique (`SUP-{year}-{seq}`), `name`, `contact_person`/`phone`/`email`/`address`/`tax_number`/`bank_name`/`bank_account_name`/`bank_account_number`/`notes` nullable, timestamps, soft-deletes.
- **purchase_requests** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (**null on delete**, changed from cascade — see note below), `requester_id`→users (**restrict**), `number` unique, `department` nullable, `purpose` required, `priority` default `normal`, `status` default `draft` (`draft`,`submitted`,`approved`,`rejected` — no in-between state), `reviewed_by`→users nullable (null on delete), `reviewed_at`/`rejection_reason`/`notes` nullable, `attachments` json nullable, timestamps, soft-deletes. Hard-delete blocked past `draft`.
- **purchase_request_items** — `id`, `purchase_request_id`→purchase_requests (cascade), `item_id`→inventory_items nullable (**null on delete** — nullable because a line can be a catalog item or a free-text service), `description` required, `quantity` unsignedInt default 1, `estimated_unit_price`/`estimated_total` nullable decimal(12,2) (computed server-side at creation, never recomputed later), `notes` nullable, timestamps.
- **quotations** — `id`, `school_id`→schools (cascade), `purchase_request_id`→purchase_requests (cascade), `supplier_id`→suppliers (**restrict**), `reference` nullable, `quotation_date` required, `validity_date`/`delivery_info`/`notes` nullable, `selected` boolean default false (set only by an explicit `quotations.select` action, never automatically), `recorded_by`→users (restrict), timestamps. Hard-delete blocked while `selected=true`.
- **quotation_items** — `id`, `quotation_id`→quotations (cascade), `purchase_request_item_id`→purchase_request_items (**restrict**) — always ties to a specific requested line for apples-to-apples comparison, `quantity` unsignedInt, `unit_price`/`tax_amount` (default 0)/`total` (server-computed) decimal(12,2), timestamps.
- **purchase_orders** — `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (**null on delete**), `purchase_request_id`→purchase_requests (**restrict**), `quotation_id`→quotations nullable (null on delete, traceability only, never re-validated), `supplier_id`→suppliers (restrict), `number` unique, `status` plain string default `draft` (`draft`,`sent`,`partially_received`,`received`,`cancelled`), `order_date` required, `expected_delivery_date`/`delivery_info`/`notes` nullable, `created_by`→users (restrict), `sent_at`/`cancelled_at`/`cancellation_reason` nullable, timestamps, soft-deletes. Hard-delete blocked past `draft`.
- **purchase_order_items** — `id`, `purchase_order_id`→purchase_orders (cascade), `purchase_request_item_id`→purchase_request_items (**restrict**) — no free-text description, always points to the original requested line, `quantity` unsignedInt, `unit_price`/`tax_amount`/`total` decimal(12,2), timestamps.
- **goods_receipts** — immutable once created, no soft-deletes (never removable). `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `purchase_order_id`→purchase_orders (**restrict**), `store_id`→inventory_stores (**restrict**), `number` unique, `received_date` required, `notes` nullable, `recorded_by`→users (restrict), timestamps.
- **goods_receipt_items** — `id`, `goods_receipt_id`→goods_receipts (cascade), `purchase_order_item_id`→purchase_order_items (**restrict**), `quantity_received` unsignedInt, `notes` nullable, timestamps.
- **supplier_bills** — no draft state, no stored total (always live). `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `supplier_id`→suppliers (restrict), `purchase_order_id`→purchase_orders **nullable** (null on delete — a bill can exist with no PO), `bill_number` unique, `supplier_reference` nullable, `bill_date` required, `due_date`/`notes` nullable, `created_by`→users (restrict), timestamps. Hard-delete blocked if any payment exists.
- **supplier_bill_items** — `id`, `supplier_bill_id`→supplier_bills (cascade), `purchase_order_item_id`→purchase_order_items **nullable** (null on delete), `expense_account_id`→accounts **nullable** (null on delete; falls back to the school's default expense account), `description` required, `quantity` unsignedInt, `unit_price`/`tax_amount`/`total` decimal(12,2), timestamps.
- **supplier_payments** — always born "confirmed" (no verify step), immutable. `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `supplier_bill_id`→supplier_bills (**restrict**), `amount` decimal(12,2), `method` plain string default `bank_transfer` (`bank_transfer`,`mobile_money`,`cash`,`other`), `reference_number` nullable, `gl_cash_account_id`→accounts nullable (null on delete; falls back to the school's default cash/undeposited-funds account), `notes` nullable, `recorded_by`→users (restrict), `paid_date` required, timestamps. The payment row itself is still never edited or deleted — see the new **supplier_payment_reversals** table immediately below for how a wrong payment is now corrected.
- **supplier_payment_reversals** — NEW table, added `2026_10_16` to close a real, disclosed gap (a wrong supplier payment previously had no correction path short of a manual journal entry — see §22.4). `id`, `school_id`→schools (cascade), `supplier_payment_id`→supplier_payments (**restrict**), `amount` decimal(12,2) (always ≤ the original payment's still-outstanding, un-reversed amount — enforced under a row lock), `kind` string (`SupplierPaymentReversal::KINDS` — e.g. `returned_goods`, `payment_error`, `duplicate_payment`, `other`), `reason` text nullable, `reversed_date` required, `recorded_by`→users (restrict), timestamps. Append-only — uses `Audited` and `NeverHardDeleted`, same discipline as `PaymentRefund` on the receivable side. Posts a best-effort reversing GL entry (`AccountingPostings::postSupplierPaymentReversed()`).

### Accounting Engine (new — full detail, see §23)
- **accounts** — one school's chart-of-accounts line, not a shared template. `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `parent_id`→accounts nullable self-referencing (null on delete), `code` string(20), `name`, `type` (`asset`,`liability`,`equity`,`revenue`,`expense`), `normal_balance` (`debit`/`credit` — auto-derived from `type`), `is_header` boolean default false (grouping, never postable), `is_system` boolean default false (protects accounts the posting engine relies on), `is_active` boolean default true, timestamps, soft-deletes. Unique(`school_id`,`code`). Deletion blocked if `is_system`, has children, or has journal lines — deactivate instead.
- **journals** — `id`, `school_id`→schools (cascade), `code` string(20), `name`, `type` (`cash`,`bank`,`sales`,`purchase`,`general`,`receivable`,`payable`), `is_system`/`is_active` booleans, timestamps. Unique(`school_id`,`code`). Deletion blocked if `is_system` or has entries.
- **accounting_periods** — `id`, `school_id`→schools (cascade), `name`, `start_date`/`end_date`, `status` default `open` (`open`,`closed`), `closed_by`/`reopened_by`→users nullable (null on delete), `closed_at`/`reopened_at` nullable, timestamps. No DB-level overlap constraint (checked in the controller). Deletion blocked if it has entries.
- **journal_entries** — the posting header. `id`, `school_id`→schools (cascade), `branch_id`→branches nullable (null on delete), `journal_id`→journals (**restrict**), `accounting_period_id`→accounting_periods (**restrict**), `entry_number` globally unique (`JE-{year}-{6-digit seq}`), `entry_date`, `reference`/`description` nullable, `source_module` nullable (`fee_billing`,`payments`,`procurement`,`hr`,`manual`), `source_type`/`source_id` polymorphic (tracing an auto-generated entry back to its triggering Invoice/Payment/PaymentRefund/SupplierBill/SupplierPayment/PayrollRun), `status` default `draft` (in practice only `draft`/`posted` ever stored — "reversed" is derived from `reversed_by_entry_id !== null`), `created_by`→users (restrict), `posted_by`→users nullable (null on delete), `posted_at` nullable, `reverses_entry_id`/`reversed_by_entry_id`→journal_entries nullable self-referencing (null on delete), timestamps. **Immutable once posted** except for setting `reversed_by_entry_id` — enforced at the model layer.
- **journal_entry_lines** — `id`, `journal_entry_id`→journal_entries (cascade), `account_id`→accounts (**restrict**), `line_number` unsignedInt, `debit`/`credit` decimal(14,2) default 0 (exactly one nonzero per line, enforced in code, not a DB CHECK constraint), `description` nullable, timestamps. Frozen through the child model too once the parent is posted.

### Library (new — full detail, see §25)
- **books** — school-wide catalog title (shared across branches). `id`, `school_id`→schools (cascade), `title`, `author`/`isbn`/`category` nullable, `created_by`→users nullable (null on delete), timestamps. Deletion blocked while it has copies.
- **book_copies** — one row per physical item, branch-scoped. `id`, `book_id`→books (cascade), `branch_id`→branches (cascade), `copy_number`, `status` enum(`available`,`issued`,`lost`,`damaged`) default `available`, `created_by`→users nullable (null on delete), timestamps. Unique(`book_id`,`copy_number`). Deletion blocked while it has loan records.
- **library_loans** — `id`, `book_copy_id`→book_copies (cascade), `borrower_type`+`borrower_id` polymorphic (`Student` or `User`), `issued_by`→users (**restrict**), `issued_at`/`due_date` date, `returned_at` nullable, `renewed_count` unsignedInt default 0 (no cap found anywhere), `status` enum(`active`,`returned`,`lost`,`damaged`) default `active`, `fine_amount` nullable decimal(8,2), `fine_waived_at`/`fine_paid_at` nullable, **`overdue_notified_at`** nullable (added `2026_10_16` — a one-shot guard so the new overdue reminder, §25.6, notifies exactly once per overdue loan rather than once per scheduler run; reset back to null by `renew()` so a renewed loan can be reminded again if it later goes overdue a second time), `notes` nullable, timestamps. No soft-deletes, no destroy route — loans are closed, never deleted.

### Timetable, Periods & Rooms (new — full detail, see §26–§27)
- **periods** — school-wide bell-schedule catalog. `id`, `school_id`→schools (cascade), `period_number` unsignedTinyInt, `name` nullable, `start_time`/`end_time` time, timestamps. Unique(`school_id`,`period_number`). No branch scoping — one shared bell schedule per school.
- **rooms** — branch-scoped. `id`, `branch_id`→branches (cascade), `name`, `capacity` nullable unsignedSmallInt, timestamps. Unique(`branch_id`,`name`).
- **timetable_slots** — one weekly teaching slot, layered on top of (but not requiring) `teaching_assignments`. `id`, `school_id`→schools (cascade), `branch_id`→branches (cascade), `term_id`→terms (cascade), `section_id`→sections (cascade), `subject_id`→subjects (**restrict**), `teacher_id`→users (**restrict**), `room_id` nullable→rooms (null on delete), `period_id`→periods (**restrict**), `day_of_week` unsignedTinyInt 1–7 (ISO, Mon=1..Sun=7), `override_reason` nullable text, `created_by`→users (restrict), timestamps. **No unique constraint at all** — conflict rules are enforced purely in application logic (`TimetableConflicts`) so that an authorized override can save a genuine duplicate with a recorded reason, something a DB unique index could never express.
- **period_attendance_records** — one row per student per TimetableSlot occurrence per date, distinct from daily `attendance_records`. `id`, `timetable_slot_id`→timetable_slots (cascade), `enrollment_id`→enrollments (cascade), `date`, `status` enum(`present`,`absent`,`late`,`excused`) — deliberately **no `sick`** status, `marked_by`→users (cascade), `remarks` nullable, timestamps. **Unique**(`enrollment_id`,`timetable_slot_id`,`date`).

### School Calendar (new — see §28)
- **calendar_events** — `id`, `school_id`→schools (cascade), `branch_id` nullable→branches (**null on delete**, changed from cascade — see note below) — NULL = every branch, `created_by` nullable→users (null on delete), `title`, `description` nullable text, `event_type` enum(`holiday`,`exam`,`meeting`,`activity`,`deadline`,`other`) default `other`, `audience` enum(`everyone`,`parents`,`staff`) default `everyone`, `start_date`, `end_date` nullable (NULL = single-day), `notified_at` nullable, timestamps, **soft-deletes**.

### Student Discipline & Medical Records (new — see §29)
- **student_medical_records** — one row per student, upserted, never a log. `id`, `student_id`→students (cascade) **unique**, `blood_type` nullable, `allergies`/`conditions`/`medications`/`notes` nullable text, `physician_name`/`physician_phone` nullable, `updated_by` nullable→users (null on delete), timestamps. No attachment column. **Does not use the `Audited` trait** — see §29 for why.
- **discipline_incidents** — append-only, no destroy route at all. `id`, `student_id`→students (cascade), `category` enum(`tardiness`,`attendance`,`disrespect`,`bullying`,`fighting`,`cheating`,`vandalism`,`dress_code`,`other`), `description` required, `incident_date` (must be ≤ today), `action_taken` enum(`none`,`verbal_warning`,`written_warning`,`detention`,`parent_contacted`,`suspension`,`expulsion`,`other`) default `none`, `status` enum(`open`,`under_review`,`resolved`) default `open`, `reported_by`→users (**restrict**), `resolved_by` nullable→users (null on delete), `resolved_at` nullable, `attachment_path`/`attachment_original_name` nullable, timestamps. **Does not use the `Audited` trait** either — see §29.

### Security Log & Assignments (new — see §30)
- **security_events** — wholly separate from `audit_logs`, because most of these happen to people who are not signed in at all. `id`, `school_id` nullable→schools (null on delete), `user_id` nullable→users (null on delete), `actor_id` nullable→users (null on delete, who *did* it — e.g. an admin unlocking someone), `event` string(60) indexed, `identifier` string(120) nullable — **always stored masked** (`+2519*****344`, `a***@b.com`), `ip_address` string(45) nullable indexed, `user_agent` string(200) nullable, `context` json nullable (small non-secret details only, never a password), `created_at` only — **`$timestamps = false`**, no `updated_at`, purely an append log.
- **assignments** (homework) — genuinely separate from Exams/Marks, no FK to either and no score column; never feeds into grades. `id`, `section_id`→sections (cascade), `subject_id`→subjects (cascade), `teacher_id`→users (cascade), `title`, `description` nullable, `due_date`, `attachment_path`/`attachment_original_name` nullable, `status` enum(`draft`,`published`) default `draft`, `published_at` nullable, `due_soon_notified_at` nullable, timestamps.

---

## 5. Full Route Surface

Every route except `/login`, `/forgot-password`/`/reset-password`, `/verify/{token}` (public ID-card page) and the invitation-acceptance pages sits behind `auth`. Every route is a plain Laravel web route returning either an Inertia page or a redirect — there is no JSON API, with the sole original exception of `GET /parents/search`, now joined by a handful of similar live-lookup endpoints in the new modules (`GET /library/borrowers/search`). **No route anywhere in the application carries permission middleware** — every check happens inside the controller (§3).

The original core surface held 141 routes (§6–§19 below). The eleven new modules add roughly 219 more, for a combined total around 360. The core route tables (Auth & account; Dashboard/help/notifications; Organization & settings; People; Academics; Finance; Communication/family/oversight) are unchanged from the original spec — see §6–§19 for their exact method/URL/purpose. The new modules' route tables are reproduced in full within their own sections:

- **Human Resources & Payroll** — see §20 for the full ~58-route table (employees, departments, positions, employment-contracts, staff-attendance(+corrections), leave-types, leave-allocations, leave-requests(+mine/pending), performance-cycles, performance-reviews(+mine), payroll-settings(+tax-brackets), payroll-runs(+mine payslips)).
- **Inventory & Assets** — see §21 for the full ~46-route table (inventory items/stores/categories/movements, requisitions, assets, stocktakes).
- **Procurement & Suppliers** — see §22 for the full ~29-route table (suppliers, purchase-requests, quotations, purchase-orders, goods-receipts, supplier-bills, supplier-payments). **No dedicated index/show route exists for Quotations or Goods Receipts** — both are nested resources managed entirely from `PurchaseRequests/Show` and `PurchaseOrders/Show` respectively.
- **Accounting Engine & Reports** — see §23/§24 for the full ~30-route table (accounts, journals, accounting-periods, journal-entries, plus 8 financial reports and the `/reports` catalog index).
- **Library** — see §25 for the full ~20-route table (loans, borrower search, settings, books, book copies).
- **Timetable, Periods & Rooms** — see §26 for the periods/rooms/timetable(+mine) routes.
- **Period Attendance** — see §27 for the period-attendance routes.
- **School Calendar** — see §28 for the calendar routes.
- **Student Discipline & Medical Records** — see §29 for the discipline(+document)/medical routes.
- **Security Log, Student Desk & Assignments** — see §30 for `/audit-log/security`, `/students/{student}/help` (route name `students.desk`), and the assignments(+document)/family-timetable/family-homework routes.

---

## 6. People, Organization & Academic Structure

*(unchanged from the original spec)*

**Setup order the system expects:** School → Branch(es) → Academic Year (mark one current) → Terms → Grades → Sections (per branch & year) → Subjects → Staff accounts → Teaching Assignments/Class teachers → Grade bands → Exams → Fee structures → Students. (A school setting up the newer modules additionally, at its own pace: install the chart of accounts template → §23; configure payroll pension rates/tax brackets → §20; create inventory stores/categories → §21; create rooms/periods → §26.)

**Staff accounts & onboarding.** Created by name/email/title/role/branch. A new account gets a **one-time invitation link** ("Set your password"): valid **72 hours**, works **once**, only its SHA-256 hash is stored, and creating a new invitation revokes the previous one. The public invitation page (`/invitation/{token}`) shows only the person's name, email and school; an unknown, used, revoked, expired or inactive-account link all show the identical "no longer valid" page. The owner may create any staff tier; other admins only `branch_admin`/`teacher`/`accountant`. Only `branch_admin` accounts get per-permission toggles — now across all 14 permission groups, not just the original 6. Accounts are **deactivated**, never deleted (§11). Note: a staff *login* account (`User`) and an HR *employee profile* (`Employee`, §20) are deliberately separate, optionally-linked records — linking one to the other grants no role/permission change at all.

**Student enrollment wizard (4 steps).** Child → Contact → Class (capacity-checked) → Parent. One database transaction creates the student row, an `active` enrollment, and the guardian link.

**Student record.** Edit names/status (7 lifecycle statuses), photo upload, guardians, optional services, mid-year transfer, withdrawal, ID card, report card — plus, now, two permission-gated extra tabs reachable from the student's own page: **Medical Records** (§29) and **Discipline** history (§29), each independently gated and never exposed to someone holding only `manage-students`.

**Grades & Sections, Parents page** — unchanged.

---

## 7. Academics — Attendance, Exams, Marks, Report Cards

*(unchanged from the original spec — daily homeroom attendance, exam-weight rules, mark ownership/workflow, report-card ranking rules, grading scale all as originally documented. For the new, separate per-period attendance concept, see §27. For homework/assignments, which never feed into grades, see §30.)*

**One real bug found and fixed 2026-10-16**: `AttendanceController@store`'s batch save (one class's worth of students, written as a `foreach` loop of individual row writes) was not wrapped in a database transaction — a failure partway through the loop (e.g. a validation edge case on one student) could leave a class's attendance for that day half-written, with some students marked and others silently not. It is now wrapped in `DB::transaction()`, so a mid-batch failure rolls back the entire batch rather than partially applying it — verified by a new regression test in `tests/Feature/AttendanceWorkflowTest.php` that deliberately forces a failure partway through a batch and asserts zero rows were written, not some. The identical fix was applied to staff attendance's own batch save at the same time — see §20.4.

---

## 8. Finance — Fees, Invoices, Payments, Refunds, Discounts

*(unchanged from the original spec — fee structures, optional services, invoice generation, the centralized `App\Services\Finance` money formulas, discount/waiver authority, refunds, the manual payment flow, branch payment accounts, filters & Excel exports all as originally documented. This remains the single source of truth for what a family owes/paid; nothing in the new Accounting engine, §23, is ever read back to answer that question — see §23's "Relationship to the existing simple Finance system" for exactly how the two connect.)*

---

## 9. Calendar, Age, Names & Phone Numbers

*(unchanged from the original spec — Ethiopian/Gregorian calendar conversion, business timezone, age-from-date-of-birth, flexible names/guardian relationships, phone normalization. Note: this section is about the **Ethiopian calendar conversion system**; the unrelated **School Calendar events feature** — holidays, meetings, exams on a shared calendar page — is new and documented separately in §28.)*

---

## 10. Enrollment Safety — Promotion & Section Capacity

*(unchanged from the original spec.)*

---

## 11. Data Integrity — Deletion Rules, Year Switching, Term Dates

*(unchanged from the original spec's three deletion tiers — Never deleted / Deleted only while unused / Archived, not erased. Every one of the eleven new modules follows the exact same discipline: see the per-module "Deletion blocked..."/"immutable"/"no destroy route" notes throughout §4 and §20–§30. New never-hard-deleted records: `StockMovement`, `AssetEvent`, `GoodsReceipt`/`GoodsReceiptItem`, `SupplierPayment`, **`SupplierPaymentReversal`** (added 2026-10-16, §22), a posted `JournalEntry`, `DisciplineIncident`, `Payslip`. New archived-not-erased (soft-deleted) records: `Employee`, `InventoryCategory`/`InventoryStore`/`InventoryItem` (an archived item's `sku`/`barcode` is also rewritten with an `#archived-{id}` suffix so the code can be reused, §4), `Asset`, `Supplier`, `PurchaseOrder`, `CalendarEvent`, `Account`. A real bug fixed 2026-10-16, **not** part of this deliberate discipline but worth distinguishing from it: five tables' `branch_id` foreign keys were wrongly `cascadeOnDelete()` instead of `nullOnDelete()`, meaning deleting a `Branch` would have silently deleted unrelated inventory stores, assets, stocktakes, purchase requests, and calendar events — see §4's dedicated note for the full detail and the branch-deletion regression test that now guards it.)*

---

## 12. Audit Trail

*(core mechanics unchanged — see the original description of `audit_logs`, the `Audited` trait, and automatic vs. explicit-summary entries. Every new module's specific audit action names and exclusions are documented in its own section's "Notifications & audit trail" subsection — §20.6, §21/§22.8, §23.9 (folded into §23's own business-rules text), §25.9, §26–§30's shared §30.10. Two deliberate, disclosed departures from the universal `Audited` trait: `DisciplineIncident` and `StudentMedicalRecord` write their own minimal `AuditLog::record()` call with before/after both empty, specifically so that *what changed* in a health or behavioral record is never readable by the broader `view-audit-log` audience — only *that* it changed, by whom, and when (§29). `PeriodAttendanceRecord` and `Payslip` both suppress per-row creation logging (`auditsCreation() => false`) to avoid spamming the log with one entry per student-period or per-payslip; the parent action (the batch save, the payroll run's own `finalized` entry) already covers it.)*

---

## 13. Complaints & Their Privacy Model

*(unchanged from the original spec.)*

---

## 14. File Uploads & Content Security

*(unchanged mechanics — `App\Support\SafeUpload`/`SafeUploadRule` continue to govern every upload in the system. The set of protected private-disk documents has grown: employment contract PDFs, leave-request supporting documents, discipline-incident attachments, and assignment attachments all now use the same content-sniffed, randomly-named, policy-gated serving pattern as payment proofs — each served only through its own narrow, authorization-checked controller action (`EmploymentContractController@document`, `LeaveRequestController@document`, `DisciplineIncidentController@document`, `AssignmentController@document`), never a direct public URL.)*

---

## 15. Communication — Announcements & Notifications

*(Announcements mechanics unchanged. The notification catalogue has grown from 8 types to roughly 26, entirely in-app/database — no module anywhere adds an email/SMS channel. New notification classes, grouped by module, are listed in full in each module's own "Notifications & audit trail" subsection: HR §20.6 (7 types), Inventory/Procurement §21–22.6 (10 types), Library §25.6 (1 type, `LoanOverdue`, added 2026-10-16), Timetable/Calendar/Assignments §26–30.10 (4 types). The Accounting engine deliberately adds none at all — see §23's notification note — it remains check-the-page-on-demand, never proactively pushed; the new Supplier Payment Reversal workflow (§22.4) likewise adds no notification of its own, same as the six other un-notified Procurement lifecycle events it joins.)*

---

## 16. Family (Parent) Portal & the Student-Login Decision

*(core mechanics unchanged — attendance history, report-cards hub, the deliberate no-student-login decision. Two new parent-facing pages were added as part of the Timetable and Assignments modules: `/my/timetable` (`Family/Timetable.jsx`, read-only weekly grid for the selected child's current section) and `/my/homework` (`Family/Homework.jsx`, published-only assignment list for the selected child's section) — both reuse the existing `ChildSwitcher` pattern and both are deliberately read-only, since parents hold no timetable or assignment permission. See §26 and §30.)*

---

## 17. Identity — ID Cards

*(unchanged from the original spec.)*

---

## 18. Multi-language

Core mechanics unchanged — four languages, per-school enablement, per-user choice, the AI-assisted-draft-translation disclosure in `LOCALIZATION.md`. **Correction to an earlier draft of this document, which claimed the eleven new modules were English-only — verified false by a direct, programmatic comparison of every key in `en.json` against `am.json`/`om.json`/`ti.json`**: all 3,736 frontend translation keys exist in all three other languages, with essentially none left identical to the English source (i.e. not a placeholder copy-through) — this covers every new module's namespace (`hr`, `pr`/`requisition`, `asset`, `supplier`/`supplier_bill`/`supplier_payment`, `account`/`journal`/`period` (accounting), `library`, `timetable`, `discipline`, `calendar`, `payroll`, `leave`, `performance`, `department`, `position`, `contract`, `stocktake`, `goods_receipt`, `quotation`, `purchase_order`, `inv` (inventory), `req`, `desk`, `assignment`, `secevt` (security log)). Backend notification strings (`lang/{en,am,om,ti}/notifications.php`) are equally complete: 32 titles / 31 bodies in every language, matching exactly. All of this is still only AI-assisted draft quality, unreviewed by a native speaker, same as the original screens — `resources/js/lang/review-status.json` correctly tracks every one of these namespaces as `"draft"` in am/om/ti, which is the accurate status (translated but unreviewed), not untranslated.

**The one real, verified gap** is unrelated to "new vs. old": computed/dynamic validation and error messages built directly inside controllers (e.g. `"You can refund at most {amount} ETB..."`, `"This grade has a 'per term' fee, but no terms are set up..."`) are raw English string literals, never passed through `__()`/`trans()` — confirmed as a pre-existing, whole-application pattern present identically in the original core controllers (`PaymentController`, `InvoiceController`) and every new-module controller alike, not something introduced by or specific to the new modules. Static UI text (labels, buttons, headings, confirmations, empty states) is fully translated everywhere; only these dynamic, server-computed error strings are not. Fixing this would be a cross-cutting change touching the whole application's controller layer, not a "finish the new modules" task.

---

## 19. Dashboards

The original four dashboard variants (Owner/Branch Admin/Auditor, Accountant, Teacher, Parent) are as previously documented, still driven exclusively by `App\Services\Finance`/the existing attendance-counting logic for every money/attendance figure. **Correction to an earlier draft of this document**, which claimed none of the new modules had dashboard integration — verified by reading `DashboardController` directly: this is only partly true. Already wired up, each independently gated the same "null = not for you" way as every other figure here:

- **Inventory** — total items, low-stock, out-of-stock counts (Owner/Branch Admin/Auditor and Accountant dashboards).
- **Requisitions** — pending approvals (for an approver), the signed-in person's own pending requisitions.
- **Assets** — total count, count under repair.
- **HR** — a full snapshot (`hrStats()`): total active employees, contracts expiring soon, today's staff attendance rate, pending leave requests, performance reviews finalized this year, the latest payroll run's status — each sub-figure gated on its own HR permission independently, so (for example) someone who can see employees but was never given `payroll-view` still gets every other figure, just not the payroll one.
- **Procurement** — added during the 2026-10-16 hardening pass (`DashboardController::procurementStats()`, mirroring `requisitionStats()`'s exact shape and "null = not for you" convention). Shows `pending_approvals` (branch-scoped, split correctly by the large-purchase threshold — a plain `purchase-approve` holder never sees or is shown a request that actually needs `purchase-approve-large`, and vice versa) and `my_pending` (the signed-in person's own submitted-or-approved requests, for a plain requester with no approval permission at all). Verified by a new `tests/Feature/ProcurementDashboardTest.php` (7 tests) covering the threshold split, status filtering (`submitted` only, never `draft`/`approved`), branch scoping, and the org-wide owner view.

**Still not surfaced on any dashboard** (confirmed, a real, disclosed gap — not fixed this session, see §33): Library (overdue loans, unresolved fines), Timetable/Calendar (today's schedule, upcoming events), Discipline (open incidents). Each of these is visible only on its own module's own index page.

**Date/day header (new).** Every dashboard variant — including the parent portal's separate early-return page — now opens with a small, secondary "Today • Friday, 6 Tikimt 2019 (Oct 16, 2026) · 2026/2027" line (`resources/js/Components/TodayHeader.jsx`), reading a `today` object shared on every single Inertia request (`HandleInertiaRequests::share()`), computed server-side from `App\Support\SchoolCalendar` — the business timezone (`Africa/Addis_Ababa`), never the browser's clock, and already respecting the school's own calendar preference (Ethiopian/Gregorian/both) through the same mechanism §9 documents. This also fixed a real, verified bug: `HomeTasks.jsx`'s and `TeacherHome.jsx`'s "Good morning/afternoon/evening, {name}" greeting each independently computed the hour from `new Date().getHours()` — the visiting device's own clock — so a browser set to a different timezone than the school showed the wrong time-of-day greeting, inconsistent with every other "today" computation in the app. Both now read the same shared `today.hour`, through one small helper (`resources/js/greeting.js`) instead of each duplicating the same threshold logic. Verified live (Playwright, both desktop and a 390px-wide mobile viewport, zero horizontal overflow) against all four dashboard variants plus the receptionist's HomeTasks view.

---

## 20. Human Resources & Payroll

A full HR module — distinct from `manage-staff` (which governs login accounts, roles and permissions) — covering employee profiles, departments/positions, employment contracts, staff attendance, leave, performance reviews, and an Ethiopian-specific payroll engine. It was built in six dated phases (A–F), each adding its own permission pair and, in most cases, its own table.

### 20.1 Permissions & defaults

Permission group `hr`: `employee-view`, `employee-manage`, `contract-view`, `contract-manage`, `staff-attendance-view`, `staff-attendance-manage`, `leave-view`, `leave-request`, `leave-approve`, `performance-view`, `performance-manage`, `payroll-view`, `payroll-manage`. Owner gets all 13; branch_admin gets all 13 by default too; teacher and accountant get **only** `leave-request` (deliberate self-service for every staff tier, not just HR/admin); parent/student get none; auditor gets none directly but every HR controller's `canView()` also accepts `hasRole('auditor')` as a blanket read bypass.

The per-controller pattern is consistent everywhere: a broader `-view` permission OR the matching `-manage` permission OR auditor. `LeaveTypeController::canView` additionally allows a plain `leave-request` holder to see the leave-type catalog (they need it to pick a type when self-filing). Branch scoping: every HR controller enforces org-wide-or-own-branch, directly on `Employee`/`PayrollRun` or indirectly via the owning `Employee` for Contract/Attendance/Leave/Performance.

### 20.2 Database schema

See §4 ("Human Resources & Payroll") for the full column-level detail of all 14 tables: `departments`, `positions`, `employees`, `employment_contracts`, `staff_attendances`, `staff_attendance_corrections`, `leave_types`, `leave_allocations`, `leave_requests`, `performance_review_cycles`, `performance_reviews`, `payroll_tax_brackets`, `payroll_runs`, `payslips`, plus the `schools` columns for pension rates and the five payroll GL account mappings.

### 20.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET/GET/POST/GET/GET/PUT | `/employees`, `/employees/new`, `/employees`, `/employees/{e}`, `/employees/{e}/edit`, `/employees/{e}` | EmployeeController | view/view/manage/view/manage/manage |
| GET/POST/PUT/DELETE | `/departments`, `/departments`, `/departments/{d}`, `/departments/{d}` | DepartmentController | view/manage/manage/manage |
| GET/POST/PUT/DELETE | `/positions`, `/positions`, `/positions/{p}`, `/positions/{p}` | PositionController | view/manage/manage/manage |
| POST/PUT/DELETE/GET | `/employees/{e}/contracts`, `/employment-contracts/{c}`, `/employment-contracts/{c}`, `/employment-contracts/{c}/document` | EmploymentContractController | manage/manage/manage/view |
| GET/POST/GET/POST/POST/POST | `/staff-attendance`, `/staff-attendance`, `/staff-attendance/corrections`, `/staff-attendance/{a}/corrections`, `/staff-attendance-corrections/{c}/approve`, `/.../reject` | StaffAttendanceController | view/manage/view/manage/manage/manage |
| GET/POST/PUT/DELETE | `/leave-types`, `/leave-types`, `/leave-types/{t}`, `/leave-types/{t}` | LeaveTypeController | view(+request)/approve/approve/approve |
| POST/PUT/DELETE | `/employees/{e}/leave-allocations`, `/leave-allocations/{a}`, `/leave-allocations/{a}` | LeaveAllocationController | approve (all) |
| GET/GET/POST/PUT/DELETE/POST/POST/GET | `/my-leave`, `/leave-requests/pending`, `/employees/{e}/leave-requests`, `/leave-requests/{r}`, `/leave-requests/{r}`, `/leave-requests/{r}/approve`, `/.../reject`, `/.../document` | LeaveRequestController | self or approve/view/self-or-approve ×3/approve ×2/view-or-self |
| GET/POST/PUT/DELETE | `/performance-cycles`, `/performance-cycles`, `/performance-cycles/{c}`, `/performance-cycles/{c}` | PerformanceReviewCycleController | view/manage/manage/manage |
| GET/POST/PUT/DELETE | `/my-performance`, `/employees/{e}/performance-reviews`, `/performance-reviews/{r}`, `/performance-reviews/{r}` | PerformanceReviewController | none (self)/manage/manage/manage |
| GET/PUT/POST/PUT/DELETE | `/payroll-settings`, `/payroll-settings/pension`, `/payroll-settings/brackets`, `/payroll-settings/brackets/{b}`, `/.../brackets/{b}` | PayrollSettingsController | view/manage/manage/manage/manage |
| GET/GET/POST/GET/POST/DELETE | `/my-payslips`, `/payroll-runs`, `/payroll-runs`, `/payroll-runs/{r}`, `/payroll-runs/{r}/finalize`, `/payroll-runs/{r}` | PayrollRunController | none (self)/view/manage/view/manage/manage |

Viewing your own leave (`/my-leave`), your own finalized performance reviews (`/my-performance`), and your own finalized payslips (`/my-payslips`) each require **zero permission** — gated purely on having a linked `Employee` record, the same self-service shape repeated three times.

### 20.4 Business rules

**Departments & Positions** are pure org-chart scaffolding gated entirely on `employee-manage` (no separate per-entity permission). A department can be org-wide or branch-specific; a non-org-wide admin creating one is silently pinned to their own branch. A department can optionally name one Employee as its manager. Positions ("Job Title") optionally belong to a department but are independent, never conflated with a Spatie role. Both block deletion while still referenced, surfaced as a friendly validation error.

**Employee records & contracts.** An Employee is fully decoupled from the login `User` — nothing in `EmployeeController` ever touches roles/permissions/passwords; linking (`user_id`) only connects two already-independent records and is audited with its own action name. Only staff-tier accounts without an existing employee link are offered as linkable; one `User` links to at most one `Employee`. `employee_number` auto-generates via the shared `NumberSequence` sequencer. Employment contracts carry full history — a renewal is always a *new* contract row; once a contract has ever left `draft` it becomes permanent, undeletable history. `salary_reference` is the field `Payroll::generate()` reads as basic salary. Contract documents (PDF only) live on the private disk, served only through the gated `document()` action. A daily `hr:check-contract-expiry` console command auto-transitions `active`→`expiring` 30 days before `end_date` (notifying once, guarded by `expiry_notified`) and `active`/`expiring`→`expired` once past. **Fixed 2026-10-16**: this command, along with the new `library:check-overdue-loans` (§25.6), is now registered in `routes/console.php`'s scheduler (confirmed via `schedule:list` showing the correct UTC-converted fire time) — previously it existed only as a command runnable by hand. Whether the production server's cron actually invokes `php artisan schedule:run` every minute still depends on the `schoolerp-scheduler` Render Cron Job documented in `DISASTER_RECOVERY.md`; this is a real-world deployment-configuration fact that **requires real-world verification**, not something confirmable from the code alone.

**Staff attendance** is deliberately HR/admin-recorded for everyone — no self-service check-in exists — and wholly separate from student attendance. The daily roster writes the *first* record for a (employee, date) pair directly; a later change must go through a `StaffAttendanceCorrection` requiring a second approver, more two-person-controlled than student attendance because staff attendance feeds downstream overtime/leave calculations. The requester of a correction can never approve or reject it themselves. **Fixed 2026-10-16**: the daily roster's batch save (one `foreach` loop writing every employee's record for the day) is now wrapped in `DB::transaction()`, the same fix applied to student attendance (§7) for the identical reason — a mid-batch failure now rolls back completely instead of leaving some employees marked and others not. Verified by a new regression test in `tests/Feature/StaffAttendanceTest.php`.

**Leave.** Types are school-configurable; `default_annual_days` is a UI suggestion only, never auto-applied. Allocation balances are never cached — summed live every call. Requests follow Draft→Submitted→Approved/Rejected: a draft is freely edited/deleted, but submitting makes it permanent history. Submitting is blocked by the leave type's `requires_document` flag (unless attached) and a date-overlap check against the employee's other submitted/approved requests. `leave-request` is explicitly self-service, granted by default to every staff tier. Approval enforces a **doubled** self-review ban: the reviewer can never be the original submitter AND can never be the employee the leave is *for* — stronger than elsewhere because HR might submit on someone's behalf.

**Performance.** A cycle is just a named grouping (e.g. "Mid-Year 2026"). No self-service permission exists at all — an employee never authors or requests their own review. One review per employee per cycle; `draft`→`finalized` is one-way (deletion blocked once finalized); a reviewer can never author or finalize a review of their own linked employee record.

**Payroll.** Pension rates (employee 7% / employer 11%, per Pension Proclamation 1268/2022) are two editable decimal columns on `schools`; progressive income-tax brackets (per Income Tax Proclamation Amendment 1395/2025) are a fully editable per-school table, seeded once and flagged throughout the UI as needing legal verification. `Payroll::calculateTax()` finds the covering bracket and applies `(income × rate) − deduction` — both PAYE and pension computed on **full gross salary**, not a pension-deducted base. A run is scoped to one branch + one calendar month (unique DB constraint). `Payroll::generate()` creates a `draft` run and iterates every `active` employee in that branch, skipping (with a visible, named reason) anyone with no current contract or no `salary_reference`. A `draft` run can be freely deleted and regenerated; `finalize()` is the one irreversible step — it locks every payslip, notifies each linked employee (`PayslipReady`), and attempts a best-effort journal-entry post to Accounting (silently no-ops, logging a warning, if the school hasn't configured all five `default_*_account_id` mappings, has no payslips, or zero gross pay — never blocks finalization).

### 20.5 Pages

17 pages under `resources/js/Pages/Hr/`, roughly in admin-then-employee encounter order: **Departments/Index** and **Positions/Index** (both flat lists with a modal dialog, `employee-manage`-gated). **Employees/Index** (card-grid roster with status badges). **Employees/Create** (4-step `WizardShell`: Personal → Employment → Other (optional account link) → Review). **Employees/Edit** (flat stacked sections, no wizard). **Employees/Show** (the HR hub for one person — header/Employment/Contact/linked-account cards, then four independently permission-gated sections: Contracts, Leave, Performance, Payroll, each only visible to its own `-view`/`-manage` holder; the Performance section's manage button is explicitly suppressed when the viewer is reviewing themselves). **Attendance/Index** (date-picker roster split into "Not yet marked" with 4-button status cards and "Already marked" with a correction-request button). **Attendance/Corrections** (the approval queue, with an explicit "you cannot review your own request" disabled note rather than just a server 403). **LeaveTypes/Index**. **Leave/Pending** (the HR review queue). **Leave/Mine** (self-service: balances + request history + "Request leave" if types exist). **PerformanceCycles/Index**. **Performance/Mine** (pure read-only, finalized reviews only — drafts never shown). **Payroll/Settings** (a persistent "verify against current law" warning banner, pension-rate inputs, tax-bracket CRUD). **Payroll/Runs/Index** and **Payroll/Runs/Show** (status badge, skipped-employee warnings, a payslip table with totals, Finalize/Remove only while draft — once finalized the action bar disappears entirely). **Payroll/Mine** (self-service payslip list, read-only).

### 20.6 Notifications & audit trail

Seven in-app-only notification classes: `LeaveRequestSubmitted`/`LeaveRequestReviewed`, `ContractExpiringSoon` (fired once per contract via `expiry_notified`), `AttendanceCorrectionRequested`/`AttendanceCorrectionReviewed`, `PerformanceReviewFinalized`, `PayslipReady`. None ever include salary, tax, pension figures, or leave/correction reasons in the notification body — sensitive detail always stays behind the permission-gated screen it belongs to. Every HR model uses the generic `Audited` trait; action-name overrides: `employee.account_linked`/`account_unlinked`/`status_changed`, `employment_contract.status_changed`, `staff_attendance_correction.{pending|approved|rejected}`, `leave_request.{draft|submitted|approved|rejected}`, `performance_review.finalized`, `payroll_run.finalized`. `Payslip` explicitly disables per-row creation logging (`auditsCreation() => false`) since a single run can create dozens at once.

---

## 21. Inventory & Assets

A consumable-stock and fixed-asset tracking module, built in four phases (A: catalog + stock ledger; B: requisitions; C: assets; D: stocktakes).

### 21.1 Permissions & defaults

Permission group `inventory`: `inventory-view`, `inventory-manage`, `stock-receive`, `stock-issue`, `stock-transfer`, `stock-adjust`, `requisition-create`, `requisition-approve`, `requisition-issue`, `asset-view`, `asset-manage`. Owner gets all 11 automatically; **every other tier, including branch_admin, gets none by default** — each is turned on per account deliberately (e.g. a "Storekeeper" title), confirmed by the migration docblocks. Auditor's blanket read access again comes from `hasRole('auditor')` checks, not a permission grant. Two relevant `schools` columns: `allow_negative_stock` (default false — when false, a decrease that would go negative throws instead) and (shared with Procurement) `purchase_approval_threshold`.

### 21.2 Database schema

See §4 ("Inventory & Assets") for full column detail of all 11 tables: `inventory_categories`, `inventory_stores`, `inventory_items`, `stock_balances`, `stock_movements`, `requisitions`, `requisition_items`, `assets`, `asset_events`, `stocktakes`, `stocktake_lines`.

### 21.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET/GET/POST/GET/PATCH/DELETE | `/inventory/items`(index/new/store/show/{i}/{i}) | InventoryItemController | view ×2 / manage ×4 |
| GET/POST/PATCH/DELETE | `/inventory/stores` | InventoryStoreController | view/manage ×3 (+ same-branch check) |
| POST/PATCH/DELETE | `/inventory/categories` | InventoryCategoryController | manage ×3 |
| GET/POST ×4 | `/inventory/movements`(index/receive/issue/transfer/adjust) | StockMovementController | view / stock-receive / stock-issue / stock-transfer / stock-adjust |
| GET ×3/POST ×5 | `/requisitions`(index/new/show)+(store/destroy/submit/approve/reject/issue) | RequisitionController | create or approve or issue (view); create (write); owner-draft; approve+branch+submitted ×2; issue+branch+approved-or-partial |
| GET ×2/POST ×10 | `/assets`(index/new/show) + assign/reassign/return/transfer/repair-start/repair-complete/mark-lost/mark-damaged/retire/dispose | AssetController | asset-view / asset-manage (each with its own precondition on current `status`) |
| GET ×2/POST ×2 | `/stocktakes`(index/show) + store/counts/complete | StocktakeController | stock-adjust throughout |

### 21.4 Business rules

**Stock ledger — `App\Services\Stock` is the only path that ever changes a quantity.** Every call (`opening`, `receive`, `returnStock`, `issue`, `markDamaged`, `markLost`, `transfer`, `adjust`) writes one immutable `StockMovement` row and updates the matching `StockBalance` in the same transaction, under `lockForUpdate()`. `StockBalance` rows are lazily created at 0 on first touch via `insertOrIgnore` (done without a lock first, to avoid a gap-lock deadlock when two first-movements race — the same two-step pattern `NumberSequence::next()` uses). If a decrease would go negative and `allow_negative_stock` is false, a validation error is thrown instead. `transfer()` is two linked movements (`transfer_out`/`transfer_in`) joined by `related_movement_id`; `quantity` is always stored positive, direction implied by `type`. Movements are immutable by model guard — a mistake is corrected with a new, opposite movement, never an edit.

**Low-stock notification** fires only at the exact moment a decrease crosses `reorder_level` from above to at-or-below it — never repeatedly while stock stays low.

**Requisitions (draw-down workflow).** States: `draft → submitted → approved/rejected → partially_issued → fully_issued/completed`. Approval is **per-line** (the approver sets `quantity_approved` ≤ requested, independently per line); rejection is all-or-nothing with a required reason. Issuing is **per-line and incremental**, capped at `quantity_approved − quantity_issued`; the header status recomputes after every issue. A draft can be deleted only by its own requester; anything past draft can never be hard-deleted.

**Assets.** A one-of-a-kind item with its own tag (`AST-{year}-{seq}`), managed through `App\Services\Assets`, which mirrors `Stock`'s pattern — one immutable `AssetEvent` plus a status/assigned_to/current_store_id snapshot update, per transaction. State machine: `available ⇄ assigned` (assign/return/reassign), `available → available` (transfer, different store), `{available,assigned} → under_repair → {assigned-or-available | damaged}` (repair start/complete with outcome), any non-disposed state `→ retired`/`→ disposed`. `markLost`/`markDamaged` have no precondition. Nothing in Procurement ever creates an Asset row (goods receipt only moves consumable stock, never fixed assets). **Supplier linkage, added 2026-10-16**: an asset can now optionally carry a real `supplier_id`→`suppliers` FK alongside its original free-text `supplier` field — closing a previously disclosed gap where an asset's supplier was structurally disconnected from the rest of Procurement. The Create form lets the user either pick an existing Supplier or type a name not yet in the system (which leaves `supplier_id` null and only fills the free-text field, so nothing is forced into the Supplier table just to record an asset purchase); `AssetController::show()` exposes the linked supplier only to a viewer who also holds supplier-viewing rights (`canViewSuppliers`), otherwise `supplier_id` is nulled out of the payload even if it's set — the same "capability flag, never a raw field" pattern used for medical/discipline data (§29.1).

**Stocktakes.** One per store at a time. Starting one snapshots every item's *current* balance into `system_quantity`. Completion is blocked unless every line has a physical count AND every differing line has a reason. On completion, each differing line becomes one `Stock::adjust()` call against the **live** balance at that moment — deliberately not the stale snapshot, since real movements can happen mid-count.

### 21.5 Pages

**Inventory/Items/Index** — catalog with low-stock/out-of-stock badges, inline per-permission Receive/Issue/Transfer buttons opening a shared movement-dialog modal. **Items/Create**, **Items/Show** (per-store balance breakdown, paginated history, plus an Adjust dialog not shown on the index). **Stores/Index** (inline add/remove, branch picker for org-wide users). **Movements/Index** (the cross-item ledger report). **Requisitions/Create** (3-step `WizardShell`: Where → Items → Review, with a "save as draft" escape hatch alongside Submit). **Requisitions/Index**/**Show** (per-line approved/issued counts; approve form with per-line editable quantities; per-line issue buttons). **Assets/Index** (status-toned badges), **Create** (now includes a supplier picker — existing Supplier dropdown or an "other" free-text fallback), **Show** (action buttons computed from current status, each opening a small targeted modal; a separate "Other actions" card groups the destructive/terminal ones away from everyday ones; the linked supplier, when set and visible to the viewer, renders as a link through to that Supplier's own page). **Stocktakes/Index**/**Show** (physical-quantity inputs, a live difference badge, a reason input that appears only for non-zero differences, Complete disabled until every rule is satisfied).

### 21.6 Notifications & audit trail

`LowStockAlert`, `RequisitionSubmitted`/`Approved`/`Rejected`, `AssetAssigned`, `AssetRepairCompleted` — all in-app only. Every model here uses the `Audited` trait; `Requisition`/`Asset`/`Stocktake` override `auditAction()` to `.status_changed` when status changes; `StockMovement`/`AssetEvent` can in practice only ever log a `.created` entry since their models block update/delete outright.

---

## 22. Procurement & Suppliers

The purchasing pipeline: supplier management, then purchase request → (optional quotations) → purchase order → goods receipt → supplier bill → supplier payment, feeding both Inventory's stock ledger and the Accounting engine's GL.

### 22.1 Permissions & defaults

Permission group `procurement`: `supplier-manage`, `purchase-request-create`, `purchase-approve`, `purchase-approve-large`, `quotation-manage`, `purchase-order-manage`, `goods-receipt-manage`, `supplier-bill-manage`, `supplier-payment-manage`. Same shape as Inventory: owner gets all 9 automatically, every other tier (including branch_admin) gets **none** by default. `schools.purchase_approval_threshold` (nullable decimal) is the two-tier approval switch — null means every request only ever needs plain `purchase-approve`.

### 22.2 Database schema

See §4 ("Procurement & Suppliers") for full column detail of all 12 tables: `suppliers`, `purchase_requests`, `purchase_request_items`, `quotations`, `quotation_items`, `purchase_orders`, `purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `supplier_bills`, `supplier_bill_items`, `supplier_payments`.

### 22.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET ×2/POST/PATCH/DELETE | `/suppliers`(index/new/show)+store/update/destroy | SupplierController | view ×3 / manage ×3 |
| GET ×2/POST ×3/DELETE | `/purchase-requests`(index/new/show)+store/submit/approve/reject/destroy | PurchaseRequestController | create or approve (view); create; owner-draft; approve (or large)+branch+requester≠approver ×2 |
| POST/POST/DELETE | `/purchase-requests/{r}/quotations`, `/quotations/{q}/select`, `/quotations/{q}` | QuotationController | quotation-manage + request approved; destroy blocked if selected |
| GET/POST/GET/POST ×2/DELETE | `/purchase-orders`(index)+store+show+send/cancel+destroy | PurchaseOrderController | view/manage (request approved)/view+branch/manage ×2/manage-draft |
| POST | `/purchase-orders/{po}/goods-receipts` | GoodsReceiptController | goods-receipt-manage + PO sent-or-partial |
| GET ×2/POST/GET | `/supplier-bills`(index/new)+store+show | SupplierBillController | view ×2/manage/view+branch |
| POST | `/supplier-bills/{b}/payments` | SupplierPaymentController | supplier-payment-manage + branch |

**No dedicated index/show route exists for Quotations or Goods Receipts** — both are nested resources, created and managed entirely from `PurchaseRequests/Show` (quotations) and `PurchaseOrders/Show` (goods receipts).

### 22.4 Business rules — end-to-end lifecycle

1. **Purchase Request**: a `purchase-request-create` holder builds N lines, each either a catalog item or a free-text service, with quantity + optional estimated unit price. Saves as `draft` or `submitted`. `estimated_total` per line is computed server-side at creation and never recomputed later even if quantities change downstream.
2. **Approval threshold.** `needsLargeApproval()` = true iff `purchase_approval_threshold` is set and the estimated total exceeds it — then `purchase-approve-large` is required instead of plain `purchase-approve`, both to see and to click Approve/Reject. The requester can never approve their own request even if they also hold the approver permission. Submitting notifies only whichever permission group is actually required.
3. Once `approved`, two children become available, both gated on the request still being `approved`:
   - **Quotations**: any number of suppliers, one priced line per *request* line (never free lines), for side-by-side comparison. `selected` is set **only** by an explicit action — nothing picks automatically. A selected quotation can't be deleted until deselected.
   - **Purchase Orders**: built against the request, optionally pre-filled from the selected quotation (a UI convenience only — `quotation_id` is traceability-only, never re-validated). Each PO line is capped at `requested − already-ordered-elsewhere` — **a request line can be split across multiple POs/suppliers but never over-ordered in total.** `draft → sent → {partially_received|received}`, or `cancelled` (with required reason, never from `received`); only a `draft` PO can be hard-deleted.
4. **Goods Receipt**, only against a `sent`/`partially_received` PO: one line per PO line, capped at `ordered − already-received`, so **partial and repeated receipts against the same PO are explicitly supported.** Each receipt line calls `Stock::receive()` **only if** the originating request line had a catalog item (service-only lines never touch stock) — this is the one place Procurement feeds Inventory's ledger. PO status recomputes after every receipt; if the whole PO becomes `received` and the originating request is now fully received across every PO against it, the original requester is notified (`PurchaseRequestFulfilled`) — the closing event of the chain. Goods receipts are permanent and immutable once created.
5. **Supplier Bill** (the AP invoice, explicitly deferred to Accounting) can exist with no PO at all (sole-source purchase) or reference one. A PO-tied line is capped at `received − already-billed` — **the three-way match: never billed for more than physically received**, summed live across every bill. A non-PO line needs an expense account (falls back to the school default). Saving posts a best-effort GL entry (never blocks the bill on a posting failure).
6. **Supplier Payment** against a bill — always "confirmed" on creation, no verify step (unlike the parent-facing `Payment`, since no external party submits proof for money going out). Checked under a row lock against the bill's live balance; also posts a best-effort GL entry. **The payment record itself is still never edited or deleted** — it's corrected instead through a new **Supplier Payment Reversal** workflow (added 2026-10-16, closing what had been a real, disclosed gap — previously a wrong payment genuinely had no path except a manual journal entry): `SupplierPaymentController@reverse` creates a `SupplierPaymentReversal` row under a lock against the payment's still-outstanding (un-reversed) amount, with a required `kind` (`returned_goods`/`payment_error`/`duplicate_payment`/`other`) and an optional reason. `SupplierPayment::netAmount()` (= `amount` minus the sum of its reversals) is what `SupplierBill::paidAmount()` now sums across every payment, so a partially- or fully-reversed payment correctly frees up the bill's outstanding balance again. A best-effort reversing GL entry posts through `AccountingPostings::postSupplierPaymentReversed()`, same non-blocking discipline as every other posting in this module. `SupplierBillController::show()` now exposes, per payment, whether the viewer can reverse it and its own reversal history, surfaced directly in the UI (§22.5).

**Status enums per stage:** PurchaseRequest `draft/submitted/approved/rejected` (no partial state). PurchaseOrder `draft/sent/partially_received/received/cancelled`. Quotation has no status, just `selected`. GoodsReceipt/SupplierBill/SupplierPayment have no status at all — each is a fully-formed, immutable fact the instant it's created.

### 22.5 Pages

**Suppliers/Index**/**Create**/**Show** (editable form, disabled fieldset for auditors, archive-with-confirm). **PurchaseRequests/Create** (3-step wizard, same shape as Requisitions). **PurchaseRequests/Show** — the busiest screen: header with a large-approval-needed alert, items list, and (once approved) an embedded Quotations section (select/remove, exclusive selection) and Purchase Orders section (summary + links, "Add PO" only while some line still has quantity remaining, excluding fully-ordered lines entirely from the form). **PurchaseOrders/Index**/**Show** (received/ordered progress per line; an embedded Goods Receipts section listing every receipt so far, "Add goods receipt" only showing lines still expecting something, pre-filled to the full remaining amount). **SupplierBills/Index**/**Create** (optional "from order" picker that pre-fills and caps lines)/**Show** (an embedded Payments section, "Add payment" pre-filled to the full remaining balance; each payment row that the viewer is allowed to reverse shows a "Reverse" action opening `SupplierPaymentReversalDialog` — a kind picker + optional reason + amount capped at the payment's still-outstanding balance — with any prior reversals against that payment listed underneath it).

### 22.6 Notifications & audit trail

`PurchaseRequestSubmitted`/`Approved`/`Rejected`/`Fulfilled` — all in-app only. **No notification exists** for quotation recorded/selected, PO sent/cancelled, goods receipt recorded, supplier bill recorded, supplier payment recorded, or a payment reversed — none of those seven events notify anyone (a disclosed gap, §33). Every model uses `Audited`, including the new `SupplierPaymentReversal`; `Requisition`-style `.status_changed` overrides apply to `PurchaseRequest`/`PurchaseOrder`; `Quotation::auditAction()` → `quotation.selected`/`.deselected`/`.updated`.

---

## 23. Accounting Engine (Double-Entry Ledger)

A genuine double-entry accounting system — chart of accounts, journals, journal entries, accounting periods — deliberately **separate from** the existing simple Finance system (§8, fees/invoices/payments) that ordinary staff already use day-to-day.

### 23.1 Permissions & defaults

Permission group `accounting`: `accounting-view`, `accounting-manage`, `accounting-period-manage`, `accounting-period-reopen`. **None of these 4 permissions appear in any tier's default set except owner** — not even branch_admin. Out of the box, only `owner` can see Accounting at all; every other account needs it toggled on individually.

### 23.2 Database schema & the balancing rule

See §4 ("Accounting Engine") for the 5 tables: `accounts`, `journals`, `accounting_periods`, `journal_entries`, `journal_entry_lines`.

**Debit/credit representation:** two separate decimal columns (`debit`, `credit`) on `journal_entry_lines`, never a signed single amount or a side enum — exactly one is ever nonzero on a given line, enforced only in code (`App\Services\Ledger::replaceLines()`), not a DB CHECK constraint (cross-column checks vary too much across supported DB drivers to lean on one). **Balancing is enforced only at `post()` time, not at save time** — a draft entry may have unequal or single-sided totals, explicitly allowed as "a work in progress." `Ledger::post()` is the one gate requiring `SUM(debit) = SUM(credit)` (compared in integer cents) and a nonzero total, before flipping status to `posted`. There is no DB-level balance constraint anywhere.

### 23.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET/POST/PATCH/DELETE/POST | `/accounting/accounts`+{a}+install-template | AccountController | view/manage ×4 |
| GET/POST/PATCH | `/accounting/journals`+{j} | JournalController | view/manage ×2 |
| GET/POST/POST/POST | `/accounting/periods`+store/close/reopen | AccountingPeriodController | view/period-manage ×2/period-reopen |
| GET/GET/POST/GET/GET/PATCH/POST/POST/DELETE | `/accounting/journal-entries`(index/create)+store+{e}(show/edit)+update+post+reverse+destroy | JournalEntryController | view/manage ×6 (edit/update/destroy draft-only) |

### 23.4 Business rules

**Chart of accounts.** Each school installs its own chart via a one-time, explicit, idempotent action (`AccountController::installTemplate` → `ChartOfAccountsTemplate::install()`), never auto-run by a migration or seeder. The template is a 5-branch hierarchy (Assets → Cash/Undeposited Funds/Bank/AR; Liabilities → AP/Income Tax Payable/Pension Payable/Net Salary Payable; Equity → Retained Earnings; Revenue → Tuition/Registration/Discounts/Waivers; Expenses → Salaries/Pension/Utilities/Supplies/General) plus 8 journals (CASH, BANK, SALES, PURCH, GEN, AR, AP, PAYROLL). Install is re-runnable (matched by `code`) and wires the school's `default_*_account_id` columns to the `system`-flagged accounts. A school can rename/recode/deactivate/add accounts freely afterward; `is_system` accounts can never be deleted.

**Journal entry lifecycle.** `App\Services\Ledger` is the *only* path that creates/posts/reverses an entry. `createDraft()` looks up the accounting period covering the entry date, throwing if none is open. `post()` runs inside a transaction with the entry row locked: requires `draft` status, ≥1 line, exact debit=credit equality in cents, a nonzero total, every line's account non-header/active/branch-matching, and the covering accounting period still `open` at the moment of posting (re-checked even if it was open when drafted). Once posted, the entry and its lines are immutable except for one allowed mutation.

**Reversal.** `Ledger::reverse()` creates a brand-new draft with every line's debit/credit swapped, dated **today** (never the original date — always into whatever period is open now, never a possibly-closed original period), posts it immediately, and links both entries bidirectionally. The original is never edited beyond that link. An already-reversed entry can't be reversed again.

**Accounting periods.** `open`/`closed` only. A period can only be posted into while open — closing doesn't retroactively protect already-posted entries, it blocks new postings into that date range. `accounting-period-manage` opens (overlap-validated) and closes; the stronger, separate `accounting-period-reopen` is required to undo a close.

**Relationship to the existing simple Finance system.** The two systems are architecturally independent, connected only through `App\Services\AccountingPostings`: every fee/payment/procurement/payroll event in the old system *optionally* mirrors itself into a double-entry posting, strictly best-effort — if the school hasn't installed a chart of accounts, every posting method quietly no-ops, and callers wrap the calls in try/catch so a posting failure can never block the real action money has already changed hands for. Concretely: invoice issued → Dr AR / Cr Revenue; discount/waiver changed → posts only the *delta* since last recorded; payment verified → Dr Cash-Bank / Cr AR (fired only on verification, matching Finance's own "only verified counts" rule exactly); payment refunded → Dr AR / Cr Cash-Bank; supplier bill recorded → Dr Expense(s) / Cr AP; supplier payment recorded → Dr AP / Cr Cash-Bank; payroll run finalized → Dr Salary+Pension Expense / Cr Tax+Pension+Salary Payable, one aggregated entry per run. **`Invoice`/`Payment`/`PaymentRefund` remain the single source of truth for what a family owes/paid** — nothing in the accounting engine is ever read back to answer that question; the Reconciliation report (§24) exists specifically to catch any drift between the ledger's AR/AP control-account balances and the fee system's own live totals, treated as a real bug in the posting engine if it ever happens, never papered over.

### 23.5 Pages

**Accounting/Accounts/Index** — recursive indented tree of the chart; an "install the default chart" prompt if none exists yet; code/type are immutable after creation (no edit field for either). **Journals/Index** — flat list, active/inactive toggle. **Periods/Index** — Close only shown for `open` periods (requires `accounting-period-manage`), Reopen only for `closed` periods (requires the separate `accounting-period-reopen`), both confirmed. **JournalEntries/Index** — card grid filterable by status. **Create** (shared create/edit, edit only while draft) — dynamic debit/credit line list with mutually-exclusive inputs, a client-side unbalanced warning that does **not** block submission of a structurally valid draft (the server's own "draft needn't balance" rule, surfaced in the UI too). **Show** — Post/Edit/Remove for a draft; Reverse (with an inline reason) for a posted, not-yet-reversed entry; reversal links shown as colored alert banners in either direction.

---

## 24. Financial & Operational Reports

A read-only reporting layer: a single catalog page plus eight Accounting-backed financial reports, all server-rendered HTML — **no PDF/Excel export exists for any of these eight**, unlike the older Finance module's exports (§8).

### 24.1 Routes

`GET /reports` (ReportsController@index — any authenticated user; each listed item is itself permission-filtered server-side using the exact same check its own target controller already enforces, never hidden only in the browser); `GET /accounting/reports/{ledger|trial-balance|income-statement|balance-sheet|cash-summary|ar-aging|ap-aging|reconciliation}` (FinancialReportController, all requiring `accounting-view`/`-manage`/auditor).

### 24.2 What each report shows

- **Reports/Index** — a catalog grouped into `academic`/`finance`/`accounting`/`people`/`oversight`; an empty group is dropped entirely; items marked `download: true` (the pre-existing invoices/students/staff Excel exports) render a download button, everything else a plain "View" link.
- **Ledger** — one account's full transaction history over an optional date range, with a computed opening balance and a running balance signed per that account's own normal side.
- **Trial Balance** — every non-header active account as of a date on its normal side; shows a red "unbalanced" warning if totals don't match (should never happen if the posting engine is sound).
- **Income Statement** — revenue minus expense over a date *range* (never cumulative since inception); contra-revenue accounts (discount/waiver) net directly into the revenue total.
- **Balance Sheet** — as-of-date Asset/Liability/Equity balances; since the system never posts a period-closing entry, net income to date is folded live into Equity so Assets = Liabilities + Equity always holds (checked to a small tolerance).
- **Cash Summary** — every leaf asset account except the AR control account (which has its own Aging report).
- **AR Aging** — outstanding invoice balances, read live from the existing `Invoice::balance()` (never recomputed independently), bucketed by days since `period_start` (invoices have no due date, so this is the honest proxy) into current/1-30/31-60/61-90/90+.
- **AP Aging** — the mirror, bucketed from `SupplierBill`'s `due_date` (or `bill_date` if none), over `SupplierBill::balance()`.
- **Reconciliation** — compares the ledger's AR/AP control-account balances against the fee/procurement systems' own independently-computed live totals; shows match/diff per side; shows "not installed yet" if the school has no chart of accounts. Safe to re-run any time.

Every figure is described in the controller's own comments as "a live query, never a separately duplicated total" — any discrepancy is framed explicitly as a bug in the posting engine, never a reporting bug.

---

## 25. Library

Book cataloging, physical-copy tracking, and a loan/fine workflow — deliberately modeled differently from Inventory's fungible stock-balance approach, because each physical book is an individually trackable thing, not an interchangeable unit in a count.

### 25.1 Permissions & defaults

Permission group `library`: `library-view`, `library-manage`. **Neither is in any tier's default set except owner** — not even branch_admin, "the same 'not a new role' shape Inventory already established... deliberately NOT in branch_admin's default bundle." A librarian is any staff account these are explicitly toggled on for.

### 25.2 Database schema

See §4 ("Library") for `books`, `book_copies`, `library_loans`, plus the two `schools` settings columns (`library_loan_days` default 14, `library_fine_per_day` nullable — NULL means fines are disabled entirely, not a zero rate). **No "max loans per student" setting exists anywhere** — checked specifically, not assumed.

### 25.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET | `/library` | LibraryLoanController@index | view/manage/auditor |
| GET | `/my-library-loans` | LibraryLoanController@mine | none — any signed-in staff, self-service |
| GET | `/library/borrowers/search` | LibraryLoanController@borrowerSearch | view/manage/auditor |
| POST ×6 | `/library/issue`, `/library/loans/{l}/{return\|renew\|lost-or-damaged\|waive-fine\|pay-fine}` | LibraryLoanController | manage throughout |
| GET/PUT | `/library/settings` | LibrarySettingsController | view/manage |
| GET/POST/GET/PUT/DELETE | `/library/books`(index/store/show/{b}/{b}) | BookController | view ×2/manage ×3 |
| POST/DELETE/POST ×2 | `/library/books/{b}/copies`+{c}+lost-or-damaged+restore | BookCopyController | manage throughout |

### 25.4 Business rules

**Why Book vs. BookCopy.** `Book` is the school-wide catalog title (shared across branches, like `Period`); `BookCopy` rows are the actual trackable units, branch-scoped (like `Room`) — deliberately not modeled like Inventory's single-quantity-per-store approach, since each copy has its own individual lifecycle. All state changes go through `App\Services\Library`, the same single-writer-service discipline as `Stock`/`Ledger`.

**Loan lifecycle.** `issue()` locks the copy, requires `available`, creates a loan with `due_date = today + library_loan_days`, flips the copy to `issued`. Borrower is polymorphic — a `Student` (issued by staff on their behalf, since students have no login) or a staff `User`. `returnCopy()` computes the fine as of today and flips the copy back to `available`. `renew()` pushes `due_date` to today + loan-days again (not additive) and increments `renewed_count` — no cap found anywhere. A copy found lost/damaged *outside* an active loan is closed directly on the copy; one currently on loan is closed through the loan instead (since that also has a borrower and fine to settle).

**Fines — explicitly self-contained, confirmed by code comments as a decision made with the user: "there is no path from here into Invoice/Payment/accounting at all."** Resolution is two independent plain timestamps a librarian sets directly (`waiveFine()`/`payFine()`) — no amount transfer, no GL posting, no linkage to `AccountingPostings`. `calculateFine()` returns `null` (not zero) if `library_fine_per_day` is unset; otherwise `daysLate × rate`, only if positive.

**Overdue reminder — added 2026-10-16**, closing what had been a real, disclosed gap ("no notification exists at all for overdue loans"). A new daily console command, `library:check-overdue-loans`, finds every `active` loan whose `due_date` has passed and whose `overdue_notified_at` is still null, sends a `LoanOverdue` notification to the borrower (the staff `User` directly, or — for a `Student` borrower, since students have no login — every one of that student's guardians), and stamps `overdue_notified_at` so the same loan is never reminded twice. `renew()` resets `overdue_notified_at` back to null, so a loan that goes overdue again after being renewed can be reminded a second time. Registered in the scheduler alongside `hr:check-contract-expiry` (see §2's Task Scheduling note). This remains the module's *only* notification — fines and renewal counts still have no notification or cap of any kind, as originally documented below.

### 25.5 Pages

**Library/Index** — active loans (Return/Renew/Mark-lost-or-damaged, with an estimated-fine confirm on Return) and an "unresolved fines" section (Mark-paid/Waive). **Books/Index** — search + catalog grid showing available/total copy counts. **Books/Show** — per-branch copy list with Issue (debounced borrower search across Students+Staff)/Mark-lost/Mark-damaged/Remove/Restore. **Mine** (`/my-library-loans`) — any staff account's own loan history, no permission required, "the same shape as My Leave/My Performance/My Payslips/My Timetable." **Settings** — loan-days + a fines-enabled checkbox revealing a per-day rate field only when checked, read-only fieldset for non-managers.

### 25.6 Notifications & audit trail

**One notification exists** — `LoanOverdue` (added 2026-10-16, see §25.4), fired at most once per overdue loan by the new `library:check-overdue-loans` command. Fines and renewal counts still have **no notification or cap of any kind** — every other state here is surfaced only by visiting the relevant page on demand, a deliberate (if disclosed) remaining gap. Every model uses `Audited` except `BookCopy`'s loan-child rows are logged plainly; `LibraryLoan` overrides only `auditReason()` to capture a lost/damaged `notes` explanation.

---

## 26. Timetable, Periods & Rooms

A day/time scheduling layer built on top of (but not requiring) the pre-existing `TeachingAssignment` (teacher×section×subject with no day/time component at all).

### 26.1 Permissions & defaults

Permission group `timetable`: `timetable-view`, `timetable-manage`, `timetable-override` — deliberately its own, narrower permission, separate from `-manage`, since bypassing a detected teacher/room/section conflict is more sensitive than ordinary scheduling. Owner and branch_admin get all three by default; nobody else does. Viewing your own timetable (`/my-timetable`) requires no permission at all — the same self-service shape used throughout the new modules.

### 26.2 Database schema

See §4 ("Timetable, Periods & Rooms") for `periods` (school-wide bell schedule), `rooms` (branch-scoped), `timetable_slots` (no DB uniqueness at all — conflict rules are deliberately application-only so an authorized override can save a genuine duplicate).

### 26.3 Routes

| Method | URL | Controller@action | Permission |
|---|---|---|---|
| GET/POST/PUT/DELETE | `/periods` | PeriodController | view/manage ×3 |
| GET/POST/PUT/DELETE | `/rooms` | RoomController | view/manage ×3 |
| GET | `/my-timetable` | TimetableController@mine | none — self-service |
| GET/POST/PUT/DELETE | `/timetable`+{s} | TimetableController | view/manage(+override)/manage(+override)/manage |

### 26.4 Business rules

A slot does **not** require a matching `TeachingAssignment` to exist, so a one-off substitute can always be scheduled. `day_of_week` is 1–7 ISO (Mon=1..Sun=7), scoped to a term. **Conflict detection** (`App\Services\TimetableConflicts::detect()`) checks three independent rules at the same `(term, day, period)`: the same teacher already elsewhere, the same room already hosting a different section, the same section already having a different subject. **Override flow**: `store()`/`update()` always check conflicts first unless the requester holds `timetable-override` and explicitly confirms; an override requires a written `override_reason` (stored on the slot) and conflicts are then never even checked. The grid deliberately renders every slot in a cell, not just the first, because an authorized override can leave a genuine clash on purpose. Audit: a slot created or updated *with* an override writes a distinct `timetable_slot.conflict_overridden` entry carrying the reason; everything else uses the ordinary `Audited` trait. Notification: `TimetableChanged` goes only to the slot's own teacher, never to guardians/students — "do not spam," since parents see it via their own My Timetable page instead.

### 26.5 Pages

**Academics/Periods/Index** and **Rooms/Index** — flat lists with modal add/edit/remove, deletion blocked while slots reference them. **Academics/Timetable/Index** — a scope selector (section/teacher/room) + term selector driving a Period×Day grid; an edit dialog that shows inline conflict errors, and — only if the viewer can override — a warning banner with a required-reason textarea and a "Save anyway" button. **Academics/Timetable/Mine** (`/my-timetable`) — the same grid, no gate, scoped to the signed-in teacher's own slots. **Family/Timetable.jsx** (`/my/timetable`) — the parent-facing read-only equivalent, via the existing `ChildSwitcher`.

---

## 27. Period Attendance

A finer-grained sibling to the existing daily homeroom attendance (§7) — explicitly contrasted in its own docblock.

### 27.1 Permission & scope

Reuses the *existing* `mark-attendance` permission rather than adding a new one — "the same underlying action at finer granularity." A teacher may mark **only** the one TimetableSlot they are the assigned teacher of — never another subject, even for the same class — while anyone else holding `mark-attendance` (Owner, Branch Admin) can mark any slot in their branch/org scope to cover an absent teacher, mirroring daily attendance's own access rule almost exactly.

### 27.2 How it differs from daily attendance

- **Grain**: one row per student per TimetableSlot *occurrence* per date (`period_attendance_records`, unique on enrollment+slot+date) — versus daily attendance's one row per student per *day*. A student can be marked separately in each subject's own period on the same day.
- **Statuses**: `present/absent/late/excused` — deliberately **no `sick`** status (that distinction stays a daily/homeroom concept).
- **Guardian notification — explicitly, deliberately, and verifiably none.** The controller's own docblock states: "a student can have several periods a day, and the one daily/homeroom absence notice already reaches the family." `tests/e2e/period_attendance.py` explicitly asserts the `StudentMarkedAbsent` notification count is unchanged before/after a period absence is recorded — a tested omission, not an oversight.
- **Audit**: creation is never logged (`auditsCreation() => false`, to avoid spamming the log far worse than even daily attendance already deliberately avoids); only a later change to an existing mark writes `period_attendance.status_changed`.
- **Deletion**: no destroy route exists at all; a `TimetableSlot` can't be deleted once real period attendance exists against it.

### 27.3 Routes & pages

`GET /period-attendance` (index, by date, filtered to the signed-in teacher's own slots or requiring a teacher pick for anyone else), `GET`/`POST /period-attendance/{slot}` (the 4-status take screen with a "mark rest present" bulk action and a confirmation if students remain unmarked, showing `last_saved_by`) — both gated on `mark-attendance` plus the slot-specific teacher check.

---

## 28. School Calendar (Events)

A shared events calendar — holidays, exams, meetings, activities, deadlines — explicitly modeled on the pre-existing `Announcement` feature (not to be confused with the unrelated Ethiopian-calendar *conversion* system of §9).

### 28.1 Permission & audience model

`manage-calendar` lives in the existing `messages` permission group, not its own group. **Audience**: exactly 3 buckets identical to `Announcement::AUDIENCES` — `everyone`/`parents`/`staff` (confirmed with the user as the right granularity, rather than a finer per-role picker). **Branch scoping**: `branch_id` nullable = every branch, same convention as `payment_accounts`/`announcements`.

### 28.2 Visibility & posting rules

Anyone signed in with a `school_id` can open `/calendar`, no permission needed — `CalendarEvent::scopeVisibleTo()` filters to the viewer's school, their own branch or branch-null events, and (unless they hold `manage-calendar`) further to just the audience bucket that applies to them. A `manage-calendar` holder sees every audience/branch in scope, for oversight. Only `manage-calendar` holders can post; a branch-scoped admin's event is **forced** into their own branch even if they try to post school-wide — only org-wide accounts can post school-wide or pick any branch.

Unlike Announcement's `publish_on`, an event is visible and notifies its audience **the moment it's created** — `start_date` already says *when it happens*, a different question from *when people are told*. Notification (`CalendarEventPublished`) is guarded by a `notified_at` one-shot flag, so calling the notify path twice is a safe no-op; there is deliberately no reminder as the date approaches. Removing an event archives it (soft-delete), confirmed by the e2e test's final check that a "removed" event still exists in the table.

### 28.3 Routes & pages

`GET /calendar` (any signed-in user), `POST`/`PUT`/`DELETE /calendar{/{e}}` (`manage-calendar`, branch-scoped). **Calendar/Index** — upcoming events as cards (with the audience label shown to managers only) plus a collapsed "past events" disclosure, never mixed into the open list; a single combined list+modal page rather than a separate wizard, "an event has far fewer meaningful decisions to walk through" than an announcement.

---

## 29. Student Discipline & Medical Records

Two access-restricted student record types, each with a permission pair deliberately separate from the broad `manage-students` permission — "holding the broad student-admin permission alone must never imply seeing health data," and discipline follows "the same explicit view/manage split Medical Records and HR already established."

### 29.1 Permissions

`medical-records-view`/`medical-records-manage` and `discipline-view`/`discipline-manage`. `StudentController`'s own student-list and edit payloads never include a single medical or discipline field — only boolean `canViewMedical`/`canViewDiscipline` capability flags, confirmed directly in the controller's source and comments.

### 29.2 Why neither model uses the `Audited` trait

Both `StudentMedicalRecord` and `DisciplineIncident` **deliberately do not use** the generic `Audited` trait — the trait would log a full field-level before/after diff into `audit_logs`, readable by anyone holding the broader `view-audit-log` permission, "which would silently defeat the whole point of this record being access-restricted at all." Instead, each controller writes its own minimal `AuditLog::record()` call with `before`/`after` both empty — a "this was changed" trail, never a "here is what changed" one. Action strings: `student_medical_record.created`/`.updated`; `discipline_incident.created`/`.updated`.

### 29.3 Medical Records

A single upserted row per student (`updateOrCreate`), never a timestamped log. Fields: blood type, allergies, conditions, medications, physician name/phone, notes — no attachment column at all, deliberately ("a plain text field already covers 'allergic to penicillin' perfectly well"). The edit page renders the entire form inside a `<fieldset disabled={!canManage}>`, so an auditor sees every real value but no Save button and no editable inputs.

### 29.4 Discipline incidents

**No destroy route exists at all** — "once recorded, an incident is permanent (correct it by editing/resolving, never erase that it happened), the same append-only philosophy Payment/Invoice/AuditLog already follow." Fields: a 9-value category enum, description, incident date (≤ today), an 8-value action-taken enum (including `suspension`/`expulsion`), status (`open`/`under_review`/`resolved`), an optional attachment. Transitioning into `resolved` stamps `resolved_by`/`resolved_at` once, not re-stamped on later saves. Two views: a school/branch-wide triage dashboard (`Discipline/Index`, read-only, status-filterable, links through to the student) and the student's own full history (`Discipline/ForStudent`, where recording/editing actually happens).

### 29.5 Routes

`GET /discipline`, `GET /students/{s}/discipline`, `POST /students/{s}/discipline`, `PUT /discipline/{i}`, `GET /discipline/{i}/document` (view/manage/manage/manage/view). `GET`/`PUT /students/{s}/medical` (view/manage).

---

## 30. Security Log, Student Desk & Assignments/Homework

Three unrelated small features that didn't fit elsewhere, each still worth its own precise documentation.

### 30.1 Security Log

A table **wholly separate from `audit_logs`**, because most of what it tracks happens to people who are not signed in at all (failed logins, locked-out numbers belonging to no account), so it can't reliably hang off a `users` or `AuditLog` row. `security_events` has `$timestamps = false` — only `created_at`, no `updated_at` — and is purely an append log, never updated or deleted. Tracked events observed in the codebase: `login_failed`, `login_blocked`, `account_locked`, `login_succeeded_after_failures`, `ip_pressure` (a network-level alert with no associated user), `password_reset_by_admin`, `password_reset_by_email`, `password_changed`, `sign_in_unlocked_by_admin`, `account_disabled`, `account_enabled`. **Never stores a password**; `identifier` is always masked before storage (`a***@b.com`, `+2519*****344`). Gated by the existing broad `view-audit-log` permission, not a new one — `GET /audit-log/security`, linked from the main Audit Log page. The page shows masked identifiers, the affected/acting users' names, IP, and decoded non-secret context — never a password, never an unmasked phone, and never the tuning thresholds themselves (no lockout-cooldown config is exposed).

### 30.2 Student Desk

A "help a parent" front-desk aggregator — the page staff open when a parent (with or without a portal account) asks "how much do I owe," "was my child here today," or "I need the report card." **It adds no new power at all**: each section (fees, attendance, report card, contact info, an inline record-payment shortcut) renders only if the viewer already holds the exact permission/gate that would unlock that information elsewhere in the app (`manage-invoices`/`view-financial-reports` for fees, the `record` Gate for the payment shortcut, `view-attendance-reports` for attendance, the `viewRecords` Gate for the report card, `manage-students` for contact details). If the viewer holds **none** of these the whole route 403s; if they hold some but not others, the missing sections render a plain "ask someone who can" note rather than a dead-end disabled control. Explicitly blocked for parents/students themselves. Route: `GET /students/{student}/help` (name `students.desk`).

### 30.3 Assignments / Homework

Genuinely **separate from the Exams/Marks grading system** — no FK to `exams`/`mark_batches`/`marks`, no score column, and does not feed into grades in any way; "a simple foundation for teacher assignments... never a submission/grading system." `manage-assignments` existed, reserved and unused, since the original 24-permission set — this phase simply activated it, needing no seeder change. `teacher_id` is always **derived live** from the current `teaching_assignments` row for the section+subject, never a freely-picked or stored-forever id — the same live-reauthorization principle `MarkBatchPolicy` already established for marks; if no current assignment exists, creation is blocked. A branch admin may manage any section+subject in their branch without personally holding the assignment ("the same 'can cover' shape `mark-attendance` already has"); a teacher may only act on a section+subject they are *currently* assigned to teach, re-checked every time. Lifecycle: `draft` → `publish()` (one-way, idempotent) sends `AssignmentPublished` to every enrolled student's guardians, **one notification per student**, never one broadcast to a whole class, so each guardian's own portal link lands on the right child. A separate `AssignmentDueSoon` reminder fires exactly once per assignment. A draft is invisible to guardians. Routes: `GET /assignments`, `POST`/`PUT`/`DELETE /assignments{/{a}}`, `GET /assignments/{a}/document` — all `manage-assignments`, plus the parent-facing `GET /my/homework` (`Family/Homework.jsx`, published-only, via `ChildSwitcher`).

### 30.4 Notifications & audit trail (all three combined)

`TimetableChanged`, `CalendarEventPublished`, `AssignmentPublished`, `AssignmentDueSoon` are the only notification classes across §26–§30 — all in-app only. **No notification exists** for Discipline, Medical Records, or Security events — consistent with their access-restricted or pure-log design. `Period`/`Room`/`CalendarEvent`/`Assignment` all use the standard `Audited` trait; `TimetableSlot` special-cases conflict overrides as described in §26.4; `DisciplineIncident`/`StudentMedicalRecord` deliberately do not use `Audited` at all (§29.2); `SecurityEvent` is entirely separate infrastructure, not part of `audit_logs` or `Audited` at all.

---

## 31. Security & Privacy — Consolidated View

Pulling together everything described in context above, in one place:

- **Authorization** goes through Policy classes for the original five sensitive record types (`StudentPolicy`, `InvoicePolicy`, `PaymentPolicy`, `ComplaintPolicy`, `MarkBatchPolicy`), plus a consistent `abort_unless($user->can(...))`/`canViewOrManage()` in-controller pattern for every one of the 73 controllers across all eleven new modules — **no route anywhere in the application carries permission middleware**, old or new.
- **Branch scoping** is checked on every list and every direct-access route, across every module; parents/students get 404s (not 403s) for anything not theirs.
- **Permissions live on the user**, never inherited implicitly from a role — confirmed as the deliberate shape for all 9 new permission groups too, none of which introduce a new Spatie role.
- **Medical Records and Discipline deliberately opt out of the generic audit trail** (§29.2) specifically so sensitive content isn't readable by the broader `view-audit-log` audience — a privacy-motivated exception to "the audit trail is append-only," not a gap in it.
- **A wholly separate, append-only Security Log** (`security_events`, §30.1) exists specifically because most authentication-abuse events happen to people who aren't signed in, and nothing it stores is ever a raw password or unmasked identifier.
- **File uploads** continue to be judged by content, stored under random names, with private files served only through authorized, policy-checked routes — now also covering employment contracts, leave documents, discipline attachments, and assignment attachments (§14).
- **Passwords are never emailed in plaintext or generated and hidden** — unchanged.
- **HTTPS is enforced in production** — unchanged.
- **Uniqueness and safety are enforced at the database layer wherever it matters** — extended by the new modules' own unique constraints (one stock balance per item+store, one payroll run per branch+month, one payslip per run+employee, one performance review per employee+cycle, one leave allocation per employee+type+year, one period-attendance record per enrollment+slot+day) and by application-level invariants where a DB constraint genuinely can't express the rule (timetable conflict detection, debit=credit balancing, the three-way procurement match).
- **Concurrency is handled explicitly wherever money, seats, stock, or sequence numbers are involved** — row locks taken in a fixed order, now also covering `Stock::applyLocked()`, `Assets`' event+status transaction, `Ledger::post()`'s locked entry, and supplier-payment-against-balance checks, in addition to the original payment verification, promotion, section capacity and number sequences.
- **The audit trail is append-only** at the model layer (update and delete both throw) for every model that uses it; the two deliberate non-`Audited` exceptions are documented, not accidental.
- **Nothing financial, academic-record, HR, inventory, procurement, or identity-bearing is ever hard-deleted** without a model-level guard — extended consistently into every new module (§11, §21.4, §22.4, §23.4, §25.4, §29.4).

---

## 32. Automated Testing

The original PHPUnit suite (feature/unit tests on in-memory SQLite) plus the Node calendar-parity check remain as originally documented. **Backend suite stands at 1087/1087 passing** as of the 2026-10-16 hardening pass (up from 1053 at that session's start — 34 new tests added, zero weakened or removed, zero failures). Browser-level coverage under `tests/e2e/` (Python Playwright against a throwaway SQLite usability-session database — see the memory note on the exact recipe) has grown substantially alongside the eleven new modules, with one script per major area: `hr.py`, `hr_dashboard.py`, `hr_attendance.py`, `hr_leave.py`, `hr_performance.py`, `hr_payroll.py` (HR & Payroll); `inventory.py`, `assets.py`, `requisitions.py` (Inventory & Assets); `procurement.py`, `suppliers.py`/`bills.py` (Procurement); `accounting.py` (the Accounting engine and its reports); `library.py` (Library); `timetable.py`, `period_attendance.py`, `calendar.py`, `section_balancing.py` (Timetable/Calendar); `discipline.py`, `medical_records.py` (Discipline/Medical); `role_home.py`, `family_portal.py`, `parent_account.py`, `receptionist.py`, `frontdesk.py`, `payment_desk.py`, `admin.py`, `assisted.py`, `auth_phone.py`, `photo.py`, `forms.py`, `prod_readiness_check.py` (cross-cutting UX/role coverage). Notably, `period_attendance.py` specifically asserts that a period absence does **not** trigger a second guardian notification (§27.2) — a deliberate omission verified by test, not left to chance.

**New PHPUnit coverage added during the 2026-10-16 hardening pass** (all under `tests/Feature/`): `InventoryTest` (+5 — SKU/barcode uniqueness, cross-school collision allowance, archive-then-reuse), `AssetTest` (+5 — supplier-linking scenarios, including the capability-gated visibility check), `BranchDeletionSafetyTest` (new file, 5 tests — proves each of the five fixed tables survives a branch delete with `branch_id` nulled rather than its rows disappearing), `SupplierBillTest` (+5 — payment reversal scenarios, including netted balance and re-opening a bill's outstanding amount), `LibraryTest` (+4 — overdue-notification scenarios, the one-shot guard, and the renew-resets-the-guard behavior), `PayrollTest` (+2 — tax-bracket boundary continuity, gross-vs-net sanity), `AttendanceWorkflowTest`/`StaffAttendanceTest` (+1 each — proving the newly-transactional batch save actually rolls back completely on a mid-batch failure, not partially), `ProcurementDashboardTest` (new file, 7 tests — the new dashboard block's threshold split, status filtering, and branch scoping). Every one of these tests exercises a real fix or a real new feature from this pass, not a cosmetic assertion — consistent with the instruction this session was run under not to claim something was tested without actually testing it.

**E2E verification during the same pass** re-ran, individually against a freshly-seeded database, every e2e script touching a module this session modified (`hr.py`, `hr_leave.py`, `hr_performance.py`, `hr_payroll.py`, `inventory.py`, `procurement.py`, `calendar.py`) — all passed 100%. Running the same scripts back-to-back in one long sequential batch against a single shared throwaway database produced intermittent failures (e.g. a `UNIQUE constraint failed: employees.employee_number` collision) that did **not** reproduce when each script ran alone; this was confirmed to be test-infrastructure contention (shared DB/server resource collisions under rapid sequential load), not a real application bug, consistent with the project's own documented "reset between participants" usability-session workflow. The roughly 15 e2e scripts for modules untouched this session (discipline, medical records, auth/phone, most of receptionist) were **not** re-run — their last-known-good status is unchanged from before this pass, not re-verified by it.

---

## 33. Known Gaps / Honest Limitations

1. **No online payment gateway** — unchanged, deliberate.
2. **Student login is deliberately not offered** — unchanged.
3. **Translations are complete but unreviewed, for static UI text** — every one of 3,736 frontend keys and all 32 backend notification strings exist in all four languages, including every new module (verified by direct key-by-key comparison; corrects an earlier, wrong claim in a prior draft of this document). No native speaker has reviewed any of it yet, old or new (per `LOCALIZATION.md`). **What is genuinely still English-only everywhere, old and new modules alike**: computed/dynamic validation and error messages built inline inside controllers (e.g. "You can refund at most X ETB..."), never passed through a translation function — a pre-existing, whole-application pattern, not a new-module gap.
4. **Ethiopian numerals and 12-hour clock time** are not implemented — unchanged.
5. **File-upload validation is not a virus scanner** — unchanged.
6. **No email is actually sent** anywhere in the system, old or new (`MAIL_MAILER=log`) — every one of the ~26 notification classes across every module is in-app/database only.
7. **Dashboard integration is partial, not absent** (corrected from an earlier draft of this document — see §19): Inventory, Requisitions, Assets, the full HR module, and (as of 2026-10-16) **Procurement** now surface on the role dashboards. **Library, Timetable/Calendar, and Discipline do not yet** — overdue loans, today's schedule, and open incidents are each visible only on their own module's index page.
8. **HR/Library scheduler wiring — fixed 2026-10-16.** The contract-expiry check (`hr:check-contract-expiry`) and the new overdue-loan reminder (`library:check-overdue-loans`, §25.6) are both now registered in the Laravel scheduler (`routes/console.php`), confirmed via `schedule:list`. Whether the production server's `schoolerp-scheduler` Render Cron Job (documented in `DISASTER_RECOVERY.md`) is actually configured and running is a real-world deployment fact that **requires real-world verification** — it cannot be confirmed from the code. The assignment due-soon reminder remains a real console command with the same real-world caveat.
9. **Procurement**: six lifecycle events — quotation recorded/selected, PO sent/cancelled, goods receipt recorded, supplier bill recorded, supplier payment recorded — plus the new supplier-payment reversal (§22.4) have **no notification at all**; nobody is told unless they're already watching the relevant screen.
10. **Procurement — partially addressed 2026-10-16.** There is still no edit/correction path for a `PurchaseOrder`, `Quotation`, `GoodsReceipt`, or `SupplierBill` after creation except a new, offsetting record or (for accounting effects) a manual journal entry — stated explicitly in the code, not an oversight. `SupplierPayment` is the one exception: it now has a proper reversal workflow (§22.4) rather than requiring a manual journal entry.
11. **Procurement**: `purchase_orders.branch_id` and several sibling FKs still use `nullOnDelete()` where most of the rest of the schema (old and new) uses `cascadeOnDelete()` for `branch_id` — flagged as a possible inconsistency worth a deliberate decision rather than an accepted default. (Note: this is a *different*, still-open question from the branch-deletion bug fixed this session on five other new-module tables — see §4's note — which was a genuine bug, not an inconsistency-to-review.)
12. **Inventory/Assets — fixed 2026-10-16.** `inventory_items.sku`/`.barcode` now carry a real DB-level `unique(['school_id','sku'])`/`unique(['school_id','barcode'])` constraint (previously app-level-only). `assets` now also carries an optional structured `supplier_id`→`suppliers` link alongside its original free-text `supplier` field (previously structurally disconnected from Procurement).
13. **Library — partially addressed 2026-10-16.** An overdue-loan reminder now exists (§25.6). No fine notification and no renewal-count cap exist anywhere — checked specifically, not assumed absent.
14. **Accounting**: no DB-level CHECK constraint enforces "exactly one of debit/credit nonzero" or period non-overlap — both are application-only rules, by explicit design (cross-DB-driver CHECK support varies too much to lean on), but worth knowing they aren't a last-resort database guarantee the way the original system's unique-key invariants are. A DB-level unique constraint on `journal_entries(source_type, source_id)` was considered during the 2026-10-16 hardening pass specifically to prevent duplicate accounting postings, then deliberately **not** added once it was confirmed that `Invoice` legitimately accumulates 3+ journal entries over its lifetime (issuance, each discount change, each waiver toggle) — such a constraint would have broken real, correct usage; the existing `accounting:backfill-ledger` command's `whereDoesntHave('journalEntries')` guard was confirmed sufficient instead.
15. **Pagination — a real, previously undocumented finding from the 2026-10-16 hardening pass, deliberately not fixed yet.** `StudentController`, `StaffController`, `ParentController`, and `InvoiceController` all load every matching row with no pagination at all — a genuine scalability risk for any school with hundreds of students, flagged in `PRODUCTION_READINESS_REPORT.md` as the single most important follow-up item. Not fixed this session because it touches core list-rendering behavior relied on throughout the frontend (sorting/filtering assumptions, export buttons reading the same full list) broadly enough that it warrants its own deliberate, reviewed pass rather than a drive-by change during a hardening session explicitly scoped to avoid new risk.
16. **Uncommitted work**: as of this writing, a very large amount of work — effectively everything described in §20–§30, this hardening pass's own fixes, plus a UI redesign pass and mobile-responsiveness fixes — sits uncommitted in the working tree. Commits happen only when the project owner explicitly asks.
17. **No real usability testing has happened** on any of the eleven new modules specifically — the existing `USABILITY_TESTING.md`/`UAT.md` protocol and empty findings log (unchanged from the original spec) predate all of this work. Likewise, the backup/restore drill documented in `DISASTER_RECOVERY.md` exercises `rclone`'s local-filesystem backend, not the real configured bucket's authentication — a real restore against the actual production bucket **requires real-world verification**, not re-confirmed by this session.

---

## 34. Codebase Map (every file, by purpose)

**Controllers** (`app/Http/Controllers`, 73 files). Core (documented in §6–§19): `AcademicStructureController`, `AcademicYearController`, `AnnouncementController`, `AttendanceController`, `AttendanceReportController`, `AuditLogController`, `BranchController`, `ComplaintController`, `DashboardController`, `ExamController`, `FamilyController`, `FeeStructureController`, `GradeBandController`, `GradeController`, `HelpController`, `IdCardController`, `InvoiceController`, `MarkBatchController`, `NotificationController`, `OptionalServiceController`, `ParentController`, `PaymentAccountController`, `PaymentController`, `ProfileController`, `PromotionController`, `ReportCardController`, `SchoolSettingsController`, `SectionController`, `StaffController`, `StudentController`, `SubjectController`, `TeachingAssignmentController`, `TermController`, plus `Auth/` (Breeze + `InvitationController`). **HR & Payroll (§20):** `DepartmentController`, `PositionController`, `EmployeeController`, `EmploymentContractController`, `StaffAttendanceController`, `LeaveTypeController`, `LeaveAllocationController`, `LeaveRequestController`, `PerformanceReviewCycleController`, `PerformanceReviewController`, `PayrollSettingsController`, `PayrollRunController`. **Inventory & Assets (§21):** `InventoryCategoryController`, `InventoryItemController`, `InventoryStoreController`, `StockMovementController`, `RequisitionController`, `AssetController`, `StocktakeController`. **Procurement & Suppliers (§22):** `SupplierController`, `PurchaseRequestController`, `QuotationController`, `PurchaseOrderController`, `GoodsReceiptController`, `SupplierBillController`, `SupplierPaymentController`. **Accounting & Reports (§23–§24):** `AccountController`, `JournalController`, `AccountingPeriodController`, `JournalEntryController`, `FinancialReportController`, `ReportsController`. **Library (§25):** `BookController`, `BookCopyController`, `LibraryLoanController`, `LibrarySettingsController`. **Timetable/Periods/Rooms/Period Attendance (§26–§27):** `PeriodController`, `RoomController`, `TimetableController`, `PeriodAttendanceController`. **Calendar (§28):** `CalendarEventController`. **Discipline & Medical (§29):** `DisciplineIncidentController`, `StudentMedicalRecordController`. **Security/Desk/Assignments (§30):** `SecurityLogController`, `StudentDeskController`, `AssignmentController`.

**Models** (`app/Models`, 85 files, flat — no subfolders except `Concerns/`). Core: `AcademicYear`, `AccountInvitation`, `Announcement`, `AttendanceRecord`, `AuditLog`, `Branch`, `Complaint`, `Enrollment`, `Exam`, `FeeStructure`, `Grade`, `GradeBand`, `Invoice`, `InvoiceItem`, `Mark`, `MarkBatch`, `MarkBatchPublication`, `NumberSequence`, `OptionalService`, `Payment`, `PaymentAccount`, `PaymentRefund`, `ReportCardSnapshot`, `School`, `Section`, `Student`, `Subject`, `TeachingAssignment`, `Term`, `User`, plus `Concerns/` (`Audited`, `GuardsDeletion`, `NeverHardDeleted`). New: `Department`, `Position`, `Employee`, `EmploymentContract`, `StaffAttendance`, `StaffAttendanceCorrection`, `LeaveType`, `LeaveAllocation`, `LeaveRequest`, `PerformanceReviewCycle`, `PerformanceReview`, `PayrollTaxBracket`, `PayrollRun`, `Payslip`, `InventoryCategory`, `InventoryStore`, `InventoryItem`, `StockBalance`, `StockMovement`, `Requisition`, `RequisitionItem`, `Asset`, `AssetEvent`, `Stocktake`, `StocktakeLine`, `Supplier`, `PurchaseRequest`, `PurchaseRequestItem`, `Quotation`, `QuotationItem`, `PurchaseOrder`, `PurchaseOrderItem`, `GoodsReceipt`, `GoodsReceiptItem`, `SupplierBill`, `SupplierBillItem`, `SupplierPayment`, **`SupplierPaymentReversal`** (added 2026-10-16, §22), `Account`, `Journal`, `AccountingPeriod`, `JournalEntry`, `JournalEntryLine`, `Book`, `BookCopy`, `LibraryLoan`, `Period`, `Room`, `TimetableSlot`, `PeriodAttendanceRecord`, `CalendarEvent`, `DisciplineIncident`, `StudentMedicalRecord`, `SecurityEvent`, `Assignment`.

**Policies** (`app/Policies`): `ComplaintPolicy`, `InvoicePolicy`, `MarkBatchPolicy`, `PaymentPolicy`, `StudentPolicy`, plus `Concerns/ScopesToBranch` — none of the eleven new modules adds its own Policy class; all of them use the consistent in-controller `abort_unless`/`canViewOrManage()` pattern instead (§3).

**Services** (`app/Services`): `AccountOnboarding`, `EthiopianCalendar`, `Finance`, `ReportCardCalculator`, `SectionCapacity` (core); `Stock`, `Assets` (Inventory, §21); `Ledger`, `AccountingPostings` (Accounting, §23, the latter also the integration point for HR payroll and Procurement bills/payments); `Library` (§25); `TimetableConflicts` (§26); `Payroll` (HR, §20 — tax/pension calculation and run generation).

**Support** (`app/Support`): `PhoneNumber`, `SafeUpload`, `SchoolCalendar`, `Backup/BackupNaming`, `Monitoring/SentryScrubber`, `Monitoring/Heartbeat`, `Localization/NotificationLocale`, plus `ChartOfAccountsTemplate` (Accounting, §23) and `PayrollTaxBracketDefaults` (HR, §20).

**Commands** (`app/Console/Commands`): includes **`CheckOverdueLoans`** (`library:check-overdue-loans`, added 2026-10-16, §25.6) alongside the pre-existing `hr:check-contract-expiry` and other scheduled commands — both are now actually registered in `routes/console.php`'s scheduler (§2's Task Scheduling note).

**Notifications** (`app/Notifications`, ~26 files, all database-channel only). Core 9: `AccountInvitationNotification`, `AttendanceCorrected`, `ComplaintSubmitted`, `ComplaintUpdated`, `InvoiceGenerated`, `PaymentRefunded`, `PaymentRejected`, `PaymentVerified`, `StudentMarkedAbsent`. HR (§20.6): `LeaveRequestSubmitted`, `LeaveRequestReviewed`, `ContractExpiringSoon`, `AttendanceCorrectionRequested`, `AttendanceCorrectionReviewed`, `PerformanceReviewFinalized`, `PayslipReady`. Inventory/Procurement (§21.6/§22.6): `LowStockAlert`, `RequisitionSubmitted`, `RequisitionApproved`, `RequisitionRejected`, `AssetAssigned`, `AssetRepairCompleted`, `PurchaseRequestSubmitted`, `PurchaseRequestApproved`, `PurchaseRequestRejected`, `PurchaseRequestFulfilled`. Library (§25.6): **`LoanOverdue`** (added 2026-10-16). Timetable/Calendar/Assignments (§30.4): `TimetableChanged`, `CalendarEventPublished`, `AssignmentPublished`, `AssignmentDueSoon`. Accounting and the Supplier Payment Reversal workflow deliberately add none.

**Seeders** (`database/seeders`): `RolesAndPermissionsSeeder` (now 66 permissions / 14 groups), `DemoSchoolSeeder`, `SampleAcademicDataSeeder`, `UsabilitySessionSeeder`, `DatabaseSeeder`.

**Frontend pages** (`resources/js/Pages`). Core folders: `AcademicStructure`, `AcademicYears`, `Announcements`, `Attendance`, `AuditLog` (now also `Security.jsx`), `Auth`, `Branches`, `Complaints`, `Exams`, `Family` (now also `Timetable.jsx`, `Homework.jsx`), `FeeStructures`, `GradeBands`, `Help`, `IdCard`, `Invoices`, `Marks`, `Notifications`, `OptionalServices`, `Parents`, `Payments`, `Profile`, `Promotions`, `ReportCard`, `Settings`, `Staff`, `Students` (now also `Desk.jsx`, `Medical.jsx`), `Subjects`, `TeachingAssignments`. New folders: `Hr/` (17 pages), `Inventory/`, `Assets/` (`Create.jsx`/`Show.jsx` now include the supplier picker/link, §21.5), `Stocktakes/`, `Requisitions/`, `Suppliers/`, `PurchaseRequests/`, `PurchaseOrders/`, `SupplierBills/` (`Show.jsx` now includes the reversal UI, §22.5), `Accounting/`, `Reports/`, `Library/`, `Academics/` (`Periods`, `Rooms`, `Timetable`, `PeriodAttendance`), `Calendar/`, `Discipline/`, `Assignments/`.

**Frontend components** (`resources/js/Components`, selected additions from the 2026-10-16 pass): **`TodayHeader.jsx`** (the shared date/day header on every dashboard variant, §19) and **`SupplierPaymentReversalDialog.jsx`** (§22.5), plus **`resources/js/greeting.js`** (the shared `greetingKey(hour)` helper now used by both `HomeTasks.jsx` and `TeacherHome.jsx` instead of each independently reading the browser's own clock — the fix for the cross-timezone greeting bug, §19).

**Tests**: `tests/Feature`/`tests/Unit` (core PHPUnit suite — see §32 for the specific new test files/counts added 2026-10-16); `tests/js/calendar-parity.mjs`; `tests/e2e/` — see §32 for the full list of scripts covering every new module.

---

## 35. Architecture-Review Changelog

This numbered list is the running record of every item raised in the architecture review and where its full description now lives in this document. Items 1–40 are complete and cover the original core system (§6–§19, §31–§34). Items 41+ cover the eleven modules added since, each cross-referenced to its own section above.

| # | Item | Where documented |
|---|---|---|
| 1–40 | *(original core system — enrollment eligibility, withdrawal, invoice immutability, overpayment, duplicate-verification prevention, invoice/receipt idempotency & numbering, report-card missing-marks policy, exam-weight validation, class ranking, attendance permissions, parent authorization, ID-card privacy/validity, invitation links, phone/calendar/age/name support, audit logging, soft deletion, promotion safety, complaint privacy, upload security, attendance corrections, parent attendance history, announcements, the student-portal decision, branch-scoped payment accounts, centralized finance formulas, refunds, discount/waiver authority, year switching, term dates, section capacity, teaching-assignment uniqueness, mark ownership, published-marks immutability)* | §3–§19, §31–§34 |
| 41 | Full Human Resources module: departments/positions, employee profiles decoupled from login accounts, employment contracts with permanent history, staff attendance with a two-person correction workflow, self-service leave for every staff tier with a doubled self-review ban, performance review cycles with no self-review permission at all, and an Ethiopian-specific payroll engine (progressive tax brackets, pension rates, best-effort GL posting) | §20 |
| 42 | Inventory & consumable-stock ledger as a single-writer service (`App\Services\Stock`), with row-locked balances, a positive-only immutable movement log, and low-stock alerting that fires only on the crossing, never repeatedly | §21 |
| 43 | Requisition draw-down workflow with per-line approval and per-line incremental issuing | §21 |
| 44 | Fixed-asset lifecycle as its own state machine, independent of consumable stock and of the Procurement supplier table | §21 |
| 45 | Stocktake reconciliation against the *live* balance at completion time, not a stale starting snapshot | §21 |
| 46 | Full supplier procurement pipeline — request → two-tier approval → quotations (manual selection only) → purchase order (split-across-suppliers safe) → goods receipt (partial/repeated receiving, the sole point stock is fed) → supplier bill (three-way match against received, never billed-over-received) → supplier payment (always "confirmed," no verify step) | §22 |
| 47 | A genuine double-entry accounting engine — chart of accounts, journals, journal entries balanced only at posting time (never at save time), accounting periods with a stronger separate reopen permission, and reversal-not-edit as the only correction path for a posted entry | §23 |
| 48 | A best-effort, non-blocking posting bridge (`AccountingPostings`) from the existing Finance/Procurement/Payroll systems into the new ledger, with a dedicated Reconciliation report to catch drift | §23, §24 |
| 49 | Eight read-only financial reports (ledger, trial balance, income statement, balance sheet, cash summary, AR/AP aging, reconciliation) plus a permission-filtered cross-module report catalog | §24 |
| 50 | A Library module modeling physical copies as individually trackable (not fungible stock), with self-contained fines that deliberately never touch the invoice/payment/accounting systems | §25 |
| 51 | A Timetable layer with explicit, overridable conflict detection (teacher/room/section double-booking), scoped to terms and deliberately not requiring a matching teaching assignment | §26 |
| 52 | Period-level attendance as a distinct concept from daily homeroom attendance — finer-grained, no `sick` status, and a tested, deliberate decision not to duplicate the guardian absence notification | §27 |
| 53 | A School Calendar events feature modeled on Announcements' audience/branch rules, but notifying immediately on creation rather than on a scheduled publish date | §28 |
| 54 | Student Discipline and Medical Records as permission-pairs deliberately separate from `manage-students`, with both models deliberately opting out of the generic audit trail to avoid leaking sensitive content to a broader oversight audience | §29 |
| 55 | A Security Log wholly separate from the main audit trail, built to cover unauthenticated-actor events that could never attach cleanly to a `users`/`audit_logs` row, with every identifier always stored masked | §30 |
| 56 | A Student Desk aggregator that adds no new authority of its own — every section it shows is re-derived from a permission/gate the viewer already holds elsewhere | §30 |
| 57 | Homework/Assignments kept genuinely separate from the Exams/Marks grading system, with teacher authority re-derived live from the current teaching assignment every time, never cached or trusted from a stored id | §30 |
| 58 | **2026-10-16 production-hardening pass** (33-point checklist audit, documented in full in `PRODUCTION_READINESS_REPORT.md`): real bugs found and fixed — a cross-timezone dashboard greeting bug, two un-transacted multi-row attendance batch saves, a branch-deletion cascade chain that would have silently destroyed five new modules' unrelated records, and four dead scheduler tasks that existed as code but never actually ran | §4 (branch-deletion note), §19, §7/§26 (attendance), §2 (Task Scheduling) |
| 59 | Small, deliberately scoped new features added only where a genuine, already-disclosed gap was found: a Supplier Payment Reversal workflow (closing Procurement's "no correction path" gap for payments specifically), a Library overdue-loan reminder, a dashboard Procurement block, a DB-level SKU/barcode uniqueness guarantee for Inventory, and an optional structured supplier link on Assets | §22.4, §25.6, §19, §4 (inventory_items), §21.4/§4 (assets) |
| 60 | A corrected, programmatically-verified localization claim (all 3,736 frontend keys and 32 backend notification strings are complete in all four languages — an earlier draft of this document had wrongly claimed otherwise) and an honest, newly-disclosed pagination gap (`StudentController`/`StaffController`/`ParentController`/`InvoiceController` load unbounded result sets) deliberately left unfixed pending its own dedicated review | §18, §33 item 15 |
