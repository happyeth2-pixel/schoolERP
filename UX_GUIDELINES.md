# UX Guidelines & Phase Log — School ERP

**Goal:** a receptionist who is comfortable with WhatsApp and a banking app, but not with "computer software", can finish their task correctly without help. The success metric is that — not how modern the screens look. The ERP stays powerful underneath; the power stays invisible.

**Status:** the shared design system is built and tested (Phase 1); the parent payment-proof wizard was the pilot. Everything here is verified by scripted browser runs, **not by real users** — real UAT (see `USABILITY_TESTING.md`, `UAT.md`) is still what decides whether a change actually helped.

**Last updated:** 2026-09-28.

---

## Principles (what every new or redesigned flow is held to)

1. **One decision at a time; ≤3 fields/decisions per step** where practical. Progressive disclosure instead of every field at once.
2. **Big, obvious targets:** important controls ≥ 48–56px tall. Cards for choices (`ChoiceCard`), not small dropdowns/radios.
3. **Always show where you are** ("Step 2 of 4" + the step's title) and **what happens next**, before the irreversible click.
4. **Back keeps what was typed.** Validate before moving on. Server-side errors send you back to the step that owns the field, with the message beside the field, in plain words.
5. **Confirm important outcomes on the page** (a visible success panel), not only in a toast.
6. **Plain language.** Tasks over system terms. No defaults that make the choice for the person on money/records questions (e.g. no pre-selected payment method).
7. Comfortable on a phone (390px) *and* efficient on desktop. Nothing overflows in Amharic/Oromo/Tigrinya (they run longer than English).

## Safety rules (never traded for simplicity)

No removed authorization checks, permissions, branch scoping, validation, audit logging, financial rules, duplicate prevention, approval workflows, or DB constraints. A friendlier flow sends the **same fields to the same endpoint**; client-side checks only say the same thing one step earlier — the server stays the authority. Do not expose actions to people who may not perform them. Do not change a business rule to make a screen simpler.

## The design system (`resources/js/Components/ui`)

One folder, one import: `import { Button, Field, Input, ChoiceGroup, Alert, StatusBadge, useConfirm, ... } from '@/Components/ui'`. A live, interactive gallery of every component (development only — the route does not exist in production): **`/dev/ui-kit`**. Colours, sizes and tones are defined once in `ui/tokens.js`.

| Need | Use | Replaces |
|---|---|---|
| Any button | `Button` (`variant` primary / secondary / danger / success / quiet; `size` lg 56px default, md 44px; `busy`, `icon`) | `PrimaryButton`, `SecondaryButton`, `DangerButton`, hand-styled `<button>` (small, upper-case, 12px) |
| Icon-only control | `IconButton` (`label` **required**; 48px phone / 40px desktop) | bare `<button><Icon/></button>` |
| Page skeleton | `Page`, `PageHeader`, `SectionHeader`, `BackLink`, `Card` | the `py-8 / mx-auto max-w-…` boilerplate every page repeats; `Card3D` for forms (it tilts under the pointer) |
| Text / long text / long list | `Field` + `Input` / `TextArea` / `Select` | `InputLabel` + `TextInput` + `InputError` (small, error far from the field) |
| 2–6 options | `ChoiceGroup layout="cards"` (`ChoiceCard`) | small dropdowns / radio buttons (39 raw `<select>`s today) |
| 2–3 short options | `ChoiceGroup layout="segmented"` | Male/Female and Yes/No dropdowns |
| A tickable list (permissions) | `CheckRow` | tiny checkboxes |
| Message the person must not miss | `Alert` (`success` / `info` / `warning` / `danger`) and `FlashMessages` | the banner 24 pages copy by hand |
| Status word | `StatusBadge status="pending"` (`STATUS_TONE` is the one table) | 7 hand-kept colour tables |
| "Are you sure?" | `useConfirm()` / `ConfirmDialog` | `window.confirm()` (16 places) |
| Nothing here / working / it worked | `EmptyState`, `LoadingState` + `Spinner`, `SuccessPanel` | grey one-liners; nothing at all |
| Printing | `PrintButton label="Print ID card"` + `PrintArea` | ad-hoc `window.print()` + CSS |
| Multi-step task | `useWizard` + `WizardShell` + `ReviewList` | one long form |

### Rules (each one is enforced by the components or checked in the browser test)

1. **Buttons name their outcome.** "Save Attendance", "Create invoices", "Reject Payment", "Delete Student" — never "Submit", "Confirm", "OK", "Yes", "Delete", "Reject". In development the console warns when a `Button` label is one of the vague words. Wizards keep the predictable **Back / Next / Save / Cancel**.
2. **One primary action per screen.** Cancel/Back are `secondary` and are the safe choice.
3. **Danger buttons say what is lost**, and sit in a `ConfirmDialog` that lists what is affected and what is kept. In the dialog **Cancel has focus** (`data-autofocus`), the action is on top on a phone, Escape cancels, and while working it cannot be dismissed.
4. **Show every option instead of hiding it in a dropdown**: `ChoiceGroup` for up to about six; `Select` only for long lists (forty sections, hundreds of students). Nothing is preselected on questions about money or records.
5. **An error goes beside its field**, with an icon and plain words (`Field`); anything the person must not miss uses `Alert`. An error alert cannot be dismissed; a success can.
6. **Say it worked on the page**, not only in a banner: `SuccessPanel` with what happened and what happens next.
7. **Empty states explain and offer the next step** (`EmptyState action=…`).
8. **Status is icon + words + colour**, never colour alone. Colours mean one thing everywhere (`tokens.js` `TONE`).
9. **Sizes:** primary controls ≥ 56px (cards 64px); header icons 48px on a phone, 40px on desktop; sidebar and dropdown items 48px on a phone. Desktop stays compact.
10. **Print actions name the document and say what will open** and how to keep a file ("Save as PDF").

### Wizard recipe (`useWizard`)

Steps are data: `{ key, label, description, fields: [server error keys], isValid: () => bool, whyBlocked: 'reason' }`; the last step is always a review (`ReviewList`, with "Change" shortcuts). `WizardShell` gives "Step X of Y", a segmented bar, title and explanation, Back/Next, Next disabled **with the reason shown**, Enter that advances (never submits a half-finished form), and a jump back to the earliest step with a server-side error. Keep every answer in **one** `useForm` above the wizard so Back loses nothing. Reference use: `Components/PaymentProofWizard.jsx`; demo: `Pages/Dev/UiKit.jsx`.

### Pitfalls found while building it (so they are not rediscovered)

- **Next → Send auto-submit:** when "Next" (`type=button`) becomes the final "Send" (`type=submit`) in the same spot, React reuses the same `<button>` and the click that reached the last step also submits. `WizardButtons` gives them different `key`s. Keep that.
- **Tailwind must be able to see shared class strings:** `tailwind.config.js` `content` now covers `.js` as well as `.jsx`. It used to skip plain `.js` files, so classes that lived only in `ui/tokens.js` had no CSS (the dark-mode info alert was unreadable, 1.4:1). Any new folder of class strings must be in `content`.
- **Contrast:** white on emerald-600 is only 3.8:1, so `success` buttons are emerald-700. The browser test computes contrast for badges, alerts and buttons in light **and** dark and fails below 4.5:1.
- **A server-refused file must be cleared**, otherwise Next stays lit and the same bad file is resubmitted.
- (Test harness) Playwright **calls** an evaluated expression if its value is a function; end a stub with `; true`.

### Migration map for later phases

Nothing was mass-migrated in this phase, on purpose: each page moves onto the system in its own phase, with its own browser check. Counts today: 24 pages with the hand-copied success banner, 16 `window.confirm()`, 7 private badge colour tables, 25 "nothing here" one-liners, 39 raw `<select>`s, 30 pages using the old `PrimaryButton`, 0 loading states. **Already on the system:** the payment-proof wizard, the ID-card page (`Alert`, `PrintButton`, `PrintArea`), and the shared top bar / sidebar / dropdown sizes (`IconButton`, 48px items). The old `PrimaryButton`/`SecondaryButton`/`DangerButton`/`InputLabel`/`TextInput`/`InputError` are unchanged and still used elsewhere.

### Testing it

- `python tests/e2e/ui_kit.py` — 60 checks on `/dev/ui-kit` in a real phone-sized browser: behaviour, sizes, keyboard focus, print stylesheet, contrast in light + dark, no raw translation keys and no overflow in am/om/ti. Set up as in `tests/e2e/payment_wizard.py`'s docstring.
- `python tests/e2e/payment_wizard.py` — 44 checks; proves the refactored wizard behaves exactly as before.
- `php artisan test --filter=UiKitRouteTest` — the gallery needs a login, works outside production, and is not registered when the app boots in production.
- `npm run test:i18n` — every key exists in en/am/om/ti (new `ui` and `status` strings are `draft`).

## Home page and navigation (Phase 2)

**The home page asks one question — "What do you want to do today?" — and answers it with 3–6 large tiles** (`Components/HomeTasks.jsx`, `ui/ActionTile`, catalog in `resources/js/homeTasks.js`). Below them: **More things you can do** (a disclosure holding every other task the person may use) and **Today at a glance** (the existing figures/widgets, now secondary).

**How a tile is chosen — and why it can never offer something forbidden**
- A tile appears only if its `navRoute` is one the person's own **menu** already offers (`navigation.jsx` builds the menu from their permissions), and, where a tile goes to a more specific page than its menu item, they also hold the tile's `needs` permission (e.g. *Register a new student* needs `manage-students`; an auditor's read-only Students item does not qualify). One source of truth, no second permission table on the front end.
- The server is unchanged and still enforces every route. Tiles use existing routes only.
- **Personas are recognised by what a person can do, not by a title**: `personaFor()` — parent, teacher, accountant, auditor, *front desk* (a branch admin who can manage students but not staff or fees; there is no "receptionist" role), and *admin* (owner and full branch admins). Each persona has a priority list; the first six the person may use become the big tiles; **everything else they may use goes under "More things you can do"** — nothing authorised is dropped.
- **What people get today (from the browser test):** front desk — Register a new student · Find a student · Print an ID card · Parents and guardians · Classes. Teacher — Take attendance · Enter marks · My classes · Notifications · Announcements. Accountant — Check payments (with a "N waiting" count) · Invoices and reports · School fees · Notifications · Announcements (*Give money back* only with the `refund-payments` permission, which accountants do not have by default). Parent — My children · Attendance · Fees · Report card · Notifications (with "N new") · Complaints. Admin/owner — Students · Staff · Classes · School fees · Check payments · Attendance report, with the rest under More. Auditor — Students · Attendance report · Invoices and reports · Activity history · Complaints · Classes.
- **Deliberate gaps, so nobody "fixes" them by widening access:** a teacher has no Students tile because teachers hold no student-list permission (their class lists are under *My classes* and attendance); there is no "Today's appointments/queue" tile because the app has no appointments feature; *Financial reports* is *Invoices and reports* (the invoice screen is the report, with filters and a spreadsheet download); a parent's *Fees* goes straight to the invoice when there is one child with an invoice, otherwise to the children list where each has its own invoice link.

**Adding a tile:** add an entry to `TASKS` (icon, `route`, `navRoute`, optional `needs`/`roles`/`badge`), put its key in the right `PRIORITY` list, and add `home.task.<key>.title|desc` to all four `lang/*.json`. Then run `tests/e2e/role_home.py`: it requests every tile and menu link for eight accounts.

**Plain school words in the menu.** Renamed: Dashboard → *Home*, Grades & Sections → *Classes*, Teaching Assignments → *Who Teaches What*, Result Approvals → *Approve Results*, Staff Accounts → *Staff*, Payment Verification → *Check Payments*, Fee Structures → *School Fees*, Optional Services → *Extra Services*, Audit Log → *Activity History*, Promote Students → *Move Students Up a Grade*, Academic Years & Terms → *School Year & Terms*, School Profile → *School Details*, Parents → *Parents & Guardians*; sections Academics → *Classes & Teaching*, Finance → *Money*, Oversight → *History*. Renamed labels live under `menu.*` (draft translations); labels that were not renamed keep their existing `nav.*` strings, so the trusted Amharic wording is untouched where the meaning did not change. The test fails if developer words (Structure, Assignment, Audit, Verification…) reappear in any role's menu.

**Progressive disclosure in the sidebar.** A long menu (more than 12 items — owner, full admins, auditor) folds its sections: the first three and the one holding the current page are open, the rest are one tap away (44px headers), the choice is remembered, and opening a page inside a folded section opens that section. Short menus (teacher, parent, front desk, accountant) are shown whole. Nothing is removed.

**A permission gap this phase found and closed.** The branch-admin dashboard sent the fee-collection figures and pending-payment count to *every* branch admin, including a front-desk registrar with no finance permission. `DashboardController::orgStats` now sends money figures only to people holding `view-financial-reports`, `manage-invoices` or `manage-fee-structures`, and the pending-payment count only with `verify-payments`; otherwise `null`, and the home page renders only what the server sent. Covered by `DashboardPermissionsTest` (fails against the old code) and the browser test.

## Receptionist experience (Phase 3)

The path: **Register a student → choose a class → link a parent → complete → print the ID card → find the student again.** All on the shared system (`Components/ui`), same routes and same fields as before.

- **Registration wizard** (`Pages/Students/Create.jsx`, on `useWizard`): six steps — the child's name (3 fields) → about the child (gender + birth date; mother's name, address, emergency phone and previous school are folded into "More details (optional)") → **class** → who the parent is → the parent's details (skipped when "add the parent later") → a final check with a "Change" shortcut per answer. Never more than three decisions on a step; Next says *why* it is off. **No silent defaults on things that end up on a record:** gender and the parent's relationship must be chosen (they used to start as "male" / "father"). The date-of-birth field is now a large calendar-aware control (`DateInput large`).
- **Class cards** (`Components/SectionPicker.jsx`, also used for "move to another class"): each card shows grade + section, the class teacher and free places. More than 8 classes → pick the grade first (large buttons), then its classes. A **full** class is disabled for someone who may not override capacity ("FULL — ask an administrator"); someone who may override can pick it and must give a reason (kept in the audit log). Capacity is still enforced by `SectionCapacity` on the server. Teacher *names* only; no teacher contact details are sent.
- **Confirmation** — after registering, the student list opens on a "*Name* is registered" panel (ID number, class, parent) with **Print ID card / Open profile / Register another student**; the one-time link for a new parent's account is still shown. Driven by a one-time `registered_student` session flag that is only honoured for a student inside the user's own branch scope.
- **Find a student** (`Pages/Students/Index.jsx`): one large, autofocused box — "Type a name, ID number or parent's phone". Matches name words in any order, ID number, class and parent names in the browser; **a parent's or emergency phone is matched on the server** (`StudentController@index`, `?phone=`, at least four digits, `PhoneNumber::searchKey`, same branch scope) which answers with **matching ids only — no phone number is ever sent to the page to be searched.** Results are cards (name, class, ID, parent, and *Open profile* / *ID card*); with nothing typed the newest registrations are shown; nothing found gives a clear message with what to try and a *Register a new student* button.
- **Profile** (`Pages/Students/Edit.jsx`): who they are, class, ID number and status first; **Print ID card** and **Report card** as large buttons; the parents always visible, each with a **tap-to-call** number (shown only to people who manage students — auditors see none); everything else folded into worded sections (personal details, photo, move class, extra services, and a red *Withdraw this student* corner). The three browser `confirm()` boxes on this page are now proper dialogs.
- **Printing:** the ID card's *Print ID card* is the large primary action on its page (with the "Save as PDF" hint), and the ID card is one tap from the confirmation panel, every search result, and the profile. The app has no other student documents, and nothing tax- or receipt-related was added.
- **Deliberately not changed (and worth watching in Phase 0):** linking an *existing* parent is by email only (there is no phone lookup); a *new* parent account still requires an email (an existing business rule — families without an email are a likely friction point); the ID-card page text is still English-only.

## Transport, ASP and per-student prices

Optional services are now grouped and priced per student where needed. **Business rules unchanged:** a service is billed only to students who opted in; invoice lines are frozen snapshots; withdrawing never edits a charge already on an invoice; permissions (`manage-invoices` for a student's services, `manage-fee-structures` for the service list), branch scope and audit logging are as before.

- **Kinds** (`optional_services.category`: `transport` | `asp` | `other`, default `other`, so all existing services keep working). Make one service per transport route ("Transport - Kela", monthly 800; "Transport - Ashewa Meda", monthly 950), set its Kind to Transport, and the server allows **one transport route per student per year**: choosing another replaces the first (`App\Services\StudentServices::enroll`, audited as `student.service_withdrawn` with `replaced_by`). If the old route's charge is already on this period's invoice it is **not** removed; the person sees a warning to add a discount. A service cannot be switched to Transport while it would leave a student on two routes.
- **Student's own price** (`student_optional_service.custom_amount`, nullable). Invoice generation uses it when present, otherwise the service's amount (`Invoice::addLineFrom(..., $unitOverride)`). Set/cleared on the student's profile (`PATCH students/{student}/optional-services/{service}`, numeric 0-99,999,999.99, audited `student.service_price_changed`). Changing it, or the service's own amount, never alters invoices already generated.
- **Screens.** `Components/ServiceChoices.jsx` (shared): Transport = one big card per route + "No transport"; ASP = big Yes/No (several ASP services become toggle cards); other services = toggle cards; the price on every option; a summary line ("Transport: Kela (800 ETB/month) + ASP Classes (600 ETB/term)"). Used in the **registration wizard** (an "Extra services" step after the class, shown only to people with `manage-invoices` when the class's branch has services; the server refuses a services selection without that permission) and on the **student profile** (each tap saves at once). Front-desk staff without invoice rights see no step and a read-only list. Nothing is charged at registration: services are attached and appear on the next invoice-generation run.
- **Invoices page:** a per-term fee with no terms set up used to end in a raw 422 error page; it is now a red message on the page (rule unchanged, nothing generated).
- **Deliberately not done:** converting an existing grade-wide "ASP" *fee structure* into an optional service (that changes who is billed; a school decision). Custom price is not asked in the wizard, only on the profile.
- **Tests:** `tests/Feature/TransportAndAspServicesTest.php` (19), `tests/e2e/services.py` (45 real-browser checks). New strings are the `services` namespace, `draft` in am/om/ti.

## Teacher attendance (Phase 4)

The path: **choose the class -> see the students -> mark -> save -> a clear confirmation.** Same routes, same fields, same rules; only the screens changed (`Pages/Attendance/Sections.jsx`, `Take.jsx`, `Components/AttendanceParts.jsx`).

- **Class choice.** One large card per class the person may mark (a teacher: only their own homeroom classes; a branch admin: any class in the branch), showing progress in words ("23 of 30 students marked"), a status badge (icon + words) and one named button: *Take attendance* / *Continue attendance* / *Check or change attendance*.
- **Marking.** One card per student with five large (64px) buttons - Present, Absent, Late, Excused, Sick, the five statuses the app already has - each an icon + a word + a colour; the chosen one is filled and ticked. **Nobody starts as "present":** every student starts *Not marked yet* (dashed amber card, in words), and a progress panel says "3 of 5 students marked - 2 not marked yet" with the split by status. A *Not marked yet (n)* filter shows only what is left.
- **Mark everyone present** marks only the students who are still unmarked (its label says how many: "Mark the 2 unmarked students present"), so it can never overwrite an absence or a correction already made. Before, it overwrote everyone.
- **Before saving, the consequences are stated** - but only when there are any, so an ordinary all-present save is one tap: marking a student **absent tells their linked parents right away** (the count of students with a linked parent is shown; "no parent is linked, so nobody will be told" is said when true); changing an already-saved mark lists each change ("Feven: Present -> Late"), and changing an **absence** says the parents who were told will be told it was **corrected**; students still unmarked "stay unmarked (not present, not absent)". *Go back and check* is the focused choice. Changed rows say "Saved earlier as Present (by <name>). You are changing it."
- **Only new or changed marks are sent**, so a mark that did not change is not re-stamped with someone else's name. Save is off, and says why, until something is marked/changed.
- **Confirmation screen** (not a toast): "Attendance saved - 3 students recorded - Grade 3 A - date", the split, who was told, "2 students are still not marked" with *Mark the rest*, and the next actions *Back to my classes* / *Check or change this attendance*.
- **Other days:** the date is folded ("Take attendance for another day"); a day other than today gets a warning; changing the date with unsaved marks asks first.
- **Rules deliberately NOT changed:** one record per student per day; the five statuses; who may mark which class; the absence and correction notifications; audit logging; the server accepting a save for only part of the class (it always did). **Behaviour changes that are UI-only:** unmarked students are no longer saved as present by default; "Mark everyone present" no longer overwrites already-marked students; unchanged marks are not re-sent.
- **Small backend change (display only):** `AttendanceController@show` also returns `today`, who saved each mark, and a per-student *count* of linked parents (never who they are); its date lookups use `whereDate` (same rows, but correct on every database).
- **Tests:** `tests/Feature/AttendanceWorkflowTest.php` (11: no default marks, partial save, every status, validation, who may mark what, notifications once / on correction / silent otherwise, audit) and `tests/e2e/attendance.py` (69 real-browser checks: normal, all-present, partial, correction, other day, validation, authorization, phone, Amharic).
- **Not verified:** a real teacher doing it - see Phase 0. New `attendance` strings in am/om/ti are AI drafts.

## Teacher marks (Phase 5)

The path: **pick the exam -> one student at a time -> type the mark -> Save & Next -> finish -> submit for approval.** Same routes, same fields, same server rules; only the screens changed (`Pages/Marks/Index.jsx`, `Enter.jsx`, `useUnsavedGuard.js`).

- **The list** (`/marks`): one card per class and subject, an exam per row with its state (icon + word: not started / draft / submitted / approved / published), "12 of 30 students have a mark", and one named button (*Start entering marks* / *Continue entering marks* / *View marks*).
- **One student at a time.** A compact strip always shows Class, Subject, Exam and *Out of*; then "Student 3 of 30" with a bar, the student's name very large in an outlined card, a **large numeric input** (72px, focused for you, decimal keypad on phones), and **Save & Next** as the primary action (pinned to the bottom on phones; Enter does the same; on the last student it says *Save & Finish*). *Previous student* / *Next student* move without saving. Opens on the first student who still has no mark.
- **Existing marks are shown twice**: in words ("Saved mark: 72 out of 100") and prefilled in the input; changing it says "Changed from 72 - not saved yet". *All students* is the overview list (each row: name, mark, Saved / Not saved yet / No mark yet, tap to jump).
- **Mistakes are caught where they happen**: letters, negatives, more than two decimals and marks above the maximum are refused beside the input in plain words (a comma decimal is understood); the buttons that would carry a bad value forward are off and say why. The maximum ("Out of") is one number for the whole list, changeable under *Change the maximum mark*; lowering it below existing marks flags those students. The server still validates everything (and now also refuses values the column cannot hold instead of failing in the database).
- **No accidental loss**: typing survives moving between students; leaving the page (menu, links) asks "Leave without saving?" with *Stay and keep my marks* focused; closing or reloading the tab triggers the browser's own warning. (The browser's Back button inside the app is not intercepted.)
- **Success feedback**: after each save "Saved. Almaz: 87.5 out of 100." and, at the end, a confirmation screen ("Marks saved - 28 of 30 students have a mark") that names any student still without a mark with a one-tap way to enter it.
- **Submission** is its own clear step, with the consequences stated first: after submitting the marks are locked, the approver can send them back, parents see results only after approval and publication, and how many students have no mark and **what the school's missing-mark policy does to them** (left out of the average, or counted as 0). If typing is unsaved it is saved first, then submitted.
- **After the draft** (submitted / approved / published) the page is read-only, shows the saved marks, states where the marks are and what happens next (who can send back or reopen). A batch sent back is a draft again, with its marks.
- **Rules deliberately kept (do not "simplify" these away):** *every save sends the whole class list, blank = a row with no score*. Report cards treat "no score" (the missing-mark policy applies) differently from "no row at all" (not applicable, e.g. a student who joined later), so a student the teacher has not reached yet must still get their row - exactly as the old table did. Draft -> submitted -> approved -> published, `enter-marks` + current teaching assignment + branch scope, the row-locked draft check and the audit entries (`mark.updated` only when a score changed) are untouched.
- **Small backend changes (display + validation only):** `MarkBatchController@index` adds `students` and `entered` counts; `@show` adds the batch's single `max_score` (the first saved mark's - previously the first *student's*, which read 100 whenever that student had no mark yet), the school's `missing_mark_policy` and each student's ID number; `@store` adds `max:999.99` to score and maximum (matches the `decimal(5,2)` column).
- **Tests:** `tests/Feature/MarksWorkflowTest.php` (14) and `tests/e2e/marks.py` (71 real-browser checks: entering, invalid marks, saving, next/previous, unsaved-work protection, finish, submit, locked/sent-back states, unauthorized access, phone, Amharic).
- **Not verified:** a real teacher doing it - see Phase 0. New `marks` strings in am/om/ti are AI drafts.

## Accountant payment workflow (Phase 6)

The path: **payments to check (a queue, oldest first) -> one payment -> Payment Received / Reject Payment -> confirmation with the receipt number -> Verify next.** Same rules, same endpoints for deciding; new screens (`Pages/Payments/Pending.jsx` = the queue, `Pages/Payments/Review.jsx` = one payment, `Components/ProofViewer.jsx`) and one new read-only route, `GET /payments/{payment}` (`payments.review`).

- **The queue:** one card per waiting payment - the student and class, the amount very large, the way of paying (icon + word), the reference, the invoice, who sent it and when, a proof thumbnail, and one button (*Check this payment*); "Check the oldest payment" starts the run. A payment that can never be accepted (more than the invoice owes, or a waived invoice) is flagged on its card. Filters: grade (a list) and way of paying (buttons).
- **One payment answers five questions, in order:** *Who paid* (the parent, with their relationship, and the child + class), *How much* (the biggest thing on the page), *For what* (the invoice, its lines, total / already paid / still owed), *How* (way, reference, when) and *What to do next*. It also says what an accepted partial payment leaves ("1,400 ETB will still be owed") or that the invoice will be fully paid. The proof is **large on the page** (zoom, turn a sideways photo, open full size); a PDF opens in a new tab through one big button (the proof is served with a sandbox header, so it is not embedded); no proof says so. On a phone the two decisions are pinned at the bottom, after the proof in reading order.
- **Payment Received** (the existing *verify*) asks first, in words: what is recorded, what the invoice becomes, that a receipt number is created and the parent told, and that it cannot be undone here (a mistake is corrected by a refund or reversal). **Reject Payment** opens a panel with common reasons to tap (or your own), a live counter and the rule visible; the button stays off until a reason exists, and the server still requires one (max 255).
- **After the decision the page becomes the confirmation:** *Payment received* with the **receipt number**, the way and reference, the invoice's new state, who checked and when, then **Print receipt (PDF)** (the existing receipt), **Verify next payment - <who>, <amount>** and *Back to payments to check*. A rejection shows the reason given to the parent, that they were told, and the same next actions. Nothing tax-related was added; the receipt is the existing PDF.
- **Possible duplicates are pointed out, never blocked:** another payment (in the accountant's own branch) with the same reference number, or the same amount for the same invoice, is listed with a link while the payment is still undecided.
- **Two people deciding at once:** unchanged and now visible. If a second person presses *Payment Received* on a payment already decided in another tab, the server (row lock + re-check) changes nothing and the page says "already verified by someone else. Nothing was changed."
- **Refund / reversal** (only for people holding `refund-payments`, on a verified payment) is offered on the payment's page: a plain choice between the two kinds, the most that can be returned, a required reason, an optional reference, a confirmation ("the payment stays on record; a separate entry is subtracted; parents are told"), and a list of money already returned. Over-asking is refused on the spot; the server's limit (`refundableAmount`, under a lock) is unchanged. The refund form on the invoice page still works.
- **Rules deliberately unchanged (do not "simplify" these):** a linked parent submits proof and staff verify or reject it (`InvoicePolicy::pay`; staff can now ALSO record money received at the school, see "Two ways to get service"); a payment above the invoice balance or on a waived invoice cannot be verified; the payment / invoice / receipt-counter lock order; receipt numbers `RCT-yyyy-nnnnnn` issued only after every check passes; only verified payments count as money; rejection needs a reason and is audited (`payment.rejected` with the reason as the note); refunds are separate append-only records; branch scope on every read and write; the parent's contact details are not sent to this page.
- **Small backend change:** `PaymentController@review` (read-only, `verify-payments` + branch scope) and, after a decision, `verify` / `reject` redirect to that payment's page instead of `back()` (errors still go back). The queue also returns `review_url` and `period_label`.
- **Tests:** `tests/Feature/PaymentWorkflowTest.php` (17) and `tests/e2e/payments.py` (80 real-browser checks, starting from real parent uploads: normal, partial, verification, rejection and its reason, a repeat, duplicates, receipt and proof, refund, unauthorized users, phone, Amharic).
- **Not verified:** a real accountant doing it - see Phase 0. New `verify` strings in am/om/ti are AI drafts.

## Creating bills (Phase 7)

The path: **Invoices -> "Create bills" -> 1 Who -> 2 What and when -> 3 Check, then create -> a result screen.** New page `Pages/Invoices/Generate.jsx` (`GET /invoices/new`); the Invoices page's inline grade dropdowns were replaced by one large "Create bills" button.

- **Step 1, who:** the grade as a large button showing its student count ("Grade 9 - 3 students"; a grade with none cannot be picked). A branch question appears only for people who work across branches. Bills are made per **grade** (all its classes together) - that is the existing backend rule, not a per-class choice.
- **Step 2, what and when:** the grade's fees in words ("Tuition 1,500 ETB a term"), the number of students, how many also take extra services (transport, ASP), then a decision **nothing is preselected for**: *Bill the whole year now* (every period at once, parents told today) or *Only some billing periods* (yearly fees, each term, and the months in a folded list; each period shows "3 bills - 4,500 ETB", or **"Already billed for everyone"** and cannot be chosen). Things that stop a run are explained with a way out instead of an error page: no students, no fees (link to School fees), a per-term fee with no terms set up (link to Academic Years), or everything already billed.
- **Step 3, check:** the headline is the outcome - **"Create bills for 32 students"** - with the bills and total, who it is for, the periods and bills in each, how many messages parents will get, a warning when some bills start in the future (parents are told today) and who will be skipped and why. The final green button carries the same words. A note says a bill cannot be deleted afterwards and how a wrong one is fixed (discount or waive).
- **Result screen:** "Bills created - 3 bills - 3 students - Grade 9", the bills per period, the total, messages sent to parents, and **who was skipped and why** - *already billed for these periods*, *left the school before the period began*, *nothing to bill* - each with the names on tap. Next actions: *See these bills*, *Create bills for another grade*, *Back to invoices*.
- **Duplicate prevention is the existing rule, and is visible:** a period is billed once per student (unique key + lock, unchanged); running it again creates nothing; the raw POST cannot make a copy either. New: the workflow shows it before you press anything.
- **The preview cannot drift from the real run.** `GET /invoices/generate/preview` runs the same loop as `POST /invoices/generate` with `billPeriod(..., dryRun)` writing nothing (`InvoiceController@runGeneration`), and a PHP test asserts the two produce identical counts, amounts and messages. The invoice-creating code (transaction, school-row lock, once-per-period, frozen lines, a student's own price for a service, notifications after commit) is unchanged; `billPeriod` now reports 'created' / 'exists' / 'withdrawn' / 'nothing' in words instead of 1/0.
- **New optional `periods`** on both endpoints (`annual`, `term:<id>`, `month:<first day>`): billing only some periods. Leaving it out means every period, exactly as before; an unknown or empty choice is refused (422 / validation error) and creates nothing. The audit entry records which periods were chosen. (Before, an accountant could only ever bill the whole year at once.)
- **Small tightening:** a grade from another school is a 404 instead of an empty run; the once-per-period existence check uses `whereDate` (correct on every database).
- **Rules deliberately unchanged:** `manage-invoices` + branch scope on the page, the preview and the run; students billed are active ones plus those who left this year (for periods that began before they left); optional services bill only students who opted in; nothing here decides amounts.
- **Tests:** `tests/Feature/InvoiceGenerationTest.php` (14: preview = real run, own price in the preview, twice creates nothing, only-what-is-missing, choosing periods and finishing the rest without repeats, refused periods, left-school / nothing-to-bill reasons, blocked states, authorization, foreign grade, page props, audit) and `tests/e2e/bills.py` (51 real-browser checks). `tests/e2e/services.py` was updated to use the workflow.
- **Not verified:** a real accountant doing it - see Phase 0. New `gen` strings in am/om/ti are AI drafts.

## School fees (Phase 8)

The path: **School fees (the list) -> Add a fee -> 1 Where -> 2 What -> 3 Check and save -> confirmation**, and **Change** on any fee. New page `Pages/FeeStructures/Wizard.jsx` (`GET /fee-structures/new` and `GET /fee-structures/{id}/edit`); `Pages/FeeStructures/Index.jsx` was rebuilt on the shared system.

- **The list:** fees grouped by grade for one school year (the current one is preselected, *All years* is a button), each in words - "1,500 ETB - Every term" - with **Change** and **Remove**, and a line naming grades that have no fee yet. *Remove* asks first and says bills already made are not changed.
- **Step 1, where:** the school year as cards (the current one labelled) and the grade as large buttons that say how many fees each already has; nothing is chosen for you. The fees **already set** for that grade and year are listed under it, so a repeat is visible before it is typed. A branch question appears only for people who work across branches.
- **Step 2, what:** the fee name (with tap-to-fill common names and the school's existing names), the amount (a plain number, up to two decimals) and *how often* as three cards - **Once a year / Every term / Every month** - each explained in one line. Mistakes are shown beside the field in words (a name this grade already has, an amount that is not a number); a per-term fee in a year with no terms warns at once.
- **Step 3, check:** the whole fee read back ("Books - 2,500 ETB / Every month - Grade 9 - 2026/2027"), every answer with a *Change* button, a warning if the amount is 0 (allowed) or a per-term fee has no terms to be billed in, and "Bills that already exist are not changed. This fee is used when bills are created from now on." The final button says **Save fee structure**.
- **Confirmation:** the summary line and the same note, then *Add another fee for Grade 9* (straight to step 2), *Add a fee for another grade*, *See all school fees*.
- **Changing a fee** is the same page without step 1 (two steps): the backend only lets a fee's name, amount and frequency change, so the grade, year and branch are shown and locked ("to move a fee, remove it and add it again"), the fee is prefilled, and the check step shows "was 2,500 ETB" beside the new amount. Before this phase the page could not change a fee at all, although the update route existed.
- **There are no optional fields**: a fee is a name, an amount and a frequency in a grade, year and branch - all required - so nothing says "you can leave this empty"; nothing was invented to carry that hint.
- **New rules (small, server-side):** (1) **one fee name per grade, school year and branch** (compared without regard to case or spaces; soft-deleted fees do not count; renaming to another fee's name is refused too) - before, nothing stopped a second "Tuition" being added, which would have billed every student twice; (2) the amount may not exceed 99,999,999.99 (what the column holds) instead of failing in the database. Existing data is not touched.
- **Rules deliberately unchanged:** `manage-fee-structures` on every page and endpoint; branch scope (a branch user sees and changes only their branch's fees; grade, year and branch must belong to their school); a fee is billed exactly as before by invoice generation (`tests` confirm a fee made this way is billed); changing or removing a fee never alters bills already made; removal is an archive (soft delete); the fee calculation is untouched.
- **Tests:** `tests/Feature/FeeStructureWizardTest.php` (12: create, free fee, every frequency, billing still uses it, validation, scope, duplicates, edit, bills untouched, page data, authorization) and `tests/e2e/fees.py` (58 real-browser checks).
- **Not verified:** a real accountant doing it - see Phase 0. New `fee` strings in am/om/ti are AI drafts.

## The parent portal (Phase 9)

Assumption: a parent who can use a smartphone but has little computer experience. The parent's home is **its own page**, not the generic task tiles used for staff (`Components/ParentHome.jsx`, chosen in `Pages/Dashboard.jsx` when the role is parent).

- **Home, in this order:** (1) **the child** - with several children a row of big buttons ("Whose information do you want to see?": initial, first name, class; the chosen child filled and ticked) switches everything below **in place, without reloading**, and the choice is remembered on the phone; (2) **Today at school** - today's status as a big icon + word + colour (or "Not recorded yet today" - never "present" by default), the last days as wrapping chips, the year's rate in words, *See all attendance*; (3) **School fees** - **Left to pay** in huge type, *Total to pay* and *Already paid*, a progress bar, a plain status (Fully paid / Partly paid / Not paid yet / Waived), "1 payment is waiting for the school" when a proof is being checked, each bill with its own *Pay* / *See* button when there are several (per-term or per-month bills), otherwise one *See the bill and how to pay*; (4) **Report card** - *View report card* and *Download PDF*; (5) **Messages from the school** - the latest three, unread ones coloured with a "New" badge, *Mark all as read*. **Unread messages are also announced by a banner at the very top** ("You have 2 new messages from the school"). Complaints and Announcements sit below as "Other things".
- **The money is Finance's, added up across all of a child's bills for the current year** (before, the home showed only the first bill, which is wrong once bills are made per term or month); `bills` lists each with its own figures, `invoice_id` is the oldest bill that still owes something, and pending payments are counted separately. Refunds put money back on what is owed exactly as elsewhere. Children are sorted by first name, the same order as the other parent pages.
- **Attendance page:** the same child switcher (goes to that child's page), *today* first (whichever month is being viewed), the year's rate very large with five icon + word tiles, a month chooser, and every recorded day as a card instead of a table.
- **The bill page for a parent** (`Components/ParentInvoice.jsx`, shown by `Invoices/Show` for the parent role; staff still get the full page): *Left to pay*, total and paid; what the bill is for; **How to pay** as three plain steps ("Pay the money to one of these accounts / Take a clear photo of the receipt / Send the photo here, below"), the school's accounts with the number large and a **Copy number** button, and the existing proof-of-payment wizard right below; **Your payments** with each one's state in words (*Waiting for the school* / *Accepted* + receipt download / *Not accepted* + the school's reason and "You can send a new photo below"). None of the staff tools (discount, waiver, refund) appear.
- **Report card:** *Download PDF* and *Print* are the first things on the page (large); only the card prints; for a parent the administrative weight warning is replaced by "Some results are still being finalised by the school, so this report card may change." The per-child hub gives each child *View* and *Download PDF*.
- **Messages page:** unread cards are coloured with a "New" badge and a large *Mark as read*, a *Mark all as read* button and newer/older paging (it used to ignore its 20-per-page limit).
- **Not added:** no new parent features. Everything shown was already known to the pages (guardian-linked children, attendance, invoices and payments, published report cards, notifications).
- **Preserved, and tested:** authentication and the parent role; **child ownership** - every read goes through the guardian link, another family's child, bill, report card or PDF is a 404, and another parent's message cannot be read or marked; a parent cannot verify, discount or waive anything; the unchanged proof-upload validation and secure file serving.
- **Tests:** `tests/Feature/ParentPortalTest.php` (13) and `tests/e2e/parent.py` (57 real-browser checks on desktop and a phone, plus Amharic); `tests/e2e/role_home.py` now checks the parent's own home.
- **Not verified:** a real parent using it - see Phase 0. New `parent` strings in am/om/ti are AI drafts.

## Administrator workflows (Phase 10)

Assumption: an owner or branch admin who is comfortable with computers but not with software vocabulary ("tier", "permission slug", "audit action code"). Administrators keep **all** their power and their fuller navigation; what changed is that each screen says in words what it does and asks one thing at a time. Nothing about who may do what, branch scope, capacity, duplicate prevention or the audit trail was relaxed.

- **Staff (the most important one).** *Add a staff member* is a page with three steps: **(1) who** - name, job title, phone, email; **(2) what may they do** - system role, branch, permissions; **(3) check** - every answer read back, then **Add staff member and make invitation**. The core distinction is drawn on the page, twice: **job title = only a label** ("Vice Principal", it changes nothing) versus **system role and permissions = what they can really open and do**. The role is a set of large cards, none preselected; a branch admin starts with the **usual set of permissions already ticked** (limited to what the person adding them holds - before, the checklist started empty and silently made a branch admin who could do nothing); the other roles show their fixed permissions as a plain list (they cannot be customised one by one - that rule is unchanged). Permissions are **grouped and worded** ("Check payments - Look at payment proofs and accept them or send them back with a reason") instead of raw names like `verify-payments`, with a count ("18 of 24 ticked"), *Use the usual set* and *Untick everything*. A permission the person adding does not hold is **disabled and says why**. The new account's **one-time invitation link** is shown once, large, with *Copy the link* and what to do if email is not set up. *Change* on the list edits job title, phone and whether they can sign in (with a warning that switching someone off stops sign-in), and - for a branch admin - the permissions; it says so when a permission of that person is hidden from you.
- **Sections.** *Add a section* is three steps (grade + branch + school year with what already exists shown; name with unused suggestions and capacity, optional; check). The Grades and sections page now shows, per section, **how full it is and who the class teacher is** (with a link to choose one), and finally lets you **change a section's name and capacity** (the endpoint existed; there was no button). Removing anything opens a dialog saying what can never be removed instead of a browser box that claimed "this also removes its sections" (untrue: the database refuses).
- **Teacher assignment ("Who teaches what").** It starts from the **class**: cards showing each class, its class teacher and how many subjects have a teacher; open one to see its class teacher, every subject with its teacher, and *Give a subject to a teacher* as two questions (which subject - buttons that show who teaches it now; which teacher - only teachers of that class's branch, which the server requires anyway). A plain sentence reads it back ("Meseret Alemu will teach Mathematics in Grade 3 A"); replacing a teacher opens a dialog saying that unsent mark sheets move with the subject and submitted/approved/published ones stay. Coming from the Grades and sections page opens that class directly.
- **Fee structures.** Already the Phase 8 wizard; nothing was changed here except that it stays reachable from the admin navigation.
- **Audit log.** The same entries, the same filters, the same Excel export, nothing removed - but each entry now opens with a **plain sentence** ("A staff member's permissions were changed") **beside the raw action code**, who, record type and number, when, the reason highlighted, and the recorded **before/after values** in words ("was ..., now ...", the first four shown, the rest one tap away) plus the IP address. Unknown action codes fall back to "Changed: a section" rather than to nothing. The record-type and action filters are worded and keep the code in brackets.
- **Settings.** Still one form and one **Save** for the school's own settings (so no rule became optional), now in labelled parts with a short list to jump to: school details, calendar, report cards (missing scores - the option that changes every student's average, with a warning), discounts/waivers/terms, languages (each with its honest review status), and payment accounts (its own steps: which bank, which branch, the details; removal asks first). A pinned bar says *You have changes that are not saved yet* / *Everything is saved* and Save is off until something changed; leaving with unsaved typing asks first.
- **Fixed on the way:** the shared modal could not be scrolled to its top when its content was taller than the screen (the panel was centred so its top was cut off) - this hit the new staff edit form and would have hit any long form; `ChoiceGroup` gained an optional visible `heading` (its `label` is only spoken); the wizard's role step lost the single-branch fill after picking an across-branches role and back; after *Add another staff member* the form kept the previous person's answers; a taken section name used to be a server error and is now a message beside the field; the payment-account list widened the whole page on a phone.
- **Not changed, on purpose:** which permission does what; the clamp (nobody can grant what they do not hold); only owners add owners and auditors; a branch admin only ever works in their own branch; capacity and one-section-name-per-grade-branch-year; one teacher per subject per class; no hard deletes; every audit entry.
- **Known and left alone (reported, not silently changed):** saving a branch admin's permissions from an account that lacks some of them **removes** those permissions (the clamp applies to the whole list); the screen now warns about it. The permission `manage-assignments` is not used by any screen. The class-teacher endpoint does not check that the teacher is active.
- **Tests:** `tests/Feature/AdminStaffWorkflowTest.php` (15) and `tests/Feature/AdminStructureWorkflowTest.php` (16); `tests/e2e/admin.py` (150 real-browser checks: desktop, a phone, Amharic, the permission matrix).
- **Not verified:** a real administrator using it - see Phase 0; the plain-language permission descriptions have not been read by anyone who runs a school. New `staff`, `perm`, `sect`, `struct`, `teach`, `audit` and `settings` strings in am/om/ti are AI drafts.

## The remaining complex forms (Phase 11)

**The rule that was applied.** A form became a wizard only if it had many fields *or* several decisions that each matter (several people affected, money, records that are hard to undo). Everything else stayed a short form, and got clearer words and the effect shown before saving instead. Nothing was converted "because it was there".

| Form | Decision | Why |
|---|---|---|
| **Moving a class up (promotion)** | **Wizard, 4 steps** | Which class, a decision *per student*, a destination with seats and an exception, and it changes many enrolments at once. |
| **Announcement** | **Wizard, 3 steps** | Five fields, and it reaches many people; the check step shows the message as it will be read. |
| **Extra service (transport route, after-school class)** | **Wizard, 4 steps** | Six fields in one inline row (where / what / cost); the same shape as the fee wizard. |
| **Student details (edit)** | **Progressive disclosure, one form** | It is an edit, so a wizard would be wrong (the person changes one thing). Ten fields were a wall; now three folded groups. |
| **Discount / waiver** | Short focused dialogs | One or two fields; what they need is the effect shown *before* saving (the new total) and the school's limit in words. |
| **Refund** | Its own careful page (Phase 6) | The bill now links to it instead of keeping a second, older copy of the form. |
| **Exam** | Short form | Four fields and one number that matters: the term's weight total, now shown live. |
| Withdraw, move to another class | Unchanged (already a folded section + a confirm dialog) | Two fields plus a consequence dialog is the right size. |
| Staff editing | Unchanged (Phase 10) | Already a grouped modal. |
| Branches, academic years and terms, subjects, grade bands, grades, section edit, complaint (parent) | Unchanged | 2-4 simple fields each. "Make current year" already asks the person to type the year's name. |

- **Promotion (`Promotions/Index.jsx`, same route).** (1) which class (school year buttons, grade buttons when there are many classes, then class buttons with their student counts); (2) **what happens to each student: Moves up / Graduates / Not this time - nobody is decided for you** ("3 of 5 students decided", *Everyone moves up* / *Everyone graduates* for speed); (3) where they go (the class cards with their places left, the grade after the one moving out opened first; if there are fewer places than students a warning says how many would stay, and someone allowed to may make an exception with a reason); (4) a check: "3 students move up to Grade 2 A / 1 student graduates / 1 left out", each group's names one tap away, and a button that says exactly what it does ("Move 3 and graduate 1"). Then a result in words: how many moved, graduated, were already done, skipped or did not fit. The endpoint, the capacity rule (`SectionCapacity`), the branch scope, the once-per-student guarantee and the audit entries are untouched; the old flash sentence is still sent (an existing test pins it) and the counts are added.
- **Announcements.** *Write an announcement* opens a page: (1) title and message, (2) who (Everyone / Parents / Staff, each explained), which branch (school-wide only for accounts allowed to; branch staff are fixed to their own), and when (today, or a later day) - **nothing preselected** because it reaches many people, (3) a preview and *Publish announcement* / *Schedule announcement*. Same endpoint and rules (a publish date cannot be in the past; a branch admin cannot post school-wide). The list page lost its inline composer, marks scheduled ones and asks before removing.
- **Extra services.** *Add a service* is four steps (branch + school year with what already exists; name + kind - Transport / After-school classes / Something else - with "one route per student" said; amount + how often; a check that warns when the price is 0 or the name is already used). The list is cards; changing a service's kind and removing it now ask first (before, the kind changed the moment the dropdown moved).
- **Student details.** *Main information* (names, gender, date of birth), *More details* (mother's name, address, emergency phone, previous school) and *Status*, each folded with a worded summary; still one form and one Save, which is off until something changes and says which groups were touched. Changing the status asks first and says what it does: leaving *Active* takes the student out of their class, returning puts them back and needs a free place. An unsaved-changes guard was added. No field was removed.
- **Discount and waiver** (`Components/InvoiceAdjust.jsx`): the amount and the reason, the new total shown as you type, the school's limit stated, a warning when over it (the server still refuses and says so beside the field), *Take the discount away* when the amount is 0, and the waiver dialog says what waiving does. Taking a waiver back asks first.
- **Exams.** One form with a term choice, name, five type buttons and the weight; it shows "Term 2: weights would add up to 60%. 40% more is needed before results can be published" (or over 100%, or exactly 100%), and warns at once when the chosen term's results are already published.
- **Fixed on the way:** an old *Kind* dropdown that changed a service the moment it moved, with no confirmation; a status change that silently took a student out of their class; the bill page's second, older copy of the refund form (it now links to the payment's own page).
- **Left alone and reported:** returning a student to *Active* when their old class is full shows the server's error only - the server supports an exception but the profile does not offer one. The server's promotion moves only as many students as there are places unless an exception is given (the wizard now says so before you press the button).
- **Tests:** `tests/Feature/ComplexFormsWorkflowTest.php` (14) and `tests/e2e/forms.py` (118 real-browser checks: desktop, a phone, Amharic, who may not).
- **Not verified:** a real school office using these; the AI-drafted am/om/ti text (namespaces `promo`, `ann`, `svc`, `adj`, `exam` and the additions to `student`).

## Staff account lifecycle

Administrator-created accounts, no email needed: **create -> hand over the credentials -> the person signs in -> they choose their own password**. Details and rules are in `AUTHENTICATION.md`.

- **Create (`Staff/Wizard.jsx`, three steps).** (1) *Staff member*: name, phone, job title (label only). (2) *School access*: role, branch, permissions, and for a teacher an optional "class teacher of" (only classes that have none). (3) *Account*: a short summary, the phone typed again, a temporary password ("Make one for me" is the default; or "I will type one" with a Show password button), an optional email as contact information, then **Create Account**.
- **Success screen** (`Components/CredentialsPanel.jsx`, also used after a reset): "Account created successfully", name, phone, role, branch/class, **Username/login: the phone number**, the temporary password **once**, the warning to hand it over securely and that it must be changed at first log in, Copy and Print buttons (print area = exactly those details). Nothing is ever shown again; a lost one is replaced with *Reset password*.
- **Staff list.** Search by name, phone (any way of writing it), email, job title or role; each card shows name, phone, job title, role, branch and status; buttons: Change (name, job title, phone, permissions), Reset password, Disable account / Reactivate account, Allow sign-in again. Disabling asks first and says: signed out at once, cannot sign in, nothing deleted. A disabled card is dimmed and labelled "Switched off". You cannot disable yourself.
- **Never deleted.** Attendance, marks, payments, audit logs and the class-teacher link stay attributed to the person.
- **Email** stays as optional contact information and as the sign-in for old email-only accounts; nothing was removed.
- **Tests:** `tests/Feature/StaffLifecycleTest.php` (39), the staff parts of `AdminStaffWorkflowTest`/`PhoneLoginTest`, and the browser suites `tests/e2e/staff_lifecycle.py` (43), `admin.py` and `auth_phone.py`. **Not verified:** a real administrator handing an account to a real staff member; the am/om/ti wording (`staff`, `cred`) is an AI draft.

## Two ways to get service: the portal, or the school desk

**The rule.** The parent portal is a convenience, never a dependency. Every basic service a parent can get in the portal can also be given by authorized staff to a parent who walks in, and the parent needs no account, email, smartphone or computer for it. Helping a parent gives staff **no new power**: every part is gated by the permission that already guards that information, inside the staff member's own branch, and every money movement is audited.

- **"Help a parent" (`/students/{id}/help`, `Students/Desk.jsx`, `StudentDeskController`).** Reached from *Find a student* (a button on each result) and from the student profile. It shows: the parents (with a number to call, only to people who manage students; "no parent is linked - that is fine" when there is none); **Fees** (what the family owes, each bill, latest payments with *Print receipt*, and *Record payment received*); **Attendance** (today, totals, the last days; same formula as the portal); **Report card** (view / download-print the existing PDF). A part the person may not open is a plain "ask the accounts desk / the class teacher / the school office" note, never a hidden door. Permissions: fees = `manage-invoices` or `view-financial-reports`; record = `verify-payments`; attendance = `view-attendance-reports`; report card = `manage-students` (or an auditor); contacts = `manage-students`. A person with none of them gets 403. A receptionist therefore sees only the report card and the parents unless an administrator grants more.
- **Record payment received (`Payments/Record.jsx`, `POST /invoices/{id}/record-payment`).** Three questions - how (cash / bank slip / mobile money / other, nothing preselected), how much and who paid (the parent's name is one tap; a reference number is required for bank and mobile money; an optional photo of the slip), then a check that shows what is left. The payment is born **verified** with a receipt number and the printable receipt, because the person recording it holds `verify-payments` and is the one who received the money; it is stored with `channel = counter` and `paid_by_name` (the payer needs no account). The financial rules are the ones a parent's proof already obeys: the invoice row is locked, a waived bill takes nothing, nobody can pay more than the balance, the receipt number is issued last from the locked counter. Two extra guards: a warning lists any proof the parent already sent online for the same bill (so the same money is not counted twice), and the same amount / method / bill by the same person within a minute is refused (a double tap). Audited as one plain entry `payment.recorded` (who, how much, how, reference, payer, receipt, balance after); the portal parent is notified, and a parent without an account simply gets the printed receipt.
- **Unchanged.** The parent's own path (pay to a school account, upload proof, an accountant verifies) still works exactly as before and now also says "you can pay at the school office instead". Refunds still need `refund-payments`. No online payment gateway, no tax system.
- **Tests:** `tests/Feature/StaffAssistedServiceTest.php` (34: who sees what, branch and school boundaries, an auditor reads but cannot record, a student with no parent account, the money rules, the audit trail, the parent's view of a desk payment) and the browser suite `tests/e2e/assisted.py` (55: a receptionist, an accountant, a parent with no account, a parent with the portal, phone layout, Amharic).
- **Not verified:** a real front desk with a real parent; the am/om/ti wording (`desk`, `rec` and the additions to `find`, `parent`, `audit`) is an AI draft.

## The student's photo: taken or chosen by staff

Staff who may create and edit student records (`manage-students`, in their own branch) capture the photo; the student uploads nothing and gets no login. It is never required.

- **Registration.** The enrolment wizard has one more step, **Student photo**, right after *About the child* (7 steps; 6 when the parent is added later). It shows a placeholder and two large buttons: **Take Photo** and **Choose Photo**. *Take Photo* asks the browser for the camera (a phone or tablet's back camera, a laptop webcam), shows a live view in the page, then a preview with **Retake** / **Use This Photo**; *Choose Photo* is the ordinary file chooser (what a desktop uses). "Next" is always on: the step can be skipped and the photo added later. The review step says whether a photo was added, and nothing is stored until the last button.
- **No camera, no problem.** If the camera is missing, blocked, refused, or the page is not on a secure (https) address, the person sees one sentence - "Camera isn't available on this device. You can choose a photo instead." - and Choose Photo carries on. Camera access is never required.
- **Phones.** Every picture is shrunk in the browser to a JPEG of at most 1280 px and under the server's 2 MB limit before it is sent, because a modern phone photo is several MB. A file that is not a JPG/PNG, or cannot be read as a picture, is refused in plain words and nothing is chosen.
- **Profile** (*Photo*, folded when a photo exists). View the current photo, take or choose a replacement (**Use This Photo** uploads it), or **Remove photo** (asks first; the student record stays and the ID card simply shows no photo). The new file is written first and the old one deleted only after the record points at the new one.
- **Rules that did not move.** `StudentController@store` / `uploadPhoto` / new `removePhoto` need `manage-students` and the same school + branch scope as every student action. The server still checks the CONTENT (`SafeUploadRule`, JPG/PNG only, 2 MB), decodes, shrinks to 800 px and re-encodes under a random name on the same public disk and `student-photos/` folder as before (that is how the ID card and its verification page already read it); the uploaded name never reaches the path. An invalid photo refuses the whole registration with the reason on the photo step; a photo that passes the check but cannot be stored after the record is saved never blocks the registration (a warning says to add it from the profile). Photo changes are in the audit log (`student.updated`, with the old and new path).
- **Found on the way and fixed:** student numbers were `count + 1`, so any gap in the existing numbers (an import, a hand-typed number) made the next registration fail with a database error. They now come from the locked `number_sequences` counter (the same mechanism as receipt numbers), seeded with the highest number in use, and skip a number that already exists.
- **Tests:** `tests/Feature/StudentPhotoTest.php` (23) and the browser suite `tests/e2e/photo.py` (45: a real fake-camera stream in Chromium, the 5 MB phone photo, wrong files, no camera, the profile, who may, a phone layout, Amharic). `receptionist.py` and `services.py` were updated for the extra step.
- **Not verified:** a real phone or tablet camera (the browser test uses Chromium's fake camera; iOS Safari, Android Chrome and https-only camera access need a real device), and the am/om/ti wording (`photo`, and additions to `enroll`, `student`) is an AI draft.

## The receptionist's day (front desk redesign)

"Receptionist" is still not a role: it is a branch admin recognised by what they can do (`personaFor`: manages students, not staff or fee structures). Nothing about permissions or the backend moved; only what is put in front of them.

- **Home.** "Good morning / afternoon / evening, {name}" (from the browser's clock) and "What do you want to do?", then large tiles for only what they are allowed: **Register Student** (`manage-students`), **Find Student**, **Record Payment** (needs `verify-payments`, so a default receptionist does not see it), **Print Student Card**, **Find Parent**, and a **More** tile that opens everything else they may use (nothing authorised is ever dropped: Check payments, Classes, ...). Record Payment and Print Student Card lead to Find Student with `?task=payment|card`, which only adds a banner and one extra button per result; it grants nothing.
- **Find Student (`Students/Index.jsx`).** A very large search box (68 px, focused) that answers as you type. No exact name is needed: any words of the name in any order, the student number (whole or its end), the class, a parent's name, the mother's name, and a parent's or emergency phone number in any way of writing it (phone matching is answered by the server as ids only, as before). Each result is one simple card: name, *Grade — Section* and student number, *Parent/Guardian*, *Phone*, status, and a single large **Open Student** button. Clear empty state (newest students), clear no-results message with what to try and where to go (register, or look for a parent instead), 20 at a time.
- **Phone numbers stay out of the page.** The list still carries no phone number. The numbers shown on a result are fetched only for the results on screen (`GET /students/contacts?ids=` returns parent names, relationship and phone for at most 20 ids, needs `manage-students`, same school and branch; an id outside that is left out) and only for typed searches, so an auditor sees names but never numbers.
- **Keyboard.** `/` focuses the box from anywhere on the page, Enter opens the first result, the arrow keys walk the Open Student buttons (up from the first returns to the box), Escape clears. The shortcuts line is hidden on phones.
- **The student page (`/students/{id}/help`, formerly "Help a parent").** Open Student lands here. A clearly labelled action row, each shown only if that person may do it: **View and edit profile** (manage-students; an auditor sees **View profile**), **Fees and payment status** (fees permissions), **Attendance** (`view-attendance-reports`), **Report card** and **Print student card** (the same rule as the ID card / report card pages). Below it the parents with a number to call, then Fees (with Record payment received for `verify-payments`), Attendance and the Report card; a part the person may not open is a plain "ask ..." note. Move class, extra services and withdraw stay on the profile.
- **Find Parent (`Parents/Index.jsx`).** The same large search (name, phone in any form, email, a child's name or number), each child an Open Student button when the person may open students.
- **Tests:** `tests/Feature/FrontDeskTest.php` (13) and the browser suite `tests/e2e/frontdesk.py` (53). `role_home.py`, `receptionist.py` and `assisted.py` were updated for the new tile names and buttons.
- **Left alone and reported:** `ParentController@index` lists every parent of the school (and all their children) to anyone holding `manage-parents`, whatever their branch. A child in another branch cannot be opened (the student page is branch-scoped), but the name shows in the list. Not changed here (it is an authorization question, not a design one).
- **Not verified:** a real front desk using it; the am/om/ti wording (`home`, `find`, `desk`, `parents`) is an AI draft.

## The teacher's home and attendance (refinement of Phase 4)

**The rule did not move:** daily attendance is a HOMEROOM duty (`AttendanceController`). A teacher marks only the classes they are class teacher of; teaching a subject there grants nothing (even to someone holding `mark-attendance`); a branch admin or owner may cover any class in their scope (branch scoped for admins); every record stores who marked it. Validation, the five statuses, parent notifications (absent, and "corrected" when an absence changes), the audit trail and the correction behaviour are exactly as before (`AttendanceWorkflowTest`).

- **Teacher home (`Components/TeacherHome.jsx`).** "Good morning, {name}", then **Your class:** *Grade X — Section Y*, *N students*, **Today's Attendance** with progress in words ("0 / 30 marked") and one large **Take Attendance** button (*Continue Attendance* / *Check or change attendance* as the day goes). Two classes = two cards. A subject-only teacher sees a plain explanation (attendance belongs to that class's own teacher; ask the branch admin if they are away) and no button. The class and attendance tiles are not repeated below (they stay reachable under More).
- **Nobody is asked to choose what the system knows.** `GET /attendance` for a teacher with exactly one class (that has students) redirects straight to it; with two it lists only those two. Admins and owners always get the list. On that one-class path "back" says *Back to home* and *Done* goes home.
- **The attendance screen.** Heading = the class (*Grade X — Section Y*), *Today's attendance*, today's date, **0 / 30 marked** with the bar, **Mark everyone present** (marks only the unmarked), then a row per student with five large buttons (Present / Absent / Late / Excused / Sick; no dropdowns; 56 px+), then **Save attendance**. Before saving, anything important is explained: who will be told about an absence, and for a correction each change (*Absent → Present*) and that parents told about the absence will be told it was corrected. Then **Attendance saved** / *30 students recorded* / **Done**.
- **Tests:** `tests/Feature/TeacherAttendanceHomeTest.php` (14: homeroom, subject teacher with the permission, unrelated teacher, no permission, branch admin, other branch, owner, the away-teacher coverage and correction), `AttendanceWorkflowTest` (updated for the redirect) and the browser suites `tests/e2e/teacher_home.py` (40) and `attendance.py` (69).
- **Fixed on the way:** the dashboard's "marked today" count used `where('date', ...)`, which misses rows stored with a time on SQLite; it now uses `whereDate`. `index()` gained a redirect, so its return type is `Response|RedirectResponse`.
- **Not verified:** a real teacher with a real class list on a phone; the am/om/ti wording (additions to `attendance`) is an AI draft.

## Taking a payment at the desk (refinement)

The parent never logs in and no gateway exists: money arrives in person, or through a bank / mobile-money transfer that staff check (either as a slip shown at the desk, recorded by `verify-payments` staff, or a proof the parent sent online, verified from the queue). Every financial safeguard is unchanged: `verify-payments` and branch scope, the invoice row lock, no overpayment and nothing into a waived bill, receipt numbers issued last, refunds only with `refund-payments` and only as separate records, payments never edited or deleted, and one audit entry per movement.

- **The flow.** Find Student -> Open Student -> Fees (each bill with what is billed / paid / left) -> **Record payment received** -> three questions -> **Confirm Payment** -> confirmation.
- **The bill is always in view** (`Payments/Record.jsx`): student, grade/section, invoice, then **Invoice total / Previously paid / This payment / Remaining** as four lines that update as the amount is typed. A partial payment says "600.00 ETB will still be owed on this bill"; a full one says it settles the bill; more than is left shows no "this payment" and is refused.
- **Before saving** the check step reads *Student: X / Payment: 400.00 ETB / Remaining balance: 600.00 ETB*, and the button is **Confirm Payment**. **Notes** (kept with the payment, in the audit entry, on the confirmation) are optional, and required when the method is *Other*.
- **A reference used before.** The same bank / mobile-money reference on another accepted or waiting payment anywhere in the school is stopped once: for people who may see that payment the message names the bill and amount, for anyone else it only says it was used. It goes ahead only after the person ticks *this is a different payment*, and the audit entry records `duplicate_reference_confirmed`. Rejected payments never block a reference. (A parent's proof waiting for the same money is also listed at the top of the form, and the same amount / method / bill by the same person within a minute is refused.)
- **After saving** (`Payments/Review.jsx`, counter payments): *Payment recorded successfully.* with the amount, the remaining balance, the reference / transaction number (or "None (cash)"), the receipt number and the note, then **Print Confirmation** (the existing receipt PDF) and **Done** (back to the student's page, or the payments list for someone who cannot open students).
- **Verification of a parent's proof** is the existing Payment Received / Reject Payment page: the proof is shown large, accepting first explains the consequences, rejecting requires a reason (kept as the audit note and told to the parent).
- **Tests:** `tests/Feature/PaymentDeskTest.php` (18: partial then full, the notes, the reference check in and across branches, the confirmation data, verification and rejection with a reason, refund by an authorised person only, payments cannot be edited or deleted, cross-branch and permission refusals, audit), plus `StaffAssistedServiceTest` and `PaymentWorkflowTest`; browser `tests/e2e/payment_desk.py` (37), `assisted.py` and `payments.py`.
- **Not verified:** a real accountant or front desk; the am/om/ti wording (`rec` additions) is an AI draft.

## Optional parent accounts, the same way as staff

A parent account is never required: a family with none gets identical service at the school desk (`StudentDeskController`, Find Student > Open Student > Fees/Attendance/Report card). When a school does want to give a parent their own sign-in, it now works exactly like a staff account instead of the older email-invitation link.

- **Create (`Parents/Create.jsx`, three steps).** *Parent* (name, phone) -> *Children* (search the school's own students and link zero or more now, each with how they are related; skipping is fine, more can be linked later from the student's profile) -> *Account* (the phone typed again, a temporary password - "Make one for me" by default, or typed by the administrator - and an optional email). **Create Account** hands over a success screen built from the same `CredentialsPanel` as staff: name, role (Parent), the linked children, **Username/login: the phone number**, the temporary password once, the security warning, Copy and Print.
- **The directory (`Parents/Index.jsx`, "Find Parent").** Now also shows status, "Must choose a new password" and a paused-sign-in badge, with `Reset password`, `Disable account` and `Reactivate account` for anyone holding `manage-parents` - the same actions, wording and safeguards as the staff list (never a delete; disabling signs the person out everywhere and leaves every child's records exactly as they were).
- **First sign-in** is forced to a new password, exactly like staff (`must_change_password`, `EnsurePasswordIsChanged`), and afterwards the parent lands on the ordinary parent home - untouched by this phase.
- **Not touched, on purpose:** the enrolment wizard's own "link a new parent" step still makes an email-and-invitation-link account (a second, older way to reach the same `parent` role) - rebuilding it around phone numbers as well was judged out of scope for this phase and left as a follow-up if wanted. `ParentHome.jsx` (the parent's own dashboard) was not touched: it already shows exactly the five things asked for (child, today's attendance, fees, report card, notifications) and already has a staff-assisted equivalent for every one of them, so redesigning it would have been pure churn.
- **Tests:** `tests/Feature/ParentAccountTest.php` (28: creation, duplicate phone numbers, linking children and the relationship they need, branch scoping of who can be linked, first sign-in, reset, disable/reactivate, authorization, cross-school, no student login is ever created) and the browser suite `tests/e2e/parent_account.py` (31).
- **Not verified:** a real parent handling a phone-based account for the first time; the am/om/ti wording (additions to `parents`, `cred`, `staff`, `audit`) is an AI draft.

## Account lifecycle management (refinement)

Every account (staff or parent) already had the six actions this phase's brief asked for; this pass makes the status
always explicit and closes two real gaps.

- **Four unambiguous statuses**, on both the Staff and Parents lists: **Active**, **Switched off**, **Must choose a new
  password**, and now a distinct **Locked** badge (ten wrong tries) separate from the ordinary **Sign-in paused for
  now** cooldown - `LoginThrottle::status()` already told these apart (`locked` vs `waiting`), only the badge collapsed
  them into one wording before.
- **Last signed in.** New `users.last_login_at`, set only by `LoginRequest::authenticate()` on a real successful
  sign-in (`saveQuietly()` - no audit noise, excluded from the automatic trail like `password_changed_at`), shown on
  both lists ("3 days ago" / "Never signed in").
- **View**, as its own action. Anyone who can *look* at the staff or parent list (`canViewOrManage`, e.g. an auditor)
  now gets a **View** button opening the same details read-only - status, last sign-in, permissions or linked
  children - instead of a bare, actionless card. Editing, resetting, disabling and reactivating stay behind the
  stronger `manage-*` permission, exactly as before.
- **Reactivate with a new password**, alongside the existing plain *Reactivate account* ("if appropriate", per the
  brief - the administrator's judgement, not automatic): a second, clearly separate button that reactivates AND
  issues a fresh temporary password in one step, for when picking the account back up "as is" is not appropriate.
- **A real bug this phase's browser test found, not just a wording gap:** `User::booted()`'s `updating` hook cleared
  `must_change_password` whenever a password changed and the flag "wasn't set in the same save" - but it checked
  `isDirty()`, which is blind to an explicit `true -> true` (no value change, so not dirty). Reactivating an account
  that was disabled before its temporary password was ever used, with *Reactivate with a new password*, hit this
  exactly: the flag was already true, got explicitly re-asserted true, and was silently cleared anyway. The existing
  double-password-reset path had the identical latent bug. Fixed with a transient, non-persisted `User::$forceMustChangePassword`
  flag the four call sites set immediately before saving, so the hook can tell "explicitly kept true" apart from
  "not touched" without breaking the *other* direction (an ordinary password change, e.g. Forgot password, still
  clears the flag even when it was already true - both directions now have their own test).
- **Already true, verified rather than rebuilt:** disabling/reactivating already never deletes anything and preserves
  every kind of history (attendance, marks, payments, the person's own audit trail); "account created / disabled /
  reactivated / password reset / role or permission changed / phone changed" were all already written to the audit
  trail - `account disabled`/`account reactivated` write a generic `user.status_changed` entry automatically (the
  `Audited` model trait), which had no specific wording and fell back to an odd `"an account: status changed"`
  sentence; it now reads **"Status changed: {{noun}}"** like every other model's status changes, with the exact
  before/after values already shown in the diff beneath it (a one-line i18n fix, `audit.generic_status_changed`,
  that improves every model's status-change entries at once, not just accounts). No plaintext password has ever been
  written to the audit or security log; this phase adds a test that says so explicitly for every one of the six
  actions.
- **Tests:** `tests/Feature/AccountLifecycleTest.php` (33 - the full Active/Disabled/Must-change/Locked matrix and all
  six actions, for both staff and parent accounts, plus the `must_change_password` regression in both directions) and
  the browser suite `tests/e2e/account_lifecycle.py` (14).
- **Not verified:** a real administrator relying on the Locked/View/Reactivate-with-reset additions day to day; the
  am/om/ti wording (additions to `staff`, `audit`) is an AI draft.

## Announcements now actually notify their audience

Publishing an announcement did everything except tell anyone: it saved, it appeared on `/announcements` for whoever
was scoped to see it — but nobody's bell rang. This closes that gap, and bundles in the one thing every notification
in the system was still missing: something to tap through to.

- **`Announcement::notifyAudience()`** sends `AnnouncementPublished` to every account `recipients()` resolves (same
  audience/branch rules `scopeVisibleTo` already used for the list page), and is idempotent via a new `notified_at`
  timestamp — safe to call more than once, so a missed scheduled run is never skipped and a re-run never double-sends.
- **Published today** -> notified immediately, from `AnnouncementController::store()`. **Scheduled for later** -> not
  notified yet (`notified_at` stays null); a new `announcements:notify-due` Artisan command catches it once its
  `publish_on` day actually arrives.
  **Needs a Render Cron Job in production**, exactly like `backup:run`/`backup:verify`/`security:prune` — this app
  has no in-process scheduler loop (`php artisan serve` runs in the foreground only), so `routes/console.php`'s
  `Schedule::command('announcements:notify-due')->dailyAt('06:00')` is documentation/local-`schedule:work` only. A
  separate cron job pointed at `php artisan announcements:notify-due` still needs to be added on Render for this to
  actually fire daily.
- **Every notification now leads somewhere.** New `App\Support\NotificationPresenter` centralizes an `action_url` per
  notification type (by `class_basename`), reused by the bell dropdown, the full Notifications page, and the shared
  Inertia prop — one place to teach a new type where its "Open" link goes. Retrofitted onto all 9 types that now
  exist: `AnnouncementPublished` -> the announcements list; `StudentMarkedAbsent`/`AttendanceCorrected` -> that
  child's attendance; `InvoiceGenerated`/`PaymentRefunded` -> that invoice; `PaymentVerified`/`PaymentRejected` -> the
  invoice behind that payment; `ComplaintSubmitted`/`ComplaintUpdated` -> that complaint. All from IDs already stored
  in each notification's `data` — no new data had to be captured for the 8 pre-existing types.
- **Tests:** `tests/Feature/FamilyAndAnnouncementsTest.php` (29b — immediate notification on same-day publish, correct
  audience/branch targeting, a scheduled announcement withholding its notification until `announcements:notify-due`
  says its day has arrived, and that command's idempotency) and the browser suite `tests/e2e/forms.py` (an "Open"
  link from the parent's notification to `/announcements`).
- **Not verified:** a real Render Cron Job actually configured for `announcements:notify-due` (this phase only adds
  the command and documents the gap — see above); a real parent tapping through from a notification day to day.

## Inventory & stores -- Phase A: catalog, stores, and the stock ledger

The first of four planned phases for a full inventory/stores/asset/requisition module (a school-owned request, not
a redesign of anything existing). Phase A lays the foundation the rest build on: a consumable catalog, stores to
hold it, and a stock ledger that never loses history. Requisitions/approvals (Phase B), fixed assets (Phase C) and
stocktake (Phase D) are deliberately not part of this pass.

- **The ledger, not a mutable number.** Exactly like `Invoice` never caches a total and computes it live from its
  items and payments, stock is never just a number someone can overwrite: `stock_movements` is a permanent,
  append-only record of every opening balance, purchase receipt, issue, return, transfer, and adjustment (never
  edited or deleted -- a mistake is corrected with another movement, never by rewriting history). A `stock_balances`
  row per (item, store) holds the fast running total, updated **inside the same transaction as the movement, under a
  row lock** -- the same technique `NumberSequence` already uses for invoice numbers -- so two concurrent issues of
  the last few units cannot both succeed and push the count negative by a race. Negative stock is refused outright
  unless a school explicitly turns on `allow_negative_stock`.
- **Items are a school-wide catalog, like `FeeStructure`**; stores are branch-scoped like `PaymentAccount` (a null
  `branch_id` means shared by every branch). A branch admin manages only their own branch's stores; an owner chooses
  freely or leaves a store school-wide.
- **A transfer is two linked movements**, `transfer_out` at the source and `transfer_in` at the destination, so every
  row in the ledger still means exactly one store and one signed effect on it -- never a row with two store columns.
- **New permissions**, none auto-granted to any tier but owner: `inventory-view`, `inventory-manage`, `stock-receive`,
  `stock-issue`, `stock-transfer`, `stock-adjust`. A "storekeeper" is not a new role -- it is any existing account
  (typically a branch_admin or accountant, per the existing per-account permission model) with exactly the stock
  permissions it needs turned on and nothing else.
- **Focused dialogs, not a wizard.** Receiving, issuing, transferring and adjusting stock are each a single-screen
  dialog (the same "show the effect before saving" pattern as the invoice discount/waiver dialogs) launched right
  from the item list or the item's own page -- a wizard would have been overkill for what is, each time, one
  quantity and one reason.
- **Tests:** `tests/Feature/InventoryTest.php` (14 -- the ledger's arithmetic, negative-stock prevention and the
  configurable override, transfer's two linked movements, adjustment's difference-only recording, permission
  isolation per action, and branch scoping for both stores and movements) and the browser suite
  `tests/e2e/inventory.py`.
- **Deliberately not built yet (later phases, or out of scope for this pass):** requisitions and approvals, fixed
  assets, stocktake, CSV/print export for the stock reports (the Items and Movements list pages serve as the
  current-stock and movement-history views for now), a dedicated units-of-measure or supplier directory (both are
  plain free-text fields).
- **Not verified:** a real storekeeper or branch admin using this day to day; the am/om/ti wording (a new `inv`
  namespace, plus a few additions to `nav`/`section`/`dashboard`) is an AI draft.

## Inventory & stores -- Phase B: requisitions, approvals, issuing

The second of four planned phases (A catalog/ledger -> **B, this phase** -> C fixed assets -> D stocktake). A
department or teacher's request to draw stock from a store, reviewed, approved (in full or in part), and issued
against -- built entirely on Phase A's ledger (`App\Services\Stock::issue()`), never a second way to move stock.

- **Status flow simplified from the brief on purpose, both confirmed upfront:** "Submitted" and "Under Review" are one
  status (`submitted`) -- nothing happens between them that needs its own state. "Fully Issued" becomes `completed`
  in the same step (`Requisition::refreshIssueStatus()`) rather than needing a separate manual close -- there is
  nothing left to confirm once every line is fully issued.
- **Every issue is traceable to the requisition line it came from.** `stock_movements.related_requisition_item_id`
  (new column) links an `issue` movement back to the exact `requisition_items` row; a requisition's `quantity_issued`
  is the sum of those movements, never a separately-tracked number that could drift from the ledger.
- **Approval can reduce quantities, never invent them** -- `quantity_approved` per line defaults to what was asked
  but an approver can lower any line (never raise it above what was requested) before approving.
- **New permissions**, same rule as Phase A (nobody but owner by default): `requisition-create`, `requisition-approve`,
  `requisition-issue` -- added to the existing `inventory` permission group, not a new one, matching the brief's own
  grouping.
- **Notifications** (the three the brief's own notification-strategy discussion asked for): submitted -> every
  approver who can see that store (branch-scoped, exactly like every other inventory permission check); approved ->
  the requester AND everyone holding `stock-issue` in scope, so both "it's coming" and "prepare it" are covered in
  one notification each; rejected -> the requester, with the reason. Also added here (deferred from Phase A on
  purpose): **low-stock alerts**, fired from inside `Stock::applyLocked()` itself -- but only at the moment a
  decrease CROSSES the reorder level (`old > reorder >= new`), never repeated on every later issue while it stays
  low, per the brief's own "don't spam" rule.
- **A real, latent bug found by the browser test and fixed in BOTH phases:** a dialog's `useForm()` only captures its
  initial values on first mount. `Requisitions/Show.jsx`'s issue dialog is rendered unconditionally with `line`
  starting `null` (before any row is clicked) -- so `requisition_item_id` stayed `''` forever no matter which line
  was actually selected, failing validation on every attempt. The IDENTICAL bug already existed, undetected, in
  Phase A's `Inventory/Items/Index.jsx` receive/issue/transfer dialogs (`dialog?.item` starts `null` the same way) --
  Phase A's own browser test never caught it because it only ever used one item, so "always submits against
  whichever item was selected first" was indistinguishable from "working". Fixed in both places with
  `key={`prefix-${selectedThing?.id ?? 'none'}`}` on the dialog element, forcing React to mount a fresh instance (and
  a fresh `useForm`) on every different selection. See [[ui-verification-with-playwright]].
- **Tests:** `RequisitionTest.php` (9 -- draft-then-submit, immediate submit, partial approval, rejection with a
  required reason, partial-then-full issue completing automatically, per-action permission isolation, branch scoping
  for approval, draft-only deletion, and the low-stock alert firing from a requisition issue) and the browser suite
  `tests/e2e/requisitions.py` (15).
- **Deliberately not built** (Phases C/D, or out of scope for this pass): fixed assets, stocktake, a way to cancel a
  submitted requisition (only a never-submitted draft can be removed), CSV/print export.
- **Not verified:** a real requester/approver/storekeeper using this day to day; the am/om/ti wording (a new `req`
  namespace, plus a few additions to `nav`/`dashboard`/`notifications`) is an AI draft.

## Inventory & stores -- Phase C: fixed assets

The third of four planned phases (A catalog/ledger -> B requisitions -> **C, this phase** -> D stocktake). Computers,
furniture, vehicles -- durable, individually-tracked property, deliberately a completely separate model from
`InventoryItem` per the brief ("do not confuse items with assets"): a consumable is counted, an asset is the one
specific thing with its own tag and its own history of who had it and where it sat.

- **Same ledger-plus-snapshot shape as Phases A and B, generalized to people and locations instead of quantities.**
  `asset_events` is a permanent, append-only history (never edited or deleted, same `booted()` guard as
  `StockMovement`); `assets.status`/`assigned_to`/`current_store_id` are only ever a snapshot of where the most
  recent event left it. `App\Services\Assets` is the only code that ever changes those fields, mirroring
  `App\Services\Stock`'s role exactly.
- **Categories are shared with Phase A's, not a second table** -- `inventory_categories.kind='asset'` reuses the
  exact same simple named-grouping feature already built, matching the schema decision flagged (and not objected to)
  back in the Phase A report.
- **Ten event types** covering the brief's own list (assignment, reassignment, transfer, repair, return, retirement,
  disposal) plus marking something lost or damaged outright. A few rules the service enforces, not just the UI:
  assigning only works on an `available` asset (reassign instead of a second assign); transferring between stores
  only works on an unassigned one (an assigned asset moves with its holder, not independently); retiring/disposing
  are refused a second time (both are terminal, `disposed` most of all).
- **New permissions**, same rule as Phases A and B (nobody but owner by default): `asset-view`, `asset-manage` --
  added to the existing `inventory` group, matching the brief's own grouping, not a new one.
- **Notifications**, both explicitly asked for: assigned (and reassigned) -> the recipient; repair completed -> the
  assigned holder, fixed or damaged either way, so they are not left wondering.
- **A real regression this phase's own testing exposed and fixed:** adding permissions #34 and #35 shifted the
  staff wizard's total-permission-count text again (an unavoidable consequence of the SAME `admin.py` hardcoded-count
  issue Phase A already hit once) -- fixed the same way, by recomputing the real total and updating the test's
  wording to match, rather than leaving it stale until the next phase breaks it again the same way.
- **Tests:** `AssetTest.php` (12 -- the full assign/reassign/return/transfer/repair/lost/retire/dispose cycle,
  refusing invalid transitions, an asset event's immutability, permission isolation, and branch scoping for both the
  list and a single asset) and the browser suite `tests/e2e/assets.py`.
- **Deliberately not built** (Phase D, or out of scope for this pass): stocktake, file attachments on an asset (the
  `assets.attachments` column exists in the schema but nothing writes to it yet -- no upload UI was built this
  pass), a supplier directory (free-text, same as Phase A's purchase-receipt supplier field), CSV/print export.
- **Not verified:** a real asset custodian using this day to day; the am/om/ti wording (a new `asset` namespace, plus
  a few additions to `nav`/`dashboard`/`notifications`) is an AI draft.

## Inventory & stores -- Phase D: stocktake

The last of the four originally planned phases (A catalog/ledger -> B requisitions -> C fixed assets -> **D, this
phase**). Counting a store's physical stock against what the ledger says, and turning real differences into
authorized adjustments.

- **Reuses `App\Services\Stock::adjust()` directly** -- a stocktake is not a second way to change a balance, it is
  simply what decides the number `adjust()` is called with. "Draft" from the brief is skipped, the same kind of
  collapse Phase B made for "Submitted"/"Under Review": starting a count and it being "in progress" are the same
  moment.
- **The adjustment reconciles against the LIVE balance at completion, not the snapshot taken when counting
  started** -- if another movement happens to the same item mid-count (someone issues stock while a count is still
  open), the physical count is still applied correctly under `Stock::adjust()`'s own row lock, never silently
  overwritten by a stale number. Traced back to its cause via a new `stock_movements.related_stocktake_line_id`,
  the same pattern as Phase B's `related_requisition_item_id`.
- **No new permission** -- gated on the existing `stock-adjust` (a stocktake's entire point is to end in an
  adjustment, so whoever may adjust stock directly may also run one; a dedicated `stocktake-*` permission would have
  just duplicated it).
- **Completing is refused, in words, until every line has a count AND every line with a real difference has a
  reason** -- matches the brief's "require authorized adjustment, record reason" exactly; a line that matches needs
  no reason.
- **One in-progress stocktake per store at a time** -- starting a second one for a store already being counted is
  refused rather than allowed to silently create two competing counts.
- **Tests:** `StocktakeTest.php` (8 -- the snapshot on start, one-in-progress-per-store, completion's two gates,
  a matching count creating no adjustment, reconciling against a live balance that moved mid-count, movement
  traceability, and permission/branch scoping) and the browser suite `tests/e2e/stocktakes.py`.
- **Deliberately not built**: a dashboard tile (not in the brief's dashboard list for this module), CSV/print export
  of the differences (the stocktake's own page serves as that report).
- **Not verified:** a real storekeeper running a full physical count day to day; the am/om/ti wording (a new
  `stocktake` namespace, plus one addition to `nav`) is an AI draft.

**This closes the originally planned 4-phase inventory/stores/assets/requisitions module** (catalog+ledger,
requisitions+approvals+issuing, fixed assets, stocktake) -- see this document's Phase A section above for the full
architecture it was all built on.

## Procurement & Purchasing -- Phase 1: suppliers, purchase requests, approval

A second, separate module (own nav section, own permission group) sitting on top of the inventory module above --
requested once that module was complete, explicitly to integrate with it rather than duplicate it. Phased as P1
(suppliers + requests + approval, **this phase**) -> P2 (supplier quotations) -> P3 (purchase orders) -> P4 (goods
receipt + inventory update + vendor-bill reference + reports), confirmed with the user via AskUserQuestion first.

- **Reuses, never duplicates:** no "Contacts" system existed to reuse (checked before building `Supplier`);
  `NumberSequence` for `PR-`/`SUP-` numbers, exactly like every other document number in this app; the `Audited`
  trait for automatic audit coverage; branch scoping identical to every other phase's.
- **A line is either a catalog item or a free-text service** (`item_id` nullable on `purchase_request_items`) --
  the brief explicitly asks for both, and a school buying a service contract has nothing to link to `inventory_items`.
- **Estimated totals are never cached**, summed live from the items exactly like `Invoice` never caches its own
  total from its items.
- **"Configurable approval rules" implemented as a single amount threshold**, not a rule-builder: new
  `schools.purchase_approval_threshold` (nullable -- null means no two-tier distinction is active at all, every
  request just needs `purchase-approve`). Above it, `purchase-approve-large` is required instead -- the exact same
  shape as the existing `approve-large-discounts` vs. ordinary discount approval, just for purchasing. Exposed as an
  editable field in School Settings, not just a database column nobody can reach.
- **A requester can never approve their own request**, enforced server-side (`abort_if($pr->requester_id ===
  $user->id, 403)`), independent of whatever permissions they hold -- the brief's own security requirement, and
  worth stating explicitly since it is easy to miss when the same account happens to hold both create and approve
  permissions.
- **New permissions, own group** (`procurement`, not folded into `inventory`) -- `supplier-manage`,
  `purchase-request-create`, `purchase-approve`, `purchase-approve-large` -- nobody but owner by default, same rule
  as every phase so far.
- **A recurring, now-anticipated cost paid proactively this time:** adding permissions #36-39 shifted `admin.py`'s
  hardcoded total-permission-count text again (35->39) and its group count (7->8) -- fixed BEFORE running the
  regression this time (not after it broke), and the `perm.*` label translations for the 4 new permissions were
  added upfront too, instead of being caught by the Amharic check after the fact like Phases B and C's were.
- **Tests:** `ProcurementTest.php` (12 -- supplier creation and archiving, draft-then-submit with branch-scoped
  approver notification, a free-text service line, approval/rejection notifying the requester, the self-approval
  block, the threshold's two-tier gate in both directions, the threshold setting's own round-trip through School
  Settings, branch isolation, and draft-only deletion) and the browser suite `tests/e2e/procurement.py`.
- **Deliberately not built** (later Procurement phases, or out of scope for this pass): supplier quotations,
  purchase orders, goods receipt, the vendor-bill reference, attachments (the `purchase_requests.attachments` column
  exists in the schema but no upload UI was built this pass, same deferral as Phase C's `assets.attachments`).
- **Not verified:** a real requester/approver using this day to day; the am/om/ti wording (new `supplier`/`pr`
  namespaces, plus a few additions to `nav`/`section`/`settings`/`notifications`/`perm`) is an AI draft.

## Procurement & Purchasing -- Phase 2: supplier quotations

Comparing multiple suppliers' prices against one already-approved purchase request, then picking one -- the
natural next step once a request clears approval and someone has to actually go source it.

- **Reuses, never duplicates:** the request must already be `approved` before a quotation can be recorded against
  it (`abort_unless($pr->status === 'approved', 422)`) -- quotations don't re-implement approval, they consume its
  result; `NumberSequence` was NOT needed here (a quotation has no document number of its own in the brief, only a
  free-text supplier reference field); the same branch-scoping and `Audited` trait as every other phase.
- **No cached totals:** `Quotation::total()` sums its `QuotationItem` rows live, same rule as `Invoice` and
  `PurchaseRequest::estimatedTotal()`.
- **Mutual-exclusivity by design, not a constraint:** selecting one quotation deselects every other quotation on
  the same request inside one DB transaction (`Quotation::where('purchase_request_id', ...)->update(['selected'
  => false])` then select the chosen one) -- at most one quotation is ever `selected` per request, enforced in the
  one place it can change rather than with a DB unique-partial-index (SQLite/MySQL portability was simpler this way).
  **Not applied elsewhere:** unlike `StockMovement`/`AssetEvent`, `Quotation` is NOT append-only -- a quotation not
  yet selected can be edited away entirely (`destroy()`), since it is a working comparison, not a permanent ledger
  entry; the one immutability rule is narrower: a *selected* quotation cannot be removed (`abort_if($quotation
  ->selected, 422, ...)`) without picking a different one first, so the request is never left pointing at nothing.
- **New permission, existing group:** `quotation-manage` folded into the existing `procurement` group (not a new
  group -- comparing quotations is part of the same buying workflow as creating and approving the request, not a
  separate concern), owner-only by default like every permission so far.
- **The recurring maintenance cost, paid proactively again:** permission #40 shifted `admin.py`'s hardcoded
  "of 39" text to "of 40" (group count unchanged, since it went into an existing group) -- fixed before running any
  test, and `perm.quotation-manage`/`_hint` were added upfront too.
- **Two bugs self-caught before ever running a test:** the dialog's line-items form used a JS object keyed by each
  purchase-request-item's ID, but the controller validates `items.*.purchase_request_item_id` as a field *inside*
  each array element -- fixed with `useForm().transform()` converting the object to an array of `{
  purchase_request_item_id, ...line}` objects on submit; that same transform then meant server-side validation
  errors come back keyed by array index, not by item ID, so the per-line `<Field error={...}>` lookups had to move
  from `errors['items.' + item.id + '.field']` to `errors['items.' + index + '.field']`.
- **Tests:** `QuotationTest.php` (7 -- recording only works against an approved request, line/grand totals compute
  correctly, two quotations compare side by side and selecting one deselects the other in both directions, a
  selected quotation cannot be removed, only `quotation-manage` can record or select, branch isolation, and both
  `quotation.created`/`quotation.selected` land in the audit trail) plus an extension of `tests/e2e/procurement.py`
  covering the same flow end to end in a real browser.
- **Deliberately not built** (later Procurement phases): purchase orders, goods receipt, the vendor-bill reference.
- **Not verified:** a real buyer comparing real supplier quotes day to day; the am/om/ti wording for the new
  `quotation` namespace (24 keys) is an AI draft.

## Procurement & Purchasing -- Phase 3: purchase orders

Turning an approved purchase request into a formal order sent to one supplier -- optionally priced from a
selected quotation, but a quotation is never required (a small or sole-source purchase can skip Phase 2
entirely and price a purchase order by hand).

- **Reuses, never duplicates:** `NumberSequence` for `PO-` numbers, the same `Audited` trait, the same
  branch-scoping shape as every other document here; a purchase order line is always tied back to the
  specific `purchase_request_item` it fulfils, exactly like `quotation_items` -- no free-text description of
  its own, so a later Goods Receipt can match against the same original line for the three-way check the
  original brief asks for (request vs. order vs. receipt).
- **No cached totals:** `PurchaseOrder::total()` sums its items live, the same rule every document in this
  module follows.
- **Never over-ordered, even split across suppliers:** a request line's ordered quantity is checked LIVE
  against every one of its non-cancelled purchase orders (`PurchaseRequestItem::orderedQuantity()`, never a
  cached "quantity_ordered" column) before a new line can be added -- so the same line can be split across
  two different suppliers' orders, but never for more than was actually requested. Cancelling an order frees
  its quantity back up immediately, computed live the same way.
- **A narrower immutability rule than `StockMovement`/`AssetEvent`:** a purchase order is NOT append-only --
  a draft can be edited away entirely (deleted outright) since nothing has been committed to a supplier yet;
  once sent, it can only be cancelled (with a required reason), never silently deleted, mirroring the
  purchase request's own draft-only-deletion rule.
- **New permission, existing group:** `purchase-order-manage` folded into the existing `procurement` group,
  covering both viewing and managing (no separate view permission), the same shape `quotation-manage` used in
  Phase 2 -- except the index/show pages also honour the existing app-wide `canViewOrManage()` auditor
  bypass (`User::canViewOrManage()`), which `QuotationController` never needed since quotations have no
  standalone index page of their own.
- **The recurring maintenance cost, paid proactively again:** permission #41 shifted `admin.py`'s hardcoded
  "of 40" text to "of 41" -- fixed before running any test, and `perm.purchase-order-manage`/`_hint` were
  added upfront too.
- **A real, severe bug found by the browser test and fixed:** `PurchaseOrderDialog` filters the request's
  items down to only the ones still needing an order (`remaining_to_order > 0`) -- but `remaining_to_order`
  is `null` before approval and only becomes a live number afterward, and again changes every time an order
  is created or cancelled. The dialog is mounted unconditionally at the bottom of the purchase request's page
  (same pattern as `QuotationDialog`), so its `useForm()` captured the item set at FIRST MOUNT and never
  re-synced; the moment a request was approved elsewhere on the same page load, the dialog's own derived
  `grandTotal` calculation ran against the newly-eligible item using stale, empty form data and threw
  `Cannot read properties of undefined (reading 'unit_price')` -- crashing the ENTIRE purchase request page
  for every single approval, not just ones touching purchase orders. This is the same root cause documented
  in [[ui-verification-with-playwright]] and UX_GUIDELINES's own Phase B entry (a dialog's `useForm()` only
  captures initial prop values on first mount), but worse here because the crash didn't require the dialog to
  ever be opened. Fixed with a remount `key` on the dialog keyed to each item's own `remaining_to_order`
  value, forcing a fresh `useForm` whenever the eligible item set actually changes.
- **Tests:** `PurchaseOrderTest.php` (10 -- creation only works against an approved request, totals compute
  correctly with a real `PO-` number, a line can never be ordered past its requested quantity, a line CAN be
  split across two suppliers' orders but the second is refused once the first exhausts it, cancelling an
  order frees its quantity for a new one, sending locks an order against deletion while a draft can still be
  removed, only `purchase-order-manage` can create/send/cancel, branch isolation, tracing an order back to
  the quotation it was priced from, and creation/send/cancel all landing in the audit trail) plus an
  extension of `tests/e2e/procurement.py` covering the same flow end to end in a real browser, including the
  crash above (36 checks total for the whole Phase 1-3 suite).
- **Deliberately not built** (Phase 4): goods receipt, the vendor-bill reference, the three-way match itself
  (this phase only lays the traceable groundwork -- purchase order items pointing back to request items).
- **Not verified:** a real buyer sending a real order to a real supplier day to day; the am/om/ti wording for
  the new `purchase_order` namespace (32 keys) plus the `nav.purchase_orders` addition is an AI draft.

## Procurement & Purchasing -- Phase 4: goods receipt -- MODULE COMPLETE

Recording what a supplier actually delivered against a sent purchase order, closing the request -> order ->
receipt chain the brief calls the three-way check, and finally moving real inventory -- the one point in the
whole Procurement module that touches the Inventory module's own stock ledger.

- **Reuses, never duplicates -- this is the phase the brief's "integrate, don't duplicate" instruction is
  most about:** a goods receipt line's arrival is applied to stock with `App\Services\Stock::receive()`,
  the EXACT method Phase A's manual "receive stock" dialog already calls -- no second code path was written
  to touch `stock_balances`/`stock_movements`. `Stock::receive()` gained one new optional parameter,
  `$relatedGoodsReceiptItemId`, threaded through to a new nullable `stock_movements.related_goods_receipt_item_id`
  column, the same traceability pattern as Phase B's `related_requisition_item_id` and Phase D's
  `related_stocktake_line_id` -- a store's incoming stock can always be traced back to the purchase order (and
  the original request) that caused it. `NumberSequence` reused for `GR-` numbers; the same `Audited` trait,
  branch scoping, and store-visibility query (`InventoryStore` scoped by branch or org-wide) as every other
  phase.
- **A stronger immutability rule than Quotation/PurchaseOrder, matching StockMovement/AssetEvent instead:**
  once a goods receipt is recorded it has already moved real stock, so like the ledger models (never the
  pre-commitment documents above it) it can never be edited or removed -- a mistake is corrected with a
  further stock movement (a return), never by rewriting history.
- **Free-text/service lines are received but never touch stock:** a purchase order line whose original
  request line has no catalog `item_id` (a service, not a physical good) still gets a `GoodsReceiptItem` row
  for the three-way check, but `Stock::receive()` is simply never called for it -- there is nothing to put on
  a shelf.
- **A line can receive across more than one delivery, never past what was ordered:** `PurchaseOrderItem::
  receivedQuantity()` sums live across every receipt against it (never cached, same rule as `orderedQuantity()`
  from Phase 3) and a new receipt is rejected the moment it would exceed that line's own ordered quantity.
  `PurchaseOrder::refreshReceiptStatus()` recomputes the order's status after every receipt -- `sent` ->
  `partially_received` (some but not all lines fully received) -> `received` (every line fully received) --
  extending `PurchaseOrder::STATUSES`; cancelling a partially-received order is still allowed (the remainder is
  simply no longer expected), but a fully `received` order cannot be cancelled -- nothing is outstanding to cancel.
- **The three-way check itself, made visible rather than built as a separate matching screen:** the purchase
  order's own page shows "N of M received" on every line, straight from the same request/order/receipt chain
  already being tracked -- no new reconciliation UI was needed once the data was traceable end to end.
- **One new, deliberately narrow notification:** `PurchaseRequestFulfilled`, sent to the original requester
  the moment `PurchaseRequest::isFullyReceived()` turns true (every item's `receivedQuantity()` meets its
  requested quantity) -- closing the loop back to the person who asked for it, the first new notification type
  since Phase 1 (Phases 2 and 3 deliberately added none, since nothing in them needed to interrupt anyone).
- **New permission, existing group:** `goods-receipt-manage`, folded into `procurement` like every permission
  since Phase 2 -- a dedicated capability (not reuse of `stock-receive`) because recording a receipt against a
  specific formal purchase order is a narrower, document-driven action than Phase A's ad hoc "receive stock"
  dialog, even though it calls the identical service underneath.
- **The recurring maintenance cost, paid proactively one more time:** permission #42 shifted `admin.py`'s
  hardcoded "of 41" text to "of 42" -- fixed before running any test, `perm.goods-receipt-manage`/`_hint`
  added upfront.
- **A staleness risk defused before it could become a bug:** `GoodsReceiptDialog` filters order lines by
  `remaining_to_receive > 0`, the same shape of derived value that caused Phase 3's crash -- but here the
  eligible item SET can only ever shrink (a purchase order's own lines are fixed at creation, receiving only
  reduces what's left), so the exact crash from Phase 3 cannot happen. The subtler staleness it could still
  cause -- a reopened dialog prefilling a quantity from before an earlier receipt, over the true remaining
  amount -- was fixed proactively with the same remount `key` technique anyway, rather than waiting for a test
  to catch it.
- **Tests:** `GoodsReceiptTest.php` (10 -- receipts only work against a sent/partially-received order, a
  catalog line's receipt genuinely increases `Stock::balance()` and is traceable via
  `related_goods_receipt_item_id`, a free-text line is received without any stock movement, a line can never
  be received past its ordered quantity, partial-then-full receiving walks the order through both new
  statuses, only `goods-receipt-manage` can record one, branch isolation, a goods receipt can never be
  changed or removed, the fulfilled-request notification fires only once EVERYTHING has arrived (not on a
  partial receipt), and recording one is in the audit trail) plus a further extension of
  `tests/e2e/procurement.py` -- this last extension builds a real catalog item and store via direct SQL (the
  established e2e-fixture pattern) so the browser test proves stock genuinely moves, not just that the UI
  renders (45 checks total for the whole Phase 1-4 suite).
- **Not verified:** a real storekeeper recording a real delivery day to day; the am/om/ti wording for the new
  `goods_receipt` namespace (12 keys) plus 3 new `purchase_order.*` keys is an AI draft.
- **Whole-module final state:** all four Procurement phases (suppliers/requests/approval -> quotations ->
  purchase orders -> goods receipt) are complete. Full PHP suite: 780 passed + 2 pre-existing unrelated
  failures (`RegistrationTest`, no self-registration feature exists in this app). Deliberately out of scope
  for the whole module, left for a future pass if ever requested: purchase orders spanning more than one
  currency, a vendor-bill/accounts-payable reference (the brief's own "prepare integration points, don't
  build a fake accounting system" instruction -- the schema's FK chain from request through receipt is that
  integration point), and file attachments on any of the four documents.

## Accounting Engine -- Phase A: chart of accounts, journals, ledger, periods

A real double-entry engine, requested as its own large brief separate from the existing fee/invoice/payment
system, with an explicit non-negotiable invariant: for every POSTED journal entry, SUM(debit) = SUM(credit).
Phased as A (this phase: the core engine, usable standalone via a manual journal-entry screen) -> B (AR
integration: Invoice/Payment/Refund) -> C (AP integration: new SupplierBill/SupplierPayment models wired to
the existing Procurement module) -> D (Balance Sheet/P&L/aging reports + historical backfill +
reconciliation), confirmed with the user via AskUserQuestion before starting, after a full inspection-and-
design pass (existing financial architecture analysis, proposed data model, posting rules, integration map,
migration strategy, risks, test strategy) presented for review first, per the user's explicit instruction.

- **The existing fee/invoice/payment system already had NO cached totals anywhere** -- `Finance::figures()`
  computes gross/discount/net/collected/outstanding live, in integer cents, every time. This engine follows
  the exact same discipline: no `journal_entries.total` column exists at all; the ledger, trial balance and
  every report are live queries over posted `journal_entry_lines`. `Finance::toCents()` is reused directly as
  the ONE rounding authority for both systems, so the two can never silently drift apart.
- **One source of truth, stated explicitly:** `Invoice`/`Payment`/`PaymentRefund` remain authoritative for
  subsidiary detail (what a family owes, their payment history) -- nothing about the existing fee workflow
  changes. The general ledger becomes authoritative for aggregate financial position (trial balance, P&L,
  balance sheet). The two stay in sync purely by posting calls made at the exact moment each event already
  happens (Phase B), never a batch recompute.
- **Chart of accounts is per-school, never hardcoded:** `Account` (hierarchical via plain `parent_id`,
  `is_header` marks a pure grouping account that can never receive a posting, `is_system` protects the
  handful of accounts the posting engine relies on by ROLE). `App\Support\ChartOfAccountsTemplate` is a
  default set (matching the brief's own example numbering: 1000 Assets / 4000 Revenue / 5000 Expenses, plus
  1150 Undeposited Funds, 2100 Accounts Payable, 4910/4920 Discount/Waiver contra-revenue accounts) a school
  installs once via an explicit button and edits freely afterward -- idempotent, safe to click twice.
  `schools.default_{ar,ap,cash,revenue,expense,discount,waiver}_account_id` (seven new nullable FK columns,
  same shape as the school's existing settings columns) let the posting engine find "the" AR/AP/clearing
  account WITHOUT assuming a fixed code or name, since a school can rename or recode anything non-system.
- **Journals are configurable too:** `Journal` (cash/bank/sales/purchase/general/receivable/payable), seven
  installed by default alongside the chart of accounts, a school can add more.
- **The posting engine is one service, `App\Services\Ledger`**, called explicitly from controllers inside
  the same transactions that already exist -- never an Eloquent model observer, matching how `Stock::receive()`
  and `NumberSequence::next()` are already called explicitly elsewhere in this app. `Ledger::post()` is the
  one gate: balances in integer cents, rejects a zero-total entry, rejects a header or inactive account,
  rejects an account from a different branch than the entry, rejects posting into a closed period, then
  flips status to posted -- from that instant the entry is immutable (`JournalEntry::booted()` blocks any
  further update except the one internal reversal-linking transition, and blocks delete entirely), the exact
  "strictest pattern" already used by `PaymentRefund`/`AuditLog` (`NeverHardDeleted`-style guard PLUS a manual
  `updating` block), generalized. `JournalEntryLine` independently guards itself too (checked against its
  parent entry's own status) -- defense in depth, verified by a dedicated test that bypasses the service and
  edits a line directly.
- **Correction is reversal, never deletion or an edit:** `Ledger::reverse()` creates a brand new entry with
  every line's debit/credit swapped, dated TODAY (in whatever period is open now, never the original --
  possibly closed -- period), posted immediately, linked both ways
  (`reverses_entry_id`/`reversed_by_entry_id`). The original entry is untouched and stays posted forever --
  "reversed" is never a stored status, only derived from `reversed_by_entry_id !== null`, the same
  never-cache-a-derived-fact rule as everything else here.
- **A real design question surfaced by writing the e2e test, not assumed upfront:** should a CLOSED period
  block drafting an entry, or only posting one? The first implementation required an OPEN period even to
  create a draft, which meant a school could never even start drafting a correcting entry for a closed prior
  period without first reopening it -- backwards from how bookkeeping actually works (draft freely, post only
  once authorized). Fixed by splitting `School::openPeriodFor()` into `School::periodFor()` (any status --
  used by `Ledger::createDraft()`, since a draft moves no money yet and every entry still needs SOME period to
  belong to) with the OPEN check moved entirely into `Ledger::post()`, where it belongs.
- **A real UX gap also caught by the e2e test:** the journal entry Show page's Post/Reverse buttons used a
  plain `router.post()` with no error display at all -- a rejected post (e.g. into a closed period) redirected
  back completely silently, leaving the entry looking like nothing happened with no explanation. Fixed by
  adding `<FlashMessages errorKeys={[...]} />` to both the Show and Create pages, surfacing the exact
  validation message `Ledger::post()` already produces.
- **A third bug caught only by `admin.py`'s existing Amharic check, not the new accounting-specific
  tests:** the staff wizard's permission picker renders each permission GROUP's own heading from a
  `perm.group_{name}` key (`group_procurement`, `group_inventory`, ...) -- a key this session has added for
  every prior new permission group, but missed this time until the app-wide Amharic no-raw-keys check (which
  exercises every permission group's heading at once, not just accounting's own screens) caught the literal
  string `perm.group_accounting` leaking onto the page. A reminder that a NEW PERMISSION GROUP (as opposed to
  folding permissions into an existing one) needs its own `perm.group_*` key, on top of each permission's own
  `perm.<name>`/`_hint` pair.
- **New permission group, deliberately separate from `money`:** `accounting-view` / `accounting-manage` /
  `accounting-period-manage` / `accounting-period-reopen` (reopening is intentionally a stronger, separate
  permission from closing). None of these are required for the EXISTING fee/invoice/payment screens or for
  the automatic postings Phase B will add -- a receptionist verifying a payment will never need to hold any
  accounting permission at all, only the system posts on their behalf, attributed to them. Nobody but owner
  by default, same rule as every phase in this app.
- **Inventory valuation decision, made explicitly rather than assumed:** expense-on-purchase, not perpetual
  COGS costing. A school's consumable inventory is operating expense, not resold merchandise, and the
  existing stock ledger (`StockBalance`/`StockMovement`/`GoodsReceiptItem`) carries zero cost data today --
  building real valuation would mean retrofitting cost fields with no clear benefit for a school's use case.
  The existing quantity-only stock ledger keeps doing its own job, fully decoupled from the GL.
- **Payments will map to a specific cash/bank account via an "Undeposited Funds" clearing account** (Phase B):
  `Payment` currently has no `payment_account_id` at all -- a small additive nullable FK will let a school
  eventually record which account received the money, defaulting unmapped payments to a system clearing
  account rather than guessing, the standard accounting pattern.
- **Tests:** `AccountingEngineTest` (11 -- installing the default template creates the hierarchy and wires
  every system-account role, idempotent re-install, account CRUD and its permission/uniqueness/deletion
  guards -- system account, account with children, account with journal activity -- period open/overlap
  prevention, close/reopen needing separate permissions, accounting permissions fully separate from `money`),
  `LedgerPostingTest` (20 -- balanced posting, unbalanced/zero-total rejection, draft-editable-posted-immutable
  in both the service and the model layer, delete-blocked-reverse-instead, a correctly mirrored and linked
  reversal with the net ledger effect verified at zero, double-reversal rejection, header/inactive/wrong-branch
  account rejection, the draft-vs-closed-period distinction, and the core global invariant itself: every
  posted entry in the test database has equal debits and credits), `FinancialReportsTest` (5 -- the trial
  balance always balances, respects an as-of date, the ledger's running and opening balances are correct,
  and the view/manage permission split), plus `tests/e2e/accounting.py` (17 -- installing the chart of
  accounts and journals through the real UI, opening a period, building and posting a balanced manual entry,
  reversing it, the trial balance staying balanced in a real browser, closed-period rejection and reopening,
  and a teacher account having zero accounting access).
- **Deliberately not built** (Phase B/C/D): any automatic posting at all (this phase is the engine alone,
  proven with manual entries only), Balance Sheet/P&L/AR aging/AP aging/Cash summary reports, the
  SupplierBill/SupplierPayment models, and the historical backfill of existing invoices/payments/refunds into
  the ledger.
- **Not verified:** a real accountant using this day to day; the am/om/ti wording for the new `account`,
  `journal`, `period`, `journal_entry` and `reports` namespaces (122 keys) is an AI draft.

## Accounting Engine -- Phase B: AR integration (Invoice, Payment, Refund)

Wires the EXISTING fee/invoice/payment system into the Phase A posting engine. Nothing about the fee
workflow itself changes -- `Invoice`/`Payment`/`PaymentRefund` and `App\Services\Finance` remain the one
source of truth for what a family owes and paid. This phase only mirrors each event that already happens
into the ledger, in a new `App\Services\AccountingPostings` integration layer sitting between the existing
controllers and `App\Services\Ledger`.

- **The one rule that overrides everything else in this phase: the fee workflow must never break, with or
  without accounting adopted.** Every posting call is wrapped in its own `try/catch` in the controller,
  logging a warning on failure and letting the real business transaction (the invoice, the payment) commit
  regardless. `AccountingPostings` itself also returns `null` quietly (no exception at all) the moment a
  school hasn't configured the account it needs (`default_ar_account_id` etc never set because the chart of
  accounts was never installed) -- that is an entirely expected, benign state for any school that hasn't
  adopted this module yet, not an error. Any gap this creates between the fee system and the ledger (a
  missing period, chart not installed) is exactly what Phase D's reconciliation check exists to catch --
  never something that should interrupt a parent's payment being recorded.
- **Two small, additive, nullable schema changes -- nothing existing can break from them:**
  `fee_structures.revenue_account_id` / `optional_services.revenue_account_id` (which revenue account an
  invoice line credits; falls back to the school's `default_revenue_account_id` when unmapped) and
  `payments.gl_cash_account_id` (which ledger cash/bank account the money landed in; falls back to
  `default_cash_account_id`, the "Undeposited Funds" clearing account, when unmapped) -- distinct from the
  EXISTING `PaymentAccount` model, which stays exactly what it always was: display-only "pay into this
  account" metadata shown to a parent, never linked to the ledger.
- **Postings, matching the brief's own worked examples exactly:**
  - Invoice issued: Dr Accounts Receivable / Cr Revenue, one credit line per distinct revenue account across
    the invoice's own lines (a multi-line invoice with a fee AND an optional service posts two credit lines).
  - Discount changed: only the CHANGE since it was last recorded is ever posted (an invoice's
    `discount_amount` is mutable, so the caller passes the old and new values and `AccountingPostings` posts
    just the delta) -- never the new absolute value, which would double-count every edit.
  - Waiver applied/removed: Dr/Cr Fee Waivers for whatever was actually outstanding at that moment (the
    controller already computes this for its own approval-limit check and passes it straight through).
  - Payment verified: Dr Cash/Bank / Cr Accounts Receivable, the instant a payment counts as collected --
    never for a merely-pending portal submission, matching `Finance`'s own "only verified counts" rule
    exactly; a rejected payment posts nothing, ever.
  - Refund or reversal: Dr Accounts Receivable / Cr Cash-Bank -- both kinds post identically, mirroring how
    `Finance::cents()` already treats them identically when computing what's still collected.
- **Journal routing adds a touch of realism for free:** invoice issuance posts to the `SALES` journal,
  discount/waiver adjustments to `GEN`, and payments/refunds to `CASH` or `BANK` depending on the payment's
  own method -- falling back to `GEN` if a school deactivated one of the installed defaults.
- **Tests:** `AccountingIntegrationTest` (10) -- the two worked examples from the brief reproduced exactly
  (a 10,000 ETB invoice posting Dr AR/Cr Tuition Revenue; a 4,000 ETB payment posting Dr Cash/Cr AR leaving
  6,000 outstanding, with the AR control account's own balance checked to match the invoice's balance
  exactly at every step -- the reconciliation this whole design promises), a multi-line invoice crediting two
  different revenue accounts, discount deltas posting only the change across two edits, a waiver applied then
  removed reversing cleanly, a pending payment posting nothing while a rejected one never posts at all, two
  partial payments each posting their own entry while the AR balance still matches the combined outstanding
  total, a refund and a reversal both posting correctly, and -- the two most important tests in the whole
  phase -- a school with NO chart of accounts installed completing the entire invoice-then-payment-then-refund
  workflow exactly as before with zero journal entries created, and a school WITH a chart of accounts but no
  accounting period covering today still completing a payment successfully (posting simply cannot happen,
  silently, and nothing about the payment is affected). The full existing `InvoiceGenerationTest`,
  `PaymentWorkflowTest`, `PaymentDeskTest` and `FinanceTest` suites were re-run unchanged and still pass
  exactly as before, confirming zero behavior change to the existing fee workflow itself.
- **Deliberately not built this phase:** any new UI at all (every posting happens invisibly behind the
  existing fee/invoice/payment screens -- a "view in ledger" link from an Invoice/Payment page is left for
  Phase D's reporting/reconciliation pass), a screen for mapping `revenue_account_id` per fee structure or
  `gl_cash_account_id` per payment (both work correctly via the default-account fallback without one), and a
  dedicated e2e test (Phase B introduces no new UI to click through -- the PHP feature tests already exercise
  every posting through the real HTTP routes, controllers, validation and locking, which is where this
  phase's actual risk lives; the existing e2e suites already click through the unchanged invoice/payment
  screens).
- **Not verified:** a real school's historical data reconciling cleanly (that's Phase D's backfill); a real
  accountant checking that the automatically-posted entries look right day to day.

## Accounting Engine -- Phase C: AP integration (SupplierBill, SupplierPayment, Procurement)

Wires the existing Procurement module (purchase requests, purchase orders, goods receipts) into the same
posting engine, mirroring Phase B's shape exactly but for the payable side. Two new models,
`SupplierBill`/`SupplierPayment`/`SupplierBillItem`, sit alongside the existing `PurchaseOrder` chain rather
than replacing anything in it -- a bill is a new, separate document a school records when a supplier actually
invoices them, which may or may not trace back to a purchase order.

- **The same best-effort, non-blocking posting rule from Phase B governs this phase too:** every
  `AccountingPostings::postSupplierBillRecorded()` / `postSupplierPaymentRecorded()` call returns `null`
  quietly when the school hasn't configured `default_ap_account_id` (or an item's expense account), and every
  controller wraps its posting call in `try/catch` so a GL failure can never block a real bill or payment from
  being recorded. Both new permissions, `supplier-bill-manage` and `supplier-payment-manage`, were folded into
  the EXISTING `procurement` permission group (48 total permissions now, up from 46) rather than creating a
  new group -- unlike Phase A's standalone `accounting` group, this is squarely a procurement-side concern.
- **Three-way match:** a bill line optionally carries `purchase_order_item_id`, and its quantity is validated
  against `PurchaseOrderItem::receivedQuantity() - PurchaseOrderItem::billedQuantity()` (both live-computed,
  never cached) -- a school can never bill for more than was actually received and not yet billed. A bill with
  no purchase order behind it (a one-off expense) skips the check entirely; its lines are free-form.
- **Postings, mirroring the brief's worked examples:**
  - Bill recorded: Dr Expense (per item's `expense_account_id`, or the school's `default_expense_account_id`)
    / Cr Accounts Payable, one debit line per distinct expense account across the bill's own lines.
  - Payment recorded: Dr Accounts Payable / Cr Cash/Bank, via the same "Undeposited Funds" clearing-account
    fallback pattern as Phase B (`gl_cash_account_id` on the payment, falling back to
    `default_cash_account_id`) -- the payment's `method` only routes which JOURNAL it posts to (CASH vs BANK,
    cosmetic grouping), never which ACCOUNT, since no per-method account mapping exists.
- **`SupplierPayment` is immutable from the moment it's created -- "always born confirmed," stricter even than
  `Payment`:** its `booted()` blocks both `updating` and `deleting` unconditionally (no draft state to edit
  out of, unlike a `Payment` that can still be rejected before verification). Changing a recorded payment
  amount is never allowed; a correction means a new payment or a refund-style reversal, preserving the full
  audit trail the brief requires.
- **Expense-on-purchase, not perpetual COGS valuation** -- a deliberate design decision confirmed with the
  user via `AskUserQuestion` rather than assumed, because the existing Inventory module has zero cost data per
  item and a school doesn't resell inventory at a markup. A supplier bill posts its full amount to an expense
  account immediately; there is no inventory asset account, no COGS account, and no valuation layer to
  reconcile against stock movements.
- **Real bugs found and fixed before/during this phase's own test runs:**
  - `SupplierBillTest`'s payment-posting assertion initially checked the Bank account (`1200`) reasoning that
    `method: 'bank_transfer'` should route there -- wrong, since the method only picks the journal, not the
    account; fixed to assert against `1150` (Undeposited Funds), the account the code actually posts to.
  - A stray leftover JSX ternary in `SupplierBills/Create.jsx` (`label={t('journal_entry.debit') ? ... : ''}`,
    an artifact from editing) was caught by re-reading the file before running any test, and fixed.
  - `SupplierBills/Show.jsx` never actually rendered "Fully paid" anywhere -- only `Index.jsx` did -- caught
    while reviewing the e2e test's own planned assertions against the real component, before running it; fixed
    to show it conditionally when `bill.balance <= 0`.
  - The e2e test's own first assertion ("prefilled from the fully-received order") checked for the string
    "Whiteboard markers," which belonged to a different, unrelated free-text purchase request earlier in the
    same script -- the actual goods-receipt chain used a catalog item (`GR_ITEM_NAME`, "E2E GR Catalog Item").
    This was a bug in the TEST, not the app; caught by the first real e2e run failing on exactly that line,
    fixed by asserting against `GR_ITEM_NAME`, confirmed clean on re-run.
  - Also caught mid-session, unrelated to the feature itself: running `killport.ps1` against the live e2e
    session server while an e2e run was still in flight killed that run's own backend out from under it --
    not a product bug, but a reminder that the usability session server must never be touched while a test is
    mid-run.
- **Remount-key lesson applied proactively, not reactively:** `SupplierPaymentDialog`'s default `amount` field
  depends on `bill.balance`, which changes after every payment -- the same shape of bug Phase A/B's
  `SupplierPaymentDialog`-equivalents hit before. This time `key={`sp-${bill.balance}`}` was added on the
  dialog's render call up front, without needing a bug to force it.
- **Tests:** `SupplierBillTest` (12) -- three-way-match rejection and acceptance, a bill split across two
  separate partial billings of the same order line, a free-form bill with no purchase order, a bill correctly
  posting Dr Expense/Cr AP, a payment correctly posting Dr AP/Cr Cash (Undeposited Funds), a payment rejected
  for exceeding the bill's remaining balance, a school with no chart of accounts completing the entire
  bill-then-payment workflow unaffected with zero journal entries, permission isolation (holding only
  `supplier-bill-manage` vs. only `supplier-payment-manage`), branch isolation, payment immutability
  (`DeletionBlockedException` on any `update()` attempt), and the audit trail recording both
  `supplier_bill.created` and `supplier_payment.created`. `tests/e2e/procurement.py` gained a new Section 11
  (54 checks total in the file, up from the prior count) covering the full browser flow: opening a bill
  pre-filled from a fully-received order, the over-billing rejection, saving it, a second free-form bill with
  no order behind it, both bills listed, recording a full payment and seeing "Fully paid," the payment listed
  on the bill's own page, and a teacher being refused all supplier-bill access (403). The full PHP regression
  suite was re-run after all of Phase C's changes: 838 passed, 2 failed -- the same two pre-existing
  `RegistrationTest` failures present since before this engagement began, confirming zero new regressions.
  `tests/e2e/admin.py` was also re-run in full to confirm the permission-count bump (46→48) renders correctly
  everywhere it's shown ("20 of 48", "19 of 48") and the Amharic translations still pass: 168/168.
- **Deliberately not built this phase:** perpetual inventory/COGS valuation (see above -- confirmed as the
  wrong model for this school, not merely deferred); a screen for mapping `expense_account_id` per catalog
  item (works correctly via the default-expense-account fallback without one); supplier credit notes/bill
  reversals (a bill, once posted, has no documented correction path yet beyond never editing it -- left for
  Phase D or a later pass if a real need arises).
- **Not verified:** a real school's historical procurement data reconciling cleanly (Phase D's backfill); a
  real accountant checking the automatically-posted AP entries look right day to day; what happens when a
  goods receipt is itself corrected or reversed after a bill has already been raised against it (no such flow
  exists yet in Procurement, so it was out of scope here).

## Accounting Engine -- Phase D (FINAL): reports, historical backfill, reconciliation

Closes out the whole A→B→C→D double-entry accounting engine brief. Six new reports complete the set the
brief asked for (Trial Balance and General Ledger already existed from Phase A), plus the one piece every
prior phase deferred to this one: a command that catches a school's pre-existing invoices/payments/bills up
into the ledger, and a report that proves the catch-up (and everything since) actually reconciles.

- **The six new reports, each still a live query, never a cached total:**
  - **Income Statement**: revenue minus expense for a date RANGE, via a new `Account::movementBetween()`
    method -- deliberately separate from `Account::balance()`, which is cumulative since inception and
    exactly right for every other report here but wrong for a P&L. Discount/waiver accounts need no special
    casing: they share the `revenue` account type in the chart, so their contra (debit) postings net straight
    out of the revenue total automatically.
  - **Balance Sheet**: as-of-date balances for Asset/Liability/Equity accounts via the existing `balance()`.
    Since nothing in this engine ever posts a period-closing entry, net income earned to date is folded into
    Equity as its own computed "Net Income (to date)" line -- the only way Assets reliably equals Liabilities
    plus Equity without a closing-entry mechanism.
  - **Cash & Bank Summary**: defined as "every leaf asset account except the Accounts Receivable control
    account" rather than hardcoding account codes -- AR has its own Aging report, and any cash-like account a
    school adds later (a second bank account, a petty cash fund) is included automatically with no code
    change needed.
  - **AR/AP Aging**: outstanding invoice/supplier-bill balances bucketed into Current/1-30/31-60/61-90/90+
    days overdue. `Invoice` has no explicit due-date field, so `period_start` is the honest proxy; a
    `SupplierBill` uses its own `due_date`, falling back to `bill_date`. Balances are read straight from
    `Invoice::balance()`/`SupplierBill::balance()` (the fee/procurement systems' own truth), never
    recomputed from the ledger.
  - **Reconciliation**: the tool the whole engine's promise cashes out to -- the AR/AP control account
    balances the ledger carries are compared against the INDEPENDENTLY computed live totals the
    fee/procurement systems themselves say are outstanding. A mismatch is reported plainly with the exact
    diff, never papered over; safe to re-run any time, not just right after a backfill.
- **`accounting:backfill-ledger` (new Artisan command, Phase D's other half):** posts ledger entries for
  Invoices/Payments/PaymentRefunds/SupplierBills/SupplierPayments that existed before a school installed its
  chart of accounts (or before the engine existed at all).
  - **Idempotent by construction, not by a separate "already backfilled" flag:** a source record with ANY
    existing journal entry at all is treated as already fully live-tracked by Phase B/C's own hooks and
    skipped entirely -- never diffed for partial state. This works because an invoice only ever has zero
    entries (created before accounting existed) or a complete, correct set (created after, live-posted) --
    there is no partial state to reconcile in between.
  - **Posts with the SOURCE RECORD'S OWN historical date, never today's date** -- required a new optional
    `?string $entryDate = null` parameter threaded through all seven `AccountingPostings::post*()` methods,
    defaulting to `now()->toDateString()` so every existing Phase B/C caller is completely unaffected.
  - **Auto-creates the minimum number of non-overlapping "Historical Backfill" accounting periods** to cover
    date gaps where no period exists yet, and closes them again once that school's run succeeds. A date
    already covered by an existing CLOSED period is never force-opened or posted into -- that one record is
    reported as skipped instead, the same closed-period protection the live engine enforces everywhere else.
  - **Dry-run first, always:** `--dry-run` lists what would post without touching the database at all (no
    periods created, no entries posted).
- **Real bugs found this phase:**
  - A 500 error on every new report page the first time it was opened -- `Unable to locate file in Vite
    manifest`. The standard gotcha whenever a brand-new Inertia Page component is added: `npm run build` must
    run before the page can render at all, caught immediately by the first PHP feature test exercising the
    route (see [[ui-verification-with-playwright]] for the matching frontend-staleness gotcha).
  - Two invalid Spatie role names, `'viewer'` and `'accountant2'`, typed into freshly-written PHP tests --
    neither exists in `RolesAndPermissionsSeeder`, caught immediately by a `RoleDoesNotExist` exception on
    first run and fixed to the existing `'accountant'` role.
  - The e2e test's own two assertions expected the lowercase i18n source text for the aging buckets
    (`"1-30 days"`), but Tailwind's `uppercase` class means Playwright's `inner_text()` -- which reflects
    RENDERED text, not source text -- actually sees `"1-30 DAYS"`. Caught by the first real browser run,
    fixed by asserting the rendered-case text.
- **Tests:** `FinancialReportsTest` gained 12 new cases (one per report's correctness, a date-range exclusion
  for the Income Statement, the Balance Sheet's balancing identity, Cash Summary's AR exclusion with a
  genuinely nonzero AR balance, both Aging reports' bucket math, and a reconciliation mismatch deliberately
  engineered via an unrelated manual posting). `LedgerBackfillTest` (5, new file) covers the full happy path
  reconciling afterward, idempotency on a second run, a closed period correctly blocking one record while
  others still post, `--dry-run` touching nothing, and a supplier bill backfilling correctly too. Full
  regression suite: 850 passed, the same 2 pre-existing `RegistrationTest` failures, zero new regressions from
  adding the optional `$entryDate` parameter to all 7 `AccountingPostings` methods. `tests/e2e/accounting.py`
  extended with a new section covering all 6 report pages plus permission checks: 25/25 checks passing.
- **Deliberately not built:** a UI button to trigger the backfill command -- it is a one-time ops tool, exactly
  like `backup:run` has no UI trigger either; supplier credit notes / bill reversals (still Phase C's own
  deferred item); multi-currency; perpetual inventory/COGS valuation (confirmed wrong model for this school
  back in Phase C, not merely deferred).
- **Not verified:** a real accountant reviewing the backfilled historical entries against their own paper
  records; running the backfill against the actual production/demo database (only exercised against isolated
  test schools so far) -- the dry-run flag exists precisely so this can be checked safely before a live run.

**Whole engine, all four phases, final state:** Chart of Accounts/Journals/Ledger/Periods (Phase A) → AR
integration with Invoice/Payment/Refund (Phase B) → AP integration with the new SupplierBill/SupplierPayment
(Phase C) → reports, historical backfill, and reconciliation (Phase D, this one). The non-negotiable invariant
from the original brief -- debits always equal credits for every posted entry -- is enforced in exactly one
place, `Ledger::post()`, and has never been touched or special-cased by any later phase. No Phase E is
planned; the brief's full scope is implemented.

## HR Module -- Phase A: Employee Profiles, Departments & Job Titles

A brand-new, large initiative (separate from the UX-redesign work and from the Accounting Engine), phased
A (employee profiles, departments, job titles, this one) → B (employment contracts) → C (staff attendance) →
D (leave management) → E (performance reviews) → F (payroll foundation) → G (HR dashboard), confirmed with
the user via `AskUserQuestion` before writing any code -- same discipline as every other large module in this
project. The brief's own opening line is the whole phase's design constraint: **"Staff authentication/accounts
and HR employee records are related but are NOT the same thing."**

- **`Employee` is a new model, deliberately decoupled from `User`:** a nullable, unique `employees.user_id`
  means an employee can exist with no login account at all (most real HR records, in fact -- a school has
  people on payroll who never touch the system), and a `User` can exist with no `Employee` record (a
  system/API-only account, though in practice every staff account now gets one via the backfill command
  below). Nothing about creating, editing, or removing an `Employee` ever creates, changes, or deletes a
  `User`'s account, role, or permissions -- linking/unlinking only connects two already-independent records.
- **`Position` is the brief's "Job Title" -- a real entity, never confused with a Spatie system role.** The
  brief's own example is the whole point: "Senior Accountant" (a `Position`, what this person is called at
  the school) is completely independent of "Accountant" (the Spatie role, what they're allowed to do in the
  system) -- confirmed end-to-end with a dedicated test that gives someone the `accountant` system role, a
  "Senior Accountant" job title, and checks that changing one never touches the other.
- **`Department` and `Position` reuse the EXISTING `GuardsDeletion` trait as-is** -- the same one
  Grade/Section/Subject/Term already use, not a new deletion-guard mechanism. A department can't be removed
  while it has employees or positions; a job title can't be removed while an employee holds it; both refuse
  with a plain "cannot be deleted because it is still used by: ..." sentence rather than a raw FK error.
- **`hr:backfill-employees` (new Artisan command) mirrors `accounting:backfill-ledger`'s exact shape**,
  confirmed with the user as the right approach before building it: idempotent via
  `whereDoesntHave('employee')` (a staff `User` that already has an `Employee` record is left alone
  entirely), `--dry-run` to preview safely first, safely re-runnable as new staff accounts are created before
  someone gives them a full HR profile. It only copies what the account already knows (name, phone, email,
  branch) and leaves everything genuinely HR-specific blank for HR to fill in -- it never invents data it
  doesn't have, and `hire_date` falls back to the account's own `created_at` as the closest honest proxy. It
  reuses `StaffController::STAFF_ROLES` (owner/branch_admin/teacher/accountant/auditor) so parent and student
  accounts are never backfilled into employees.
- **A new `hr` permission group (the 10th), with only `employee-view`/`employee-manage` added this phase** --
  granted by default to owner and branch_admin (branch_admin's own default set grew from 20 to 22
  permissions). Contract/attendance/leave/performance/payroll permissions are deliberately NOT added yet,
  exactly the same discipline as Procurement Phase C only adding `supplier-bill-manage`/
  `supplier-payment-manage` once `SupplierBill` actually existed -- never speculative upfront permissions for
  features that aren't built yet.
- **Linking/unlinking an account is audited automatically, not manually** -- `Employee::auditAction()`
  matches on `user_id` being in the changed fields to pick `employee.account_linked` / `account_unlinked`,
  riding on the existing `Audited` trait's own `updated` hook with a full before/after diff. Manual
  `AuditLog::record()` calls were initially written for this in `EmployeeController::update()` and then
  removed as redundant once it became clear the trait's automatic hook already produces a more precise,
  better-named entry on its own -- a self-caught design correction, not a bug fix.
- **Four real bugs found and fixed, three in the new e2e test itself and one in the app:**
  - `get_by_role("button", ...)` was used for the "Change details" link on the employee profile page, which
    is rendered via `Button as={Link}` -- an anchor tag with an implicit `role="link"`, not `"button"`. Fixed
    to match the `get_by_role("link", ...)` pattern already established in `admin.py`'s own "Add a staff
    member" check.
  - `select_option(..., label=re.compile(...))` was used to pick an account from a dropdown -- Playwright's
    `select_option` requires a plain string for `label`, not a compiled regex. Fixed with
    `eval_on_selector_all` to resolve the matching option's `value` by partial text first.
  - A confirm-dialog text assertion assumed quotes around the department's name (`Remove "X"?`), copying the
    style of an unrelated dialog elsewhere in the app; this phase's own `department.remove_title`/
    `position.remove_title` translations were authored without quotes (`Remove X?`) -- both styles already
    coexist in this codebase, so the test was fixed to match the actual, equally valid translation.
  - **The one real app bug:** `Departments/Index.jsx` and `Positions/Index.jsx` rendered `<FlashMessages />`
    with no `errorKeys`, so a blocked deletion's `withErrors(['name' => ...])` response had nowhere to
    render -- the error vanished completely silently. The exact same class of gap Accounting Phase A hit with
    its Journal Entry Show/Create pages missing a generic error banner. Fixed with
    `errorKeys={['name']}`/`errorKeys={['title']}`.
- **Tests:** `HrEmployeeTest` (13) -- employee creation and auto-numbering (`EMP-{year}-NNNNNN` via the
  existing `NumberSequence`), linking an account at creation and the audit trail it produces, refusing to
  link one account to two employees, unlinking leaving the account's own permissions completely untouched,
  branch scoping (a branch admin sees only their own branch's employees, 403 on another branch's), permission
  gating, both deletion guards, the job-title-vs-system-role independence check, and three backfill-command
  tests (creates exactly owner+teacher+accountant -- never the parent -- idempotent on rerun, `--dry-run`
  creates nothing). Full regression suite: 863 passed, the same 2 pre-existing `RegistrationTest` failures,
  zero new regressions. `tests/e2e/hr.py` (new, 14 checks): adding a department and a job title under it,
  the employee wizard end to end, linking/unlinking an account, both deletion-guard outcomes, permission
  checks, Amharic. `admin.py` needed FIVE separate hardcoded-count fixes this time (not the usual two or
  three) -- "nine groups" → "ten", two places checking the branch_admin default count (20→22 permissions:
  this is a new permission ADDED to an EXISTING role's defaults, not just a new group, a combination earlier
  phases hadn't hit), and the two numbers that flow from it (18→20 after unticking two). Re-confirmed clean
  at 168/168 after the fixes.
- **Deliberately not built yet:** Phase B (Employment Contracts + expiry reminders), Phase C (Staff
  Attendance -- confirmed via `AskUserQuestion` that HR/admin records it for everyone, no self-service
  check-in), Phase D (Leave Management), Phase E (Performance Reviews), Phase F (Payroll Foundation --
  confirmed via `AskUserQuestion` that Ethiopian statutory tax-rate defaults will be researched and seeded as
  an editable starting point, clearly flagged "verify before relying on this," never silently assumed), Phase
  G (HR Dashboard aggregating all of the above). An HR top-level navigation section exists now with just
  Employees/Departments/Job Titles -- it grows as each later phase lands.
- **Not verified:** a real HR administrator using this with a real staff roster; the backfill command has
  only been run against isolated test schools and the demo/usability database, never a real production school.

## HR Module -- Phase B: Employment Contracts

Adds employment contracts to each `Employee` record from Phase A: a school keeps its full contract history
(a renewal is a new row, never an edit of the old one), with document attachments and automatic expiry
reminders.

- **`EmploymentContract` belongs to `Employee`, with a five-status lifecycle:** `draft` -> `active` ->
  `expiring` -> `expired`, with `terminated` reachable manually at any point as a final, deliberate decision
  the expiry command never overrides. A `draft` contract never auto-transitions on its own (it hasn't taken
  effect yet).
- **"Immutable once real," the same rule `PurchaseOrder`/`Quotation` already follow elsewhere in this app:**
  a still-`draft` contract can be removed outright; the moment it has ever been `active` it stays in the
  employee's history forever, enforced in `EmploymentContract::booted()`'s `deleting` hook throwing
  `DeletionBlockedException`. The UI reflects this honestly -- a non-draft contract's row never even shows a
  Remove button, rather than offering an action that will fail.
- **Contract documents are private, never on the public disk, served only through an authorized action --**
  reusing `SafeUpload::storePrivate()` (already supports PDF content-validation) and
  `SafeUploadRule(['pdf'])`, and copying `PaymentController::proof()`'s exact streaming pattern verbatim: an
  `abort_unless` permission+branch check, an existence check on the `local` disk, then
  `Storage::disk('local')->response()` with a server-chosen filename, `X-Content-Type-Options: nosniff`, a
  locked-down `Content-Security-Policy`, and `Cache-Control: private, no-store`. A dedicated research agent
  confirmed this existing pattern before any code was written, rather than inventing a second way to serve a
  sensitive upload.
- **`hr:check-contract-expiry` (new Artisan command) needs its own production cron job** -- the same
  disclosure already made for `announcements:notify-due`/`backup:run`/`security:prune`, since this app has no
  in-process scheduler loop; not yet configured, only disclosed here. It moves an `active` contract into
  `expiring` once its end date is within 30 days, notifying everyone holding `contract-manage` in the
  employee's own branch (plus org-wide holders) exactly ONCE per contract via an `expiry_notified` flag --
  the same "don't spam" rule the Inventory module's low-stock alert already established. A contract already
  past its end date moves silently to `expired` (bookkeeping, not a reminder -- the reminder already fired
  when it first entered `expiring`).
- **`salary_reference` is explicitly reference-only**, labelled and hinted as such in the UI -- it is never
  read by any calculation anywhere in this codebase. Real payroll math is Phase F's job entirely; this field
  exists only so a contract records what was agreed, for a human to read.
- **New `contract-view`/`contract-manage` permissions join the EXISTING `hr` group** (now 4 permissions, not
  2) and were added to `branch_admin`'s default set too (24 permissions now, up from 22) -- a deliberate
  continuation of Phase A's own choice to give branch admins HR capability by default.
- **Two self-caught seeder gaps this phase, both before or via the test suite, never shipped unnoticed:**
  the new permissions were added to `RolesAndPermissionsSeeder::PERMISSIONS` but initially forgotten from
  `DEFAULT_PERMISSIONS['branch_admin']` (caught before running any test, costing one round of `admin.py`
  count updates), and then separately forgotten from `PERMISSION_GROUPS['hr']` entirely (caught by
  `AdminStaffWorkflowTest`'s own "every permission is in exactly one group" pinning assertion failing on a
  full regression run). **The lesson, worth stating plainly: adding a permission touches THREE places in the
  seeder** -- the flat `PERMISSIONS` list, its `PERMISSION_GROUPS` entry, and `DEFAULT_PERMISSIONS` for
  whichever roles should hold it by default -- missing any one of the three is a real, test-catchable bug,
  not a style nitpick.
- **One e2e-test-only bug, not an app bug:** a document-URL check concatenated `BASE` onto a URL that
  `route()` had already rendered as fully absolute (scheme + host included), producing an invalid
  double-prefixed URL. Fixed by using the rendered `href` directly -- `route()` output and hand-typed
  relative paths are not interchangeable in this test suite's helpers.
- **Tests:** `EmploymentContractTest` (8) -- adding a contract with a document and viewing it, the
  draft-vs-active deletion rule, branch scoping, permission gating, the document being refused to someone
  outside the employee's branch, and all three transition behaviors of `hr:check-contract-expiry` (moves to
  expiring and notifies exactly once even across two command runs, moves an overdue contract to expired, a
  draft never auto-transitions even past its own end date). Full regression suite: 871 passed, the same 2
  pre-existing `RegistrationTest` failures, zero new regressions once both seeder gaps were fixed. `admin.py`
  needed count updates in TWO separate rounds this phase (52/24/23/22 total permissions/branch_admin
  defaults/first-untick/second-untick) -- re-confirmed clean at 168/168 after both. `tests/e2e/hr.py` extended
  with a new Employment Contracts section: 22/22 checks passing in the whole file.
- **Deliberately not built yet:** Phase C (Staff Attendance), Phase D (Leave Management), Phase E
  (Performance Reviews), Phase F (Payroll Foundation), Phase G (HR Dashboard) -- unchanged from Phase A's own
  list, all still confirmed via the same upfront `AskUserQuestion` decisions.
- **Not verified:** a real HR administrator uploading and later needing to retrieve a real signed contract
  PDF; the expiry command has only been exercised with `Artisan::call()` in tests, never actually scheduled
  to run daily anywhere (that's the disclosed, not-yet-configured cron job above).

## HR Module -- Phase C: Staff Attendance

Daily attendance for school employees, deliberately separate from the existing student `attendance_records`
table. **HR/admin-recorded for everyone, confirmed via `AskUserQuestion` before any code was written** -- no
self-service check-in in this phase, unlike a kiosk or an employee's own dashboard.

- **Two tables, not one:** `staff_attendances` holds the single accepted record for a (employee, date) pair;
  `staff_attendance_corrections` holds requests to CHANGE an already-recorded day, each one pending, approved,
  or rejected. The first entry for a day is a direct write (no approval needed -- nothing to contest yet);
  changing an already-recorded day always goes through a correction request instead of a plain update.
- **A REAL two-person approval workflow, where student attendance's own correction flow (a plain re-save, no
  approval gate at all) deliberately does not have one.** The brief asked for "manual correction" AND
  "correction approval" as two distinct capabilities, and staff attendance is more sensitive than student
  attendance -- it will feed overtime/payroll calculations in a later phase -- so this phase earns the extra
  control. The exact "a requester can never approve their own request" rule Procurement already established
  for purchase-request approval is reused here verbatim: `abort_if($correction->requested_by === $user->id, 403, ...)`
  in both `approveCorrection()` and `rejectCorrection()`, regardless of what permissions the requester holds.
  Only one correction may be pending per attendance record at a time.
- **New `staff-attendance-view`/`staff-attendance-manage` permissions join the EXISTING `hr` group** (6
  permissions in it now) and were added to `branch_admin`'s defaults (26 total now, up from 24). **This time,
  all three seeder places -- `PERMISSIONS`, `PERMISSION_GROUPS['hr']`, and `DEFAULT_PERMISSIONS['branch_admin']`
  -- were updated proactively in one pass, before writing or running a single test**, directly applying the
  lesson Phase B had to learn the hard way. The full regression suite and `admin.py`'s permission-count
  assertions both passed clean on the very first run, zero rework needed -- a clean contrast to Phase B's
  two-round scramble.
- **Two real bugs found this phase, both through live debugging rather than guesswork:**
  - **The SQLite date-storage/`whereDate` gotcha.** `StaffAttendanceController` used a plain
    `where('date', $string)` to filter the daily roster and to check for an existing record -- but Laravel's
    `'date'` cast still stores the underlying attribute using the connection's full datetime format
    (`'Y-m-d H:i:s'`) even on SQLite, confirmed directly via `php artisan tinker` (`$model->date = '2026-02-01'`
    stores as `'2026-02-01 00:00:00'`). A plain string-equality `where()` against that column silently never
    matched. This is the EXACT class of gotcha this project's own memory had already documented for other
    modules (student attendance, invoice generation) -- it should have been checked proactively from memory
    and wasn't. Both call sites now use `whereDate('date', ...)`. Its side effect was worse than it looked:
    it was **masking a test that passed for the wrong reason** -- with the bug in place, a second recording
    attempt for an already-recorded day wasn't being skipped at all; it attempted a real duplicate `INSERT`,
    which failed on the table's own `UNIQUE(employee_id, date)` constraint, got converted to an uncaught 500
    response the test never checked, and coincidentally left the database in the exact same final state
    (one row, status unchanged) that correct skip-logic would have produced. The test has been strengthened
    with explicit `assertSessionHasNoErrors()`/`assertRedirect()` so a regression can never hide behind a
    silently-ignored 500 again.
  - **A missing `use ($user)` in a closure.** `StaffAttendanceController::index()`'s roster-building
    `$employees->map(function (Employee $e) use ($attendances) {...})` referenced `$user` inside the closure
    body without capturing it, throwing "Undefined variable $user" the instant any roster row had a pending
    correction attached (the `can_review` comparison needs it). Found by reading `storage/logs/laravel.log`
    directly after an e2e run got stuck waiting on a correction dialog that could never close, because the
    page reload it depended on was silently failing with a 500 server-side. Fixed, and closed with a brand
    new regression test ("the roster page still loads once a pending correction exists") since none of the
    original seven tests had actually exercised `index()` with a pending correction present.
- **Tests:** `StaffAttendanceTest` (8, up from 7 after the regression-coverage addition) -- recording for
  unmarked employees, the skip-not-overwrite rule (now genuinely verified, not accidentally passing), the
  full correction approve/reject cycle with the self-review ban, the one-pending-at-a-time guard, the roster
  page surviving a pending correction, branch scoping, and permission gating. Full regression suite: 879
  passed, the same 2 pre-existing `RegistrationTest` failures, zero new regressions. `tests/e2e/hr_attendance.py`
  (new file, 10 checks): marking present with check-in/check-out, requesting and approving a correction as a
  genuinely different reviewer (`principal@demo.test`), the requester being refused review of their own
  request, and a teacher refused everywhere. `admin.py` re-confirmed at 168/168, `hr.py` unaffected at 22/22.
- **Deliberately not built yet:** Phase D (Leave Management), Phase E (Performance Reviews), Phase F (Payroll
  Foundation), Phase G (HR Dashboard) -- unchanged from Phase A/B's own list.
- **Not verified:** a real HR administrator running a full day's roster for a real staff count; how the
  correction-approval workflow holds up with more than a handful of pending requests at once (no sorting,
  filtering, or pagination on the corrections list yet).

## HR Module -- Phase D: Leave Management

Leave requests against configurable per-school leave types, with live-computed balances. **Self-service for
anyone with a linked login account, HR (`leave-approve`) can also submit on behalf of anyone in scope --
confirmed via `AskUserQuestion` before any code was written**, matching the brief's own example ("Teacher
requests: 3 days annual leave") rather than Phase C's HR-only attendance model.

- **Three new tables.** `leave_types` (a school's own categories -- Annual, Sick, Maternity, Unpaid, whatever
  they want -- each optionally carrying a default annual day count and an optional "requires a supporting
  document" flag). `leave_allocations` (how many days an employee gets of a given type in a given year,
  unique per employee/type/year). `leave_requests` (`draft` -> `submitted` -> `approved`/`rejected`, immutable
  once submitted via the same `booted()` `deleting`-hook pattern `EmploymentContract`/`PurchaseOrder` already
  use).
- **Balances are always live-computed, never cached**, continuing this project's standing rule from the
  Accounting Engine onward: `LeaveAllocation::usedDays()` sums `LeaveRequest::days_count` for approved
  requests in that year on every call; `balance()` subtracts it from `allocated_days`. Approving or rejecting
  a request never writes to a separate running total anywhere -- there isn't one to get out of sync.
- **A single `canActFor()` rule cleanly implements the self-service/HR-on-behalf split**, without a third
  permission: `$user->can('leave-approve')` and in branch scope, OR `$user->can('leave-request')` and
  `$employee->user_id === $user->id`. The exact same rule, read the other way, also decides who can edit or
  delete a still-draft request.
- **The self-review ban, strengthened.** Procurement's purchase-request-approval rule ("a requester can never
  approve their own request") is reused verbatim for the third time in this project (Procurement, Staff
  Attendance corrections, now Leave) -- `abort_if($leaveRequest->requested_by === $user->id, 403, ...)`. This
  phase adds a second, independent half the brief implied but the pattern hadn't needed before: **you can
  never approve leave that is literally for *yourself*, regardless of who clicked submit on your behalf** --
  `abort_if($leaveRequest->employee->user_id === $user->id, 403, ...)`. An HR admin who is also a listed
  employee cannot rubber-stamp their own time off just because a colleague happened to type it in for them.
- **An overlap guard and a per-type document requirement, both enforced only at the moment of submission** --
  a draft can sit in any state while it's being drafted; `assertSubmittable()` only runs when the `action` is
  `submit`, checking the leave type's `requires_document` flag against whichever document is actually on
  file (freshly uploaded *or* already attached from an earlier save) and refusing two submitted/approved
  requests for the same employee whose date ranges intersect.
- **A real bug caught before any test ran, then a second, more serious one caught *by* the tests: both fixed
  in this phase, not deferred.**
  - **The validation-message dead end.** The first draft of `assertSubmittable()` used `abort(422, "plain text
    message")` for both the document-required and overlap checks. Laravel renders a plain abort like that as
    an opaque error page, not a Laravel validation response -- so Inertia's `onSuccess`/`onError` machinery
    never saw it as a validation failure, the dialog's `processing` flag never reliably resolved, and the user
    was left looking at a form stuck on "Working…" with no visible explanation anywhere on the page. Caught by
    deliberately probing the live usability session in a browser (not just the PHP test, which only checks
    the HTTP status code and would have stayed green either way) and watching what the dialog actually did.
    Fixed by switching both checks to `ValidationException::withMessages([...])`, Laravel's standard
    field-scoped validation path -- the same `errors.*` prop every `Field` component in this app already knows
    how to render inline, in plain words, right next to the offending field.
  - **PHP's own multipart/PUT limitation, hit for the first time in this project.** `LeaveRequestDialog`
    always builds its request as `FormData` (`forceFormData: true`, since a document may or may not be
    attached), and its editing path called Inertia's `put()` directly. PHP never populates request input from
    a `multipart/form-data` body on a literal HTTP PUT -- only on POST -- so every single edit-and-resave
    arrived at the server with an empty body and failed validation on *every* field, including `action`
    itself, while the create path (plain `post()`) worked perfectly. This is a documented Inertia.js gotcha
    with an official fix: spoof the method through a POST instead -- `transform((d) => ({ ...d, _method: 'put'
    }))` then `post()` -- which was confirmed field-by-field via the raw outgoing request body before and after
    the fix. **`ContractDialog` (Phase B) has the identical latent construction** -- its edit path calls `put()`
    without `forceFormData`, so a plain edit (no new document) still works, but attaching a *new* document
    while editing an existing contract would hit this exact same silent failure. Flagged to the user as a
    pre-existing issue outside this phase's scope rather than patched without being asked.
  - **A second, smaller correctness gap surfaced by the same investigation:** the objects handed to
    `LeaveRequestDialog` for editing (from both `Mine.jsx` and `requestsFor()`) carried a `leave_type`
    *name* but no `leave_type_id`, so every edit silently re-defaulted to whichever leave type sorts first
    alphabetically rather than preserving the one actually on the request. Fixed by adding `leave_type_id` to
    `requestsFor()`'s mapped response (the HR-side mapping in `EmployeeController::show()` doesn't need the
    same fix, since that view only offers Remove, never Change, for a leave request).
  - **An access-control gap found while writing the e2e permission checks, not anticipated up front:** the
    pending-approval queue and the document-serving endpoint both originally used the same broad `canView()`
    check as the leave-types list -- `leave-view` OR `leave-approve` OR **`leave-request`** OR auditor. Unlike
    the leave-types list (just category names, harmless for a self-service holder to see), the pending queue
    shows *everyone's* submitted leave reasons and dates, and the document endpoint serves *anyone's*
    uploaded file by ID. A teacher holding only self-service `leave-request` could browse every colleague's
    pending leave and open any employee's uploaded document in their branch just by guessing the ID. Fixed by
    narrowing `canView()` to `leave-view`/`leave-approve`/auditor for both endpoints, with one explicit
    carve-out on `document()` so an employee can still open their own uploaded file:
    `$this->canView($user) || $leaveRequest->employee->user_id === $user->id`.
- **Permissions:** `leave-view`, `leave-request`, `leave-approve` join the `hr` group (now 9 permissions).
  `branch_admin` gets all three by default (29 permissions total, up from 26); `teacher` and `accountant` each
  additionally get `leave-request` only, since leave is self-service for everyone, not just HR-adjacent roles.
  The "three seeder places" lesson held with zero rework for the third phase running -- verified via a `php -r`
  script (57 total permissions, every one in exactly one group, 29/4/5 defaults for branch_admin/teacher/
  accountant) before any test was run. `tests/e2e/admin.py`'s eleven hardcoded permission-count assertions
  were updated proactively in the same pass.
- **Tests:** `LeaveManagementTest` (13) -- self-service submit, HR-on-behalf submit, the authorization split
  refusing a self-service holder from acting for someone else, the document-required-for-type and overlap
  rules (including the fixed `ValidationException` surfacing as field errors), draft deletion vs. immutability
  once submitted, live balance computation on approve, the self-review ban's *both* independent halves (and a
  third, genuinely uninvolved reviewer succeeding), rejection requiring and recording a reason without
  touching the balance, leave-type deletion blocked while in use, permission gating, and the pending-queue/
  document access-control fix. Full regression suite: 892 passed, the same 2 pre-existing `RegistrationTest`
  failures, zero new regressions. `tests/e2e/hr_leave.py` (new file, 37 checks): leave-type configuration,
  HR-on-behalf submission with the document-required dead-end fix visibly confirmed in the browser, the full
  self-service draft/edit/submit cycle (the PUT-spoofing fix exercised directly), overlap refusal, approve and
  reject through the proper `RejectLeaveDialog` modal (never `window.prompt`), both halves of the self-review
  ban plus a genuinely uninvolved third reviewer, leave-type removal blocked-while-in-use vs. removed-cleanly,
  and the narrowed permission checks including the own-document carve-out. `hr.py` (22/22) and
  `hr_attendance.py` (10/10) re-confirmed unaffected; `admin.py` re-confirmed at 168/168.
- **Deliberately not built yet:** Phase E (Performance Reviews), Phase F (Payroll Foundation), Phase G (HR
  Dashboard).
- **Not verified:** a real HR administrator managing leave for a full staff roster across several concurrent
  leave types; how the pending queue reads once dozens of requests accumulate (no sorting, filtering, or
  pagination yet, same gap Phase C's corrections list has); `ContractDialog`'s latent edit+new-document PUT
  bug (flagged, not fixed -- out of this phase's scope).

## HR Module -- Phase E: Performance Reviews

One overall rating (1-5, each with a plain-words label: Needs improvement / Developing / Meets expectations
/ Exceeds expectations / Outstanding) plus free-text comments, per employee per named review cycle (e.g.
"Mid-Year 2026"). **Confirmed via `AskUserQuestion` before any code was written: an employee can view their
OWN finalized review, read-only, but never authors one themselves** -- there is no self-service "write your
own" permission at all, only `performance-manage` for whoever conducts reviews, a deliberately simpler shape
than Leave's self-service model since the brief and the user's own choice both pointed the same way.

- **Two new tables.** `performance_review_cycles` (a school's own named periods, just the grouping/labelling
  for a batch of reviews -- reuses `GuardsDeletion`, same as `LeaveType`). `performance_reviews` (one row per
  employee per cycle, unique on that pair, `draft -> finalized` via the same "immutable once real" `booted()`
  pattern `LeaveRequest`/`EmploymentContract` already use -- a finalized review can never be edited or
  removed).
- **A reviewer can never write or finalize their own review** -- the self-review-ban spirit already applied
  to Procurement/Staff Attendance/Leave, but STRICTER here: those earlier cases only block the *review/
  approval* step; this phase blocks AUTHORSHIP entirely (`assertCanManageFor()`'s
  `abort_if($employee->user_id === $user->id, 403, ...)` on both `store()` and `update()`), since an employee
  never authors their own review at all, by design. **The "Add review" button is never even shown on your own
  employee profile**, rather than shown and left to fail silently -- the same principle Leave's
  "own-request-note" already applies to its pending queue, carried one step further: here there's no
  DOM-visible explanation needed, because there is nothing to explain once the dead-end action simply doesn't
  exist on the page.
- **New `leave-view`/`leave-request`/`leave-approve`-style permissions, minus the self-service one:**
  `performance-view`/`performance-manage` join the EXISTING `hr` group (11 permissions now); `branch_admin`
  gets both by default (31 total now, up from 29); `teacher`/`accountant` get NEITHER, unlike Leave, since
  there is no self-service authoring permission for this phase to grant them. The "three seeder places"
  lesson held with zero rework for the fourth phase running, verified via a `php -r` script before any test
  ran (59 total permissions, every one in exactly one group, 31/4/5 defaults for
  branch_admin/teacher/accountant). `tests/e2e/admin.py`'s fourteen hardcoded permission-count lines were
  updated proactively in the same pass.
- **Three real bugs found and fixed before this phase was called done, none of them hypothetical:**
  - **A pre-existing Phase D regression, found while wiring this phase's OWN notification.**
    `NotificationPresenter` routed `LeaveRequestReviewed` to `employees.show` -- but that notification's
    recipient is always the self-service employee the leave is FOR, who very often holds no `employee-view`
    at all (a plain teacher/accountant), so clicking "your leave was approved" 403'd for exactly the person it
    was meant to reach. Never caught in Phase D because no test ever exercised the notification's own
    `action_url`, only direct route access. Fixed for both notifications at once: `LeaveRequestReviewed` now
    routes to `leave-requests.mine`, and this phase's new `PerformanceReviewFinalized` was written correctly
    from the start, routing to `performance-reviews.mine`.
  - **The exact `abort(422, "plain text")` mistake from Phase D, almost repeated.** The first draft of the
    duplicate-review guard (refusing a second review for the same employee+cycle) used a bare `abort_if(...,
    422, "text")`, which -- as Phase D's own documentation above explains -- Laravel does not render as a
    scoped validation response, so Inertia never shows it inline and the dialog is left looking like nothing
    happened. Caught by re-reading Phase D's own lesson before trusting a first draft, not by waiting for a
    test to fail: fixed to `ValidationException::withMessages(['cycle_id' => ...])` before ever running the
    PHP test, let alone the browser.
  - **The silent-403 variant of the same underlying problem, found by actually driving the self-review-ban
    through the browser rather than trusting the PHP test's 403 assertion alone.** Before the "Add review"
    button was hidden (see above), clicking it on your own profile and submitting produced no visible error
    anywhere -- not stuck on "Working…" like Phase D's bug, just silently uninformative, the dialog simply
    staying open with the same plain-words choices still showing. Confirmed via a direct browser probe
    (network response 403, page body unchanged) before deciding hiding the action was the right fix rather
    than trying to render an error message for an action nobody should see in the first place.
- **Tests:** `PerformanceReviewTest` (12) -- the draft-to-finalize workflow, finalizing notifying the
  employee, the self-review ban (including the hidden-button UI check at the controller-prop level), draft
  edit/remove vs. finalized immutability, the one-review-per-employee-per-cycle guard surfacing as a clean
  validation error, the employee seeing only their finalized review (never a draft), permission gating,
  cycle deletion blocked while in use, branch scoping, rating range validation, and the fixed notification
  link. `LeaveManagementTest` gained one regression test for the `NotificationPresenter` fix. Full regression
  suite: 904 passed, the same 2 pre-existing `RegistrationTest` failures, zero new regressions.
  `tests/e2e/hr_performance.py` (new file, 18 checks): cycle setup, the full write/edit/finalize cycle, the
  duplicate-review guard's inline error confirmed live in the browser, a draft staying invisible to the
  employee while the finalized review shows, the self-review ban's hidden button confirmed live, cycle
  removal blocked-while-in-use vs. removed-cleanly, and permission checks including "My Performance" never
  403ing even for an account with no linked employee record at all. `hr.py` (22/22), `hr_attendance.py`
  (10/10), and `hr_leave.py` (37/37) re-confirmed unaffected; `admin.py` re-confirmed at 168/168.
- **Deliberately not built yet:** Phase F (Payroll Foundation), Phase G (HR Dashboard).
- **Not verified:** a real HR administrator running review cycles across a full staff roster; how the
  employee profile's Performance section reads once someone has many years of reviews (no pagination, same
  class of gap every other per-employee history list in this module has); `ContractDialog`'s latent
  edit+new-document PUT bug from Phase D (still flagged, still not fixed -- still out of scope for a phase
  that doesn't touch contracts).

## HR Module -- Phase F: Payroll Foundation

Basic salary (read from the employee's existing `EmploymentContract.salary_reference` -- the field Phase B
deliberately left "reference-only, read by nothing" until this phase finally gave it a purpose) plus
statutory income tax and pension, computed into one payslip per employee per branch per month. **Confirmed
via `AskUserQuestion`, two decisions:** (1) basic salary + statutory tax + pension only this phase, no
ad-hoc allowances or other deductions -- the simplest version that is still genuinely useful; (2) a
finalized run posts to the accounting engine best-effort, the same "never block the real action" pattern
every other money-moving module in this app already uses.

- **Real Ethiopian statutory figures were researched, not assumed or left blank** -- the user's own
  instruction from the very start of this HR module initiative. PAYE income-tax brackets come from the
  Income Tax Proclamation (Amendment) No. 1395/2025 (effective 8 July 2025: six brackets, 0%-35%, tax-free
  threshold raised to ETB 2,000/month); pension rates (employee 7%, employer 11%) come from the Private
  Organization Employees' Pension Proclamation No. 1268/2022. Both are editable, and the Payroll Settings
  page carries a prominent, un-dismissable "verify before relying on this" notice -- these are a researched
  starting point, never treated as a permanent guarantee. Taxable income is the FULL gross salary; Ethiopian
  pension contributions are confirmed (via research, not assumption) to NOT reduce the PAYE taxable base --
  both tax and pension are computed independently on the same gross figure.
- **Two new tables plus two new settings fields.** `payroll_tax_brackets` (editable per school, tax on a
  bracket = `(taxable_income * rate) - deduction`, the standard subtraction-method presentation already used
  on Ethiopian payslips -- verified by hand that the seeded deduction figures are mathematically continuous
  across every bracket boundary before trusting them). `pension_employee_rate`/`pension_employer_rate` live
  directly on `schools`, the same way `discount_limit_percent`/`waiver_limit_amount` already do -- a single
  editable value per school, not a whole table. `payroll_runs` (one per branch per month, `draft ->
  finalized` via the same "immutable once real" `booted()` pattern `LeaveRequest`/`PerformanceReview` already
  use) and `payslips` (one computed snapshot per employee per run, never re-derived later -- a contract's
  salary changing after the fact must never silently rewrite a payslip that already exists).
- **Accounting integration, confirmed and built exactly as asked.** Five new `default_*_account_id` columns
  on `schools` (salary expense, pension expense, tax payable, pension payable, net salary payable) follow
  the EXACT existing pattern (`default_ar_account_id` etc.) -- found by role via
  `ChartOfAccountsTemplate`, never a hardcoded account. Finalizing posts ONE aggregated entry per run (Dr
  Salary Expense + Dr Pension Expense / Cr Income Tax Payable + Cr Pension Payable (both the
  employee-withheld AND employer-contributed shares, both owed to the same pension fund) + Cr Net Salary
  Payable), the same "one entry per document, not per line" shape every other posting in this app already
  uses -- verified in a test that the debits and credits always balance exactly. `ChartOfAccountsTemplate`
  itself gained a small but real improvement while wiring this in: re-running `install()` now PROMOTES an
  account that already existed from an earlier install (like the chart's own pre-existing "Salaries"
  account) to `is_system` if the template newly assigns it a role, so the deletion guard actually protects
  whatever ends up wired -- previously a pre-existing account could be wired as a control account without
  ever gaining the protection a freshly-created one gets.
- **New `payroll-view`/`payroll-manage` permissions joined the EXISTING `hr` group (11 now)**; `branch_admin`
  got both by default (33 total now, up from 31); `teacher`/`accountant` got NEITHER -- same shape as
  Performance, no self-service "run your own payroll" permission exists, since an employee never generates
  or finalizes anything, only views a finalized payslip once it exists (no permission needed for that at
  all, the same as My Leave/My Performance). The "three seeder places" lesson held with zero rework for the
  fourth phase running, verified via a `php -r` script before any test ran.
- **A real, production-impacting bug was caught before this phase could be called done: tax brackets were
  silently never seeded for the actual demo/production school at all.** The migration that creates
  `payroll_tax_brackets` seeds every school that ALREADY EXISTS at migration time -- but the real/demo school
  is created afterward, by `db:seed` (`DemoSchoolSeeder`), which runs as a SEPARATE step after `migrate`
  completes. A freshly deployed school -- and every test school `BuildsSchool` creates via a plain
  `School::create()`, the same way -- ended up with exactly ZERO tax brackets, meaning
  `Payroll::calculateTax()` would silently return 0 for every employee, forever, with no error anywhere to
  notice it by. Caught only by actually checking the usability session's live database after preparing it
  fresh, not by assuming the migration's seeding was sufficient. Fixed by extracting the bracket data into
  `App\Support\PayrollTaxBracketDefaults` (shared by the migration AND by `DemoSchoolSeeder`, which now calls
  `install()` explicitly right after creating the school -- the exact same "not automatic, but one call away"
  shape `ChartOfAccountsTemplate::install()` already uses), with a dedicated regression test asserting the
  REAL seeding path (`DemoSchoolSeeder`) actually produces working brackets, not just the migration in
  isolation.
- **A second real bug, caught before ever running a test:** the tax-bracket edit dialog's first draft tried
  to convert the displayed percentage back to a decimal fraction by spreading the converted values into
  Inertia's `post()`/`put()` SECOND argument (its options object) rather than its own form `data` -- but
  `useForm().post()`/`put()` always sends whatever is in the hook's own `data` state, never extra keys
  stuffed into the options argument, so the conversion would have silently never been applied; every saved
  bracket would have stored the raw percentage (e.g. `15`) as if it were the decimal rate. Fixed with
  `transform()`, the same officially-correct mechanism already used for Phase D's PUT-spoofing fix.
- **Two e2e-test-only bugs, both matching a documented project lesson instead of being guessed at freshly:**
  a tax-bracket/payroll-run removal both go through a `useConfirm()` dialog the test initially forgot to
  click through (self-caught once the dialog consistently blocked the next action); and two checks polled
  the bare `page.url` property in a sleep loop after a redirect -- the EXACT "Playwright's sync API only
  refreshes `.url` on an active call, never passive polling" gotcha this project's own memory already
  documented from the very first Auth phase, re-found instead of re-learned, fixed with `wait_for_url`.
- **Tests:** `PayrollTest` (13) -- the researched tax figures computed correctly end to end, a full run
  generated with correct tax/pension/net math, an ineligible employee skipped with a plain reason, the
  duplicate-run guard, draft deletion vs. finalized immutability, finalizing notifying each employee AND
  posting a verified-balanced entry to accounting when configured (and silently skipping when not), an
  employee seeing only their finalized payslip never a draft, branch scoping, permission gating, pension/
  bracket settings editability, the notification link fix, and the real-seeding-path regression for the
  bracket bug above. Full regression suite: 918 passed, the same 2 pre-existing `RegistrationTest` failures,
  zero new regressions. `tests/e2e/hr_payroll.py` (new file, 18 checks): the settings page end to end
  (pension rates, add/edit/remove a bracket), a full generate-finalize cycle with the skip notice confirmed
  live, the duplicate-month guard's inline error, the HR profile's Payroll section showing the same payslip,
  draft-run removal, and permission checks including "My Payslips" never 403ing with no linked employee
  record. `hr.py` (22/22), `hr_attendance.py` (10/10), `hr_leave.py` (37/37), and `hr_performance.py`
  (18/18) re-confirmed unaffected; `admin.py` re-confirmed at 168/168.
- **Deliberately not built yet:** Phase G (HR Dashboard) -- the final phase of this module. No per-employee
  allowances/other deductions, no payslip PDF/print, no bank-file export, no overtime rules -- all
  deliberately deferred per the confirmed "simplest version" decision, not oversights.
- **Not verified:** a real school's accountant confirming the researched tax brackets and pension rates
  against current law (explicitly flagged as the user's own standing requirement, not yet done by anyone);
  a real payroll run across a full staff roster with dozens of employees; how the accounting entry reads
  once a school has actually been running payroll for several months.

## HR Module -- Phase G (FINAL): HR Dashboard

A snapshot across everything Phases A-F built, folded into the EXISTING owner/branch_admin/auditor home
dashboard rather than a new standalone page -- the exact same place the inventory/requisitions/assets tiles
already live, using the exact same `null = not for you` convention those blocks already established. No new
entities this phase: purely read-only aggregation, so there was no design fork worth an `AskUserQuestion` --
the only real choice (fold into the existing dashboard vs. build a separate `/hr/dashboard` page) was a
clear match to established convention, not a genuine ambiguity.

- **Six figures, each independently gated on its own phase's permission**, matching
  `assetStats()`/`requisitionStats()`'s existing shape exactly: total active employees (`employee-view`),
  contracts expiring soon (`contract-view`), today's staff attendance rate as a `Meter` (mirroring the
  EXISTING student-attendance meter on the same dashboard) (`staff-attendance-view`), pending leave requests
  (`leave-view`), performance reviews finalized this calendar year (`performance-view`), and the most recent
  payroll run's period + status (`payroll-view`). A branch_admin who can see employees but was never given,
  say, `payroll-view` still gets every other figure -- only the one sub-block they lack permission for comes
  back `null` and is simply not rendered, never a placeholder or an error.
- **Two bugs caught before this phase could be called done, both re-applications of lessons from Phases
  F/A rather than freshly-discovered ones -- re-found by habit, not re-learned the hard way:** (1) a payroll
  tile's label was built by concatenating a JS template string instead of going through `t()` with an
  interpolation placeholder -- caught by habit before running anything, fixed to
  `t('dashboard.latest_payroll_run_x', { period })`, keeping every dynamic dashboard string on the same
  i18n path the rest of the app uses. (2) The e2e test for a tile's click-through used
  `until(lambda: "..." in page.url)`, the EXACT bare-`.url`-in-a-sleep-loop gotcha this project's own memory
  has now documented across two different phases (Auth Phase 1 originally, re-found in Payroll Phase F) --
  caught immediately this time and fixed with `wait_for_url` before ever running the test, rather than
  needing a confusing debug session to re-discover it a third time.
- **A pre-existing, unrelated test-infrastructure gap surfaced while re-running the FULL regression suite,
  not something this phase's own code caused:** `tests/e2e/role_home.py` was missing the
  `sys.stdout.reconfigure(encoding="utf-8")` line every other e2e script in this project already has,
  crashing with a `UnicodeEncodeError` the moment it tried to print an Amharic/Tigrinya detail string on
  this Windows terminal's cp1252 console codepage -- a latent fragility that could have been tripped by any
  phase's Amharic content, at any time, on this exact terminal. Confirmed by re-running after the one-line
  fix: 125/125 checks pass clean, with no connection to this phase's own HR dashboard changes at all (that
  file tests the role-based home/task-tile page, not the dashboard this phase touched).
- **Tests:** `HrDashboardTest` (10, new file) -- the null-vs-populated gate for each of the six figures,
  correct branch scoping (and org-wide seeing every branch), the attendance-rate math, the
  finalized-this-year filter correctly excluding drafts and other years, the latest-run-by-year/month
  lookup, and an auditor seeing every figure read-only regardless of their own specific permissions.
  `DashboardPermissionsTest` (the pre-existing money-figures test) re-confirmed unaffected by the new `hr`
  key added to the same `orgStats()` array. Full regression suite: 928 passed, the same 2 pre-existing
  `RegistrationTest` failures, zero new regressions. `tests/e2e/hr_dashboard.py` (new file, 10 checks): every
  tile showing real numbers for an owner, a tile's click-through landing on its own module's page, a teacher
  with no `employee-view` getting no HR section at all while the rest of their dashboard still renders
  cleanly, and the Amharic labels translated with no raw keys. `role_home.py` re-confirmed at 125/125 (after
  its own unrelated encoding fix above); `hr.py` (22/22), `hr_attendance.py` (10/10), `hr_leave.py` (37/37),
  `hr_performance.py` (18/18), and `hr_payroll.py` (18/18) all re-confirmed unaffected; `admin.py`
  re-confirmed at 168/168 (no permission changes this phase, so no count updates needed).
- **Not verified:** how the dashboard reads once a school has been running every HR phase for a long time
  (dozens of pending leave requests, years of finalized reviews, a long payroll history) -- the figures are
  simple counts/lookups with no pagination concern, but nobody has watched them at real production scale.

**WHOLE MODULE FINAL STATE, all seven phases (A Employee Profiles/Departments/Job Titles -> B Employment
Contracts -> C Staff Attendance -> D Leave Management -> E Performance Reviews -> F Payroll Foundation -> G
this one):** every phase's own key design fork was confirmed via `AskUserQuestion` before being built, never
assumed -- HR/admin vs. self-service attendance (Phase C), self-service leave with HR-on-behalf (Phase D),
employee-view-only performance reviews (Phase E), payroll scope and accounting integration (Phase F). The
"three seeder places" lesson from Phase B held with zero rework across every phase that followed it. The
self-review ban (first established in Procurement, outside this module entirely) was reused and
independently strengthened FOUR times (Staff Attendance, Leave, Performance, and Leave's own second half)
without ever being copy-pasted blindly -- each reuse was re-checked against that phase's own actual
authorization shape. Two real, production-impacting bugs were caught by this discipline before either could
reach a user: Phase D/E's `NotificationPresenter` routing a self-service recipient into an HR-only page that
would 403 on them, and Phase F's tax brackets silently never being seeded for the real/demo school at all.
Full HR module test count: `HrEmployeeTest` (13) + `EmploymentContractTest` (8) + `StaffAttendanceTest` (8)
+ `LeaveManagementTest` (14) + `PerformanceReviewTest` (12) + `PayrollTest` (13) + `HrDashboardTest` (10) =
**78 PHP feature tests**, plus `hr.py` (22) + `hr_attendance.py` (10) + `hr_leave.py` (37) +
`hr_performance.py` (18) + `hr_payroll.py` (18) + `hr_dashboard.py` (10) = **115 browser checks**, all green
as of this phase. Deliberately never built, by explicit scope decision rather than oversight: per-employee
allowances/other payroll deductions, payslip PDF/print, bank-file export, overtime rules, a manager
hierarchy/reporting-line concept (performance reviews and payroll both key off `performance-manage`/
`payroll-manage` permission holders, never an org chart).

## Academic & Student Expansion -- Phase 1 of 9: Timetable

The first phase of a large, separately-scoped brief covering nine modules (Timetable, Period/Subject
Attendance, School Calendar, Medical Records, Section Balancing, Library, Discipline, Assignments, and
optional Student Portal improvements), explicitly required not to break existing registration, attendance,
exams, marks, report cards, the parent portal, permissions, notifications, or audit logs. Before any code,
four genuinely ambiguous, consequential forks across the whole nine-module brief were confirmed via
`AskUserQuestion` rather than assumed: (1) Student Portal scope -- enrich the EXISTING parent portal rather
than finally flipping on the long-dormant `student` login role; (2) proceed through all nine modules in the
brief's own 1-9 order; (3) Library fines stay self-contained within the Library module, never silently
folded into tuition invoicing; (4) Section Balancing wires into the EXISTING section-picker step as
recommendations, never a silent auto-assignment. This phase is Timetable only.

A wholly new concept layered on top of the EXISTING `TeachingAssignment` (teacher x section x subject, with
no day/time component at all) -- Timetable finally answers "when," without requiring a matching
TeachingAssignment to exist first, so a one-off substitute can always be scheduled.

- **Two new catalogs plus the slot itself:** `Period` (school-wide bell schedule -- Ethiopian multi-branch
  schools overwhelmingly share one daily schedule, so this is school-wide, not per-branch, a choice the
  brief didn't ask about but that was a clear fit for the existing branch/school scoping convention) and
  `Room` (branch-specific, a physical location). `TimetableSlot` ties `term_id` + `section_id` + `subject_id`
  + `teacher_id` + nullable `room_id` + `period_id` + `day_of_week` (1-7, Monday=1) together, scoped to a
  term rather than a whole academic year (matching the EXISTING `Exam` model's own scoping choice).
- **Conflict detection, never a DB constraint:** `TimetableConflicts::detect()` checks teacher, room, and
  section double-booking at the same term/day/period in application logic, because an authorized override
  must be allowed to proceed with a typed reason -- something a unique index could never express. Three new
  permissions (`timetable-view`, `timetable-manage`, `timetable-override`), granted to `branch_admin` by
  default, following the exact three-seeder-places discipline (`PERMISSIONS`, its own new `timetable` group,
  and `DEFAULT_PERMISSIONS`) that has now held with zero rework across every module since HR Phase B.
  Self-service "My Timetable" needs no permission at all, matching the established My Leave/My
  Performance/My Payslips pattern -- any account can see their own schedule.
- **Six issues caught during this phase, none reaching the user, most of them re-applications of
  already-documented lessons rather than fresh discoveries:**
  1. Manual `AuditLog::record()` calls were initially written for the override path and then self-caught and
     removed as redundant with `TimetableSlot`'s own `auditAction()`/`auditReason()` overrides -- the exact
     HR Phase A lesson, re-applied on sight.
  2. An `auditExcluded(['override_reason'])` was copied from `EmploymentContract`'s pattern without
     thinking -- wrong, since the override reason is exactly the substantive justification that belongs in
     the audit trail. Reverted in favor of a proper `auditReason()` override.
  3. The `Audited` trait's `created` hook always writes a plain `"x.created"` entry and never consults
     `auditAction()` -- true for every other model in this codebase, but TimetableSlot is the first one
     where a CREATE can itself be the overridden action (an override made while first scheduling a slot, not
     just while editing one). Caught by the feature test's own `an authorized override saves despite a
     conflict and is audited` assertion failing (0 matching audit rows, not 1); fixed with a dedicated
     `auditsCreation()`/`booted()` pair on the model that only engages when `override_reason` is set at
     creation time, leaving every ordinary creation on the trait's normal path untouched.
  4. The `index()` slot mapping initially sent only human-readable display strings (`section`, `subject`,
     `teacher`, `room`), never the underlying ids -- the exact HR Phase D missing-`leave_type_id` bug class.
     Self-caught before any test ran; every Select in the edit dialog would otherwise have opened empty.
  5. Clicking an empty grid cell to add a new slot was initially passed into the dialog as its `slot` prop,
     making `editing = Boolean(slot)` wrongly true for a brand-new, unsaved slot. Fixed with a separate
     `prefill` prop so "add here" and "edit this" are never confused.
  6. **The one bug the e2e test actually caught, not self-caught in review:** both the admin timetable grid
     and the self-service My Timetable page located a cell's content with `slots.find(...)` -- singular,
     first-match-only. An authorized override can legitimately leave TWO slots clashing in the same
     day/period cell (that's the entire point of allowing an override), and `.find()` silently showed only
     the first one, hiding the double-booked teacher's SECOND class from their own schedule entirely -- the
     one view where a double-booked teacher most needs to see both commitments. Fixed by switching both
     pages to `.filter()` and rendering every slot in the cell, not just the first.
- **A pre-existing i18n gap surfaced by the regression sweep, not this phase's own slot/period/room content:**
  the new `timetable` permission group's own label (`perm.group_timetable`, the fieldset legend above its
  three permissions in the staff-creation wizard) was never installed in any of the four languages --
  everything else about the group translated correctly, but the group's own name rendered as the raw key.
  Caught by `admin.py`'s existing Amharic regression check, not a new check written for this phase. Fixed by
  reusing the exact word already established for `nav.timetable` in each language (never inventing a second
  translation for the same concept), confirmed via `npm run test:i18n` (3,361 keys, parity across all four
  languages). `admin.py` also had its own hardcoded `groups == 10` assertion, missed during the earlier
  proactive permission-count sweep that updated every OTHER hardcoded count in that file; updated to 11 once
  the gap was found by actually running the suite rather than re-auditing the file a second time by eye.
- **Tests:** `TimetableTest` (16, new file) -- conflict-free creation; teacher, room, and section
  double-booking each refused on their own; a slot with no room skipping the room check; an authorized
  override saving and being audited (including at creation time, the bug above); override attempted without
  the permission still refused even with a typed reason; updating a slot excluding itself from its own
  conflict check; branch scoping on the section and a cross-branch room both refused; no permission at all
  refused on both index and store; a teacher seeing only their own slots on My Timetable; Period/Room
  deletion blocked while referenced; the `TimetableChanged` notification linking to My Timetable (applied
  proactively this time, not as a fix, following the Leave/Performance `NotificationPresenter` lesson); and
  creating a slot notifying its teacher. Full regression suite: 944 passed (928 + this phase's 16), the same
  2 pre-existing `RegistrationTest` failures, zero new regressions. `tests/e2e/timetable.py` (new file, 12
  checks): no permission refusing all three new routes; a Periods and a Rooms add/remove round trip through
  their own dialogs; a conflict-free slot showing on a class's grid; a teacher double-booking refused inline
  with an override offered; the override saving as visibly "Overridden"; a teacher seeing both of their
  classes on My Timetable (the bug above, now fixed); and an uninvolved teacher seeing the empty state.
  Regression e2e re-confirmed clean on a fresh session: `admin.py` 168/168 (64 permissions, 11 groups, 36
  branch_admin defaults), `role_home.py` 125/125, `hr.py` 22/22, `hr_attendance.py` 10/10, `hr_leave.py`
  37/37, `hr_performance.py` 18/18, `hr_payroll.py` 18/18, `hr_dashboard.py` 10/10.
- **Not verified:** a THIRD overlapping slot in one cell (the UI shows every slot that exists, but there is
  no "add another" affordance once a cell already holds one -- an admin can still create a third via a
  different scope's empty cell, just not from an already-occupied one); multi-branch room/section conflicts
  at real scale (the demo data has one branch); the notification's actual rendering in the notification
  center UI (verified at the database/routing level in PHP tests, not clicked through in the browser).

## Academic & Student Expansion -- Phase 2 of 9: Period/Subject Attendance

Attendance for ONE class period, deliberately separate from the EXISTING daily/homeroom `AttendanceRecord`
(one row per student per DAY, no subject). The brief's own words: "Present/Absent/Late/Excused, restricted
to the assigned teacher or admins" -- almost a verbatim match to the EXISTING daily attendance's own access
rule, so this phase reuses that proven shape rather than inventing a parallel one.

- **One row per student per `TimetableSlot` occurrence per date** (`period_attendance_records`, unique on
  `enrollment_id`+`timetable_slot_id`+`date`). Four statuses, not daily attendance's five -- no `sick` here,
  matching the brief's own wording exactly rather than copying the existing enum blindly. The date's actual
  weekday must match the slot's own `day_of_week`, or the request is refused (404) -- a slot scheduled for
  Monday simply cannot be marked against a Tuesday date.
- **No new permission.** `PeriodAttendanceController` reuses the EXISTING `mark-attendance` permission
  (already held by `teacher`/`branch_admin`/`owner`) rather than adding a narrower one, since this is the
  same underlying action -- marking attendance -- at finer granularity. A Teacher may only mark the ONE slot
  they are actually the teacher of (never a colleague's subject, even in the same class); anyone else
  holding `mark-attendance` can mark any slot in branch scope, covering an absent teacher -- the exact
  `assertCanMark()` shape `AttendanceController` already established, reused almost line for line.
- **No guardian notification on a period absence.** The brief's own "do not spam" instruction, applied
  directly: a student has several periods a day, and the existing daily/homeroom absence notice already
  reaches the family once. This was a considered decision, not an oversight, and is pinned by its own
  regression test (`Notification::fake()` + `assertNothingSentTo`).
- **A deletion guard added to the EXISTING `TimetableSlot`:** Phase 1 shipped it with no deletion guard at
  all ("nothing references a slot yet"), which stopped being true the moment real period attendance could
  exist against one. `TimetableSlot` now uses `GuardsDeletion`, blocking removal once it has attendance
  history -- caught proactively while building this phase, not by a failing test.
- **One real gap self-caught before writing a single test:** the controller's first draft let a Teacher
  mark their OWN assigned slot without checking `mark-attendance` at all -- only the slot-ownership check
  ran for the teacher branch, meaning an account with that permission explicitly revoked could still mark
  attendance for a slot they happen to be scheduled on. Re-reading `AttendanceController::show()`/`store()`
  side by side showed the existing code checks `mark-attendance` UNCONDITIONALLY before ever narrowing by
  role; `assertCanMark()` here was fixed to match that exact shape (the permission check first, the
  role-based narrowing after), rather than trusting a half-remembered copy of the pattern.
- **One real bug found by the browser test, not self-caught in review:** `Timetable/Index.jsx`'s
  `<FlashMessages />` had no `errorKeys`, so the new `DeletionBlockedException` -> `errors.delete` path (a
  global handler already wired for every other guarded model) would have failed completely silently the
  first time someone tried to delete a slot with real attendance against it -- the exact "stuck/silent
  dialog" class of bug this project's memory has flagged since Leave Phase D. Fixed with
  `errorKeys={['delete']}` before writing the e2e test that would otherwise have caught it the hard way.
- **Tests:** `PeriodAttendanceTest` (14, new file) -- the assigned teacher marking their own slot; a
  different teacher refused even with `mark-attendance` generally held; a teacher WITHOUT `mark-attendance`
  refused even for their own slot (the self-caught gap above); a branch_admin covering any teacher's slot;
  marking on the wrong weekday refused; unmarked students staying unmarked; re-marking updating the same
  record (not duplicating) and recording who last saved it; no permission at all refused on every route;
  branch scoping; a `TimetableSlot` blocked from deletion once attendance exists against it; creation not
  audited but a later change is (`period_attendance.status_changed`); and the no-notification decision
  pinned directly. Full regression suite: 958 passed (944 + this phase's 14), the same 2 pre-existing
  `RegistrationTest` failures, zero new regressions. `tests/e2e/period_attendance.py` (new file, 13 checks):
  no permission refused on both routes; the assigned teacher's own period showing by default for TODAY (the
  real Addis-Ababa weekday, not the test runner's own local date -- re-learned the `SchoolCalendar::today()`
  timezone lesson live while writing this test, not from memory alone); marking and saving; a different
  teacher refused; a branch_admin picking a teacher, seeing who already marked it, and re-marking
  successfully; and the no-guardian-notification decision confirmed at the database level in the browser
  flow too. Regression e2e re-confirmed clean on a fresh session: `admin.py` 168/168, `role_home.py`
  125/125, `attendance.py` 69/69 (the EXISTING daily attendance suite, fully unaffected), `timetable.py`
  12/12, `hr.py` 22/22, `hr_attendance.py` 10/10, `hr_leave.py` 37/37, `hr_performance.py` 18/18,
  `hr_payroll.py` 18/18, `hr_dashboard.py` 10/10.
- **Not verified:** a cross-subject attendance report (today this phase only supports marking and reviewing
  one slot at a time, by design -- a "simple foundation," matching the brief's own restraint language used
  for other phases of this same initiative); a slot's `update()` is NOT blocked once attendance exists
  against it (only deletion is guarded) -- editing a slot's room/period after real attendance has been taken
  against it is still possible and was a deliberate, out-of-scope simplification, not an oversight.

## Academic & Student Expansion -- Phase 3 of 9: School Calendar

Holidays, exam periods, meetings, activities and deadlines, each visible to the whole school, parents only,
or staff only. Confirmed two genuinely consequential design forks via `AskUserQuestion` before writing any
code: (1) a simple chronological agenda list rather than a traditional month-grid calendar -- simpler, and
more accessible for low-literacy users, this project's own standing UX principle, and this app has no
existing month-grid pattern to build on; (2) the coarse 3-bucket audience (everyone/parents/staff) rather
than a finer per-role checklist -- reusing a model people already know from Announcements rather than
teaching a new one.

- **Deliberately mirrors the EXISTING `Announcement` almost line for line** -- the closest real precedent in
  this codebase for "broadcast something to an audience, notify them, respect branch scoping." Same 3-bucket
  `AUDIENCES`, same branch-or-every-branch (`NULL` = whole school) scoping, same `scopeVisibleTo()`/
  `recipients()` shape, same one-shot `notified_at` notify-guard. The one deliberate difference:
  `CalendarEvent` has no `publish_on` scheduling concept at all -- an event is visible and notifies its
  audience the moment it is created, since its own `start_date` already answers "when it happens," a
  different question from "when people are told about it." One combined page (list + inline dialog), not a
  separate wizard page like Announcements -- an event has far fewer meaningful decisions to walk through.
- **No new permission GROUP.** `manage-calendar` folds into the EXISTING `messages` group alongside
  `manage-announcements`/`manage-complaints` (communication-family permissions stay together), granted to
  `branch_admin` by default -- the same precedent `manage-announcements` already set. Viewing needs no
  permission at all, for anyone with a `school_id`, exactly like Announcements.
- **Two issues this phase, one self-caught before testing, one found only by actually running the existing
  regression suite:** (1) the controller's first draft split events into `upcoming`/`past` with
  `$events->reject('is_past')`/`->filter('is_past')` -- passing a STRING to a Laravel Collection's
  `reject()`/`filter()` is not the `where()`-style truthy-key shorthand some other collection methods
  support; both expect a real callable, so this would have thrown immediately on the first real request.
  Caught by re-reading the code before running anything, fixed to `fn ($e) => $e['is_past']`. (2) Adding the
  65th permission (and branch_admin's 37th default) predictably shifted `admin.py`'s own hardcoded counts
  again -- despite this project's own repeatedly-written lesson to update them proactively, it was missed
  this time and caught only by actually running the full e2e regression sweep rather than re-auditing the
  seeder change by eye a second time. Thirteen string-literal counts were fixed in one pass, plus one bare
  `.count() == 34` integer comparison the string-based find/replace couldn't catch on its own -- re-found
  only once the browser actually exercised that exact assertion. Lesson reinforced once more: after changing
  any permission total, RUN `admin.py`, don't just trust a find/replace was exhaustive.
- **Tests:** `CalendarEventTest` (8, new file) -- audience and branch scoping together (mirroring
  Announcements' own combined test almost exactly); only managers can post, and branch staff are forced into
  their own branch even when asking for the whole school; validation (missing title, invalid type, an end
  date before the start date); creating notifies the audience immediately, with no scheduling delay to wait
  out; a branch_admin refused from touching another branch's event; removing archives (soft-deletes) and is
  audited (`calendar_event.archived`); someone with no `manage-calendar` can still view but never write; and
  the upcoming/past split itself, including a multi-day event that SPANS today correctly counting as
  upcoming, not past. Full regression suite: 966 passed (958 + this phase's 8), the same 2 pre-existing
  `RegistrationTest` failures, zero new regressions. `tests/e2e/calendar.py` (new file, 15 checks): anyone
  can view but only a manager sees "Add event"; a past event tucked under a collapsed section rather than
  mixed into the open list; a branch_admin's event forced into their own branch; a staff-only event actually
  invisible to a parent and a parents-only event actually invisible to a teacher (audience really filtering,
  not just stored); edit and remove, with a confirmation before removing; and the removed event confirmed
  archived, not erased. Regression e2e re-confirmed clean on a fresh session: `admin.py` 168/168 (65
  permissions, 11 groups unchanged, 37 branch_admin defaults), `role_home.py` 125/125, `attendance.py` 69/69,
  `timetable.py` 12/12, `period_attendance.py` 13/13, and all six HR suites.
- **Not verified:** real scale (dozens of events across a full school year, whether the agenda list needs
  pagination the way Announcements already has it -- this phase deliberately shipped without pagination,
  since the brief's own "foundation" framing elsewhere in this initiative suggested starting simple); no
  holiday/non-instructional-day concept exists anywhere else in this codebase, confirmed by investigation
  before building this phase, so a calendar event of type "holiday" is purely informational -- it does NOT
  block attendance-taking, alter invoicing period calculations, or interact with the academic year/term date
  validation in any way. This was a deliberate scope decision, not an oversight: integrating "holiday"
  events with attendance/invoicing logic would be a much larger, riskier change touching code this whole
  initiative is explicitly required not to break, and nothing in the brief asked for that integration.

## Academic & Student Expansion -- Phase 4 of 9: Student Medical Records

One medical profile per student (blood type, allergies, chronic conditions, current medications, treating
physician, general notes) -- a single always-upserted record, not a timestamped incident log, and with no
file attachment (the brief never asked for one, and a plain text field already covers "allergic to
penicillin" perfectly well). The brief's own three explicit requirements -- permission, audit, never in the
ordinary list -- each drove a specific, deliberate design choice below, with no `AskUserQuestion` needed
this phase: every genuinely ambiguous point had a clear, well-precedented answer already established
elsewhere in this same codebase.

- **A NEW permission group, not folded into the existing `people` group.** `medical-records-view` and
  `medical-records-manage` are deliberately SEPARATE from `manage-students` -- the exact same explicit
  view/manage split `employee-view`/`employee-manage` already established for HR, so holding the broad
  student-admin permission alone never implies seeing health data. Given a brand-new group (`medical`, 12th
  group) for something this sensitive, in the registrar's `job title vs system role` worded in the staff
  wizard, an owner sees exactly what they are granting rather than it being buried inside the much larger
  People list. Granted to `branch_admin` by default -- not withheld, matching the HR module's own consistent
  precedent for branch-sensitive data a branch admin needs day to day (contracts, performance, payroll), not
  a new "opt-in only" exception that would have broken that established pattern.
- **Its own dedicated page and controller, never folded into the ordinary `Students/Edit` form.** Reaching
  `/students/{id}/medical` at all already proves the viewer holds one of the two permissions (or is an
  auditor); `StudentController::edit()`'s own payload gained exactly one new field, a `canViewMedical`
  boolean -- never the medical content itself, so even inspecting that ordinary page's JSON reveals nothing.
  A "Medical Record" button appears on the profile only when that flag is true.
- **The single most important decision this phase, found during design rather than left to be discovered by
  a test: `StudentMedicalRecord` deliberately does NOT use the `Audited` trait.** That trait logs a full
  field-level before/after diff into the audit log -- but `view-audit-log` is a BROADER permission than
  `medical-records-view`/`manage`, so the standard automatic diff would have let anyone who can merely see
  the audit log read a child's actual allergy/condition/medication text straight out of it, silently
  defeating the entire point of restricting this record in the first place. `auditSnapshot()`/`auditAction()`
  are private trait internals with no clean per-model override, and excluding every sensitive field via
  `auditExcluded()` would have suppressed the entry (and the "must be audited" requirement) entirely rather
  than redacting it. Instead, `StudentMedicalRecordController::update()` writes its own minimal
  `AuditLog::record($user, 'student_medical_record.created'|'updated', $record)` with `before`/`after` both
  left `null` -- a "this was changed, by whom, when" trail, never a "here is what changed" one. This is a
  deliberate, documented exception to this project's otherwise-universal "always use the Audited trait,
  never a manual call" rule, for the one case where the trait's own design would itself be the privacy leak.
- **Confirmed as a genuine gap rather than assumed:** no holiday/non-instructional-day concept exists
  anywhere in this codebase (checked again, same finding as Phase 3's Calendar investigation), and no
  precedent exists anywhere for a "who VIEWED this record" read-audit trail -- only writes are ever audited,
  everywhere in this system. "Audit" in the brief is read as the standard create/update trail every other
  model in this app already gets, not a new access-log mechanism with no existing infrastructure to build on.
- **Tests:** `StudentMedicalRecordTest` (9, new file) -- `manage-students` alone refused outright (the
  brief's central requirement, proven directly); `medical-records-view` can see but not save;
  `medical-records-manage` can see and save; an auditor bypasses with read-only access, matching every other
  controller's own `hasRole('auditor')` convention; branch scoping; the ordinary student list's full response
  body never contains a sentinel allergy string even for a viewer who holds `medical-records-manage`; and
  the single most load-bearing test in the whole phase -- the audit log's own `before`/`after`/raw JSON
  never contains the sentinel content either, confirming the manual-record design actually works as intended,
  not just in theory. Full regression suite: 975 passed (966 + this phase's 9), the same 2 pre-existing
  `RegistrationTest` failures, zero new regressions. `tests/e2e/medical_records.py` (new file, 9 checks): a
  registrar (manage-students, no medical permission) sees no button and is refused the route outright; a
  branch_admin sees the button, saves a record, and it comes back; an auditor opens the page read-only (no
  save control visible at all); and the ordinary student list search turns up nothing for the saved allergy
  text. One self-caught test-script bug along the way: checking a `<textarea>`'s saved value via the page's
  visible `innerText()` doesn't work -- a form control's `value` is a DOM property, not rendered text content
  -- fixed to read `input_value()` on the field directly. `admin.py` needed the by-now-familiar proactive
  count fixes (67 permissions, 39 branch_admin defaults, 12 groups) -- fifteen hardcoded counts updated in
  one pass BEFORE declaring the phase done, catching the drift by actually running the suite rather than
  trusting the seeder edit was self-evidently complete. Regression e2e re-confirmed clean on a fresh
  session: `admin.py` 168/168, `role_home.py` 125/125, `attendance.py` 69/69, `timetable.py` 12/12,
  `period_attendance.py` 13/13, `calendar.py` 15/15, and all six HR suites.
- **Not verified:** real scale (hundreds of students, whether a nurse/admin workflow across many profiles at
  once needs its own list view -- deliberately out of scope, since the brief's own "no ordinary list
  display" requirement argues directly against building one); no file attachment exists for a scanned
  immunization record or doctor's note, a deliberate scope decision matching this initiative's "foundation,
  not a giant system" ethos elsewhere, not an oversight.

## Academic & Student Expansion -- Phase 5 of 9: Section Balancing

Recommendations, never silent auto-assignment -- confirmed by staff, exactly the brief's own wording. The
user had already confirmed at the very start of this nine-phase initiative that this should wire into the
EXISTING section-picker step rather than become a new standalone page, so this phase touches no new routes
or pages at all: it augments the one shared `SectionPicker.jsx` component already used by the enrollment
wizard, the student-profile "move to another class" picker, and the promotion wizard's destination step.

- **`App\Services\SectionRecommendation::annotate()`** -- a single, new, stateless service that takes the
  exact section array shape every one of those three controllers already builds (each entry already merged
  with the existing `SectionCapacity::describe()`, so `occupied`/`full` were already present server-side; the
  gap was purely that nothing read them for this purpose) and adds one new `recommended: bool` field. It
  NEVER changes selection, order, or any other field -- confirmed directly by its own test. Pure headcount
  comparison among siblings of the same grade, matching the brief's own worked example exactly ("8B currently
  has the lowest number of students") rather than a capacity-weighted fill ratio nothing in the brief asked
  for. A full section is excluded as a candidate entirely (recommending something unselectable would be
  perverse); a lone section with no real sibling gets no recommendation (nothing to balance against); ties
  are broken deterministically (alphabetically by section name) so exactly one section is ever flagged, never
  zero or two.
- **Branch and year scoping needed real care, not a single flat "group by grade."** Two of the three call
  sites span every branch of the school for an org-wide actor (enrollment, promotion), and the promotion
  picker also spans every ACADEMIC YEAR a section ever existed in -- comparing "Grade 8 A" at one branch
  against "Grade 8 B" at a different branch, or a closed prior year's section against a current one, would be
  meaningless. The service groups by whichever of `branch_id`/`academic_year_id` happen to be present in a
  given call site's own array (some are already scoped to one branch/year by their own query, where grouping
  by grade alone is correct; others are not), and only ever considers `is_current_year` sections as
  recommendation candidates when the promotion picker's own flag is present. One real gap self-caught before
  any test ran: the promotion controller's own section array had a `branch` NAME string but no `branch_id`,
  which would have silently defeated the branch-grouping the service relies on -- added the missing field.
- **A subtler bug caught by the service's own test suite, not assumed correct from the design doc:** the
  first draft treated "nothing to balance against" as "fewer than 2 NON-FULL candidates remain" -- but a
  grade with one open section and one FULL sibling very much has a real choice being made (an
  override-capable staff member is choosing between them), unlike a grade that truly has only one section at
  all. Fixed to count real siblings (full or not, same year) FIRST, and only then decide whether there's a
  genuine choice to flag a recommendation for, before filtering candidates by fullness.
- **The frontend change is a single additive prop, not a new UI paradigm.** `ChoiceCard` (the generic,
  shared building block behind `SectionPicker` AND several unrelated flows like `ChoiceGroup` and the invoice
  generator's own picker) gained one new optional `badge` slot next to its title -- every existing caller
  that doesn't pass it sees zero visual change. `SectionPicker` passes a green "Recommended" `StatusBadge`
  plus an extended hint line naming the actual count when a section is flagged, reusing the badge/translation
  infrastructure already established elsewhere in this app rather than inventing new UI chrome. Nothing is
  ever preselected because of a recommendation -- the exact same "nothing chosen until the person chooses"
  rule every wizard in this app already follows.
- **Tests:** `SectionRecommendationTest` (9, new file) -- lowest-headcount recommended; a full section never
  recommended even with fewer students; a lone section gets no recommendation; a tie resolves to exactly one,
  deterministically; different grades, branches, and academic years never compared against each other;
  closed-year sections never recommended; the service never mutates order/ids/other fields; and a live HTTP
  request to the real registration page confirmed the flag actually reaches the Inertia payload. Full
  regression suite: 984 passed (975 + this phase's 9), the same 2 pre-existing `RegistrationTest` failures,
  zero new regressions. `tests/e2e/section_balancing.py` (new file, 5 checks): the emptier of two same-grade
  sections shows the badge with the real count in its reason, its busier sibling does not, and neither is
  preselected. Regression e2e re-confirmed clean, including the two existing suites most likely to touch the
  shared `SectionPicker`/`ChoiceCard` components directly: `receptionist.py` (78/78, the full registration
  wizard) and `forms.py` (120/120, the promotion wizard among others) -- plus `admin.py` 168/168 (no
  permission changes this phase, so no count drift at all, a change of pace from the last several phases),
  `role_home.py`, `attendance.py`, `timetable.py`, `period_attendance.py`, `calendar.py`,
  `medical_records.py`, and all six HR suites. One pre-existing, unrelated test-infrastructure fragility
  surfaced (not caused by this phase): `admin.py` leaves behind a `Section` named "Q" at the end of its own
  run (already defensively cleaned at the START of its next run, with its own code comment acknowledging
  "left behind by an interrupted earlier run") -- running it immediately before `receptionist.py` in the same
  session pushed that branch's total section count past the `SectionPicker` grouping threshold and caused a
  cascading failure in two unrelated suites, fixed simply by reordering the regression sweep, not by changing
  any product code.
- **Not verified:** a true 3-way-or-more tie's exact alphabetical tie-break choice in front of a real user
  (covered at the unit level, never watched in a live multi-way-tied classroom scenario); whether staff find
  the badge's exact wording ("Recommended -- fewest students right now (N)") clear without an explanation the
  first time they see it -- no real-user session has tested this copy specifically.

## Academic & Student Expansion -- Phase 6 of 9: Library

Issue/return/renew/lost/damaged tracking, with fines self-contained -- confirmed at the start of this whole
nine-phase initiative that fines never become an Invoice. This is the largest single phase since the HR
module: a brand-new catalog/copy/loan data model, the first polymorphic relation in this codebase outside
accounting's own unrelated use of one, and a standalone librarian workflow with its own permissions.

- **School-wide catalog, branch-scoped physical copies** -- the exact same split `Period`/`Room` already
  established for Timetable: a `Book` title is shared across the whole school, but each `BookCopy` (a real,
  individually trackable physical item with its own `available/issued/lost/damaged` status) belongs to one
  branch. Deliberately NOT modeled on the EXISTING Inventory module's `stock_balances` (a single fungible
  quantity per item/store) -- a library copy has its own identity and lifecycle a quantity counter cannot
  express; only the broader "ledger discipline" and "one gateway service" ideas were reused, not the code.
- **`App\Services\Library`** is that one gateway -- issue/return/renew/markLost/markDamaged/waive/pay, every
  copy-status and loan-status transition locked and transactional, mirroring `Stock`/`Ledger`/
  `SectionCapacity`'s existing single-writer discipline. `calculateFine()` is pure headcount-free date math
  (days overdue x the school's own per-day rate) that returns `null` -- never zero -- the instant no rate is
  configured, so "fines OFF" is a real absence, not a rate of 0.00 that still implies a fine exists.
- **The first polymorphic relation in this codebase outside accounting's own unrelated `JournalEntry::source()`.**
  A loan's borrower is either a `Student` (no login needed -- issued by staff on their behalf, the same
  staff-assisted shape as the rest of this app) or a `User` (a staff borrower, who also gets a genuine
  self-service "My Library Loans" page, no permission required, the same shape as My Leave/My Performance/My
  Timetable). Searching for a borrower is its own lightweight endpoint gated by the Library's own
  permissions, never piggybacking on `manage-students` -- a librarian without that broader permission can
  still find and issue to a student.
- **A new, dedicated `library` permission group, deliberately NOT granted to `branch_admin` by default** --
  the one place this phase broke from the recent run of phases that DID default-grant sensitive permissions
  to branch_admin (Calendar, Medical Records). Here the Inventory module's own precedent was followed
  instead: "a librarian is any staff account these are explicitly toggled on for, not a new role, and not an
  assumed part of the standard bundle." Both choices are correct in their own context; this phase matched
  the one that fit better (an optional specialist duty, not a universal branch-admin responsibility).
- **One real production bug caught only by the e2e test, not by any PHP test:** `Collection::map()` on an
  Eloquent Collection returns another Eloquent Collection even once every item has been mapped into a plain
  array -- and `EloquentCollection::merge()` calls `$item->getKey()` expecting real models, crashing the
  moment the borrower-search endpoint tried to combine its student and staff result arrays into one list.
  No PHP feature test exercised this path with BOTH a matching student and a matching staff member in the
  same search, so it went undetected until the browser actually typed a name into the search box. Fixed with
  `->toBase()` before merging, converting to a plain Support Collection first. A second, related bug was
  fixed proactively alongside it, before it could bite for the same underlying reason as past Procurement/
  Inventory dialog bugs: `IssueLoanDialog` had no remount `key`, so issuing a SECOND copy while the first
  one's picked-borrower state was still cached internally could silently carry over stale selection --
  fixed with `key={`issue-${copyId}`}` on the dialog, the exact established fix for this exact bug class.
- **A genuine, expected side effect of this whole initiative's cumulative growth, not a Phase 6 defect on
  its own:** adding "My Library Loans" to the teacher's self-service menu pushed its total item count past
  the sidebar's own `collapsible = totalItems > 12` threshold for the first time -- the teacher menu now
  folds, exactly like the owner's already has for a while. The regression suite's own `role_home.py` had a
  check hard-coded to "the teacher menu never folds," written back when that was true; updated to confirm
  folding now works correctly for teacher too (the same already-proven mechanism), rather than asserting a
  permanently-short menu that several phases' worth of new self-service features had already quietly outgrown.
- **Tests:** `LibraryTest` (14, new file) -- permission separation from `manage-students`; a book/copy
  blocked from deletion while real history exists; issuing to both a Student and a User borrower; a
  non-available copy refused a second issue; on-time return never fining even with a rate configured;
  late return fining correctly ONLY when a rate is configured (never a silent zero); renewal extending from
  today, not stacking onto the old due date; marking a loan lost/damaged closing it and tagging the copy;
  waiving/paying a fine confirmed to never touch Invoice at all; branch scoping; an auditor's read-only
  bypass; and self-service isolation (one person's loans never showing another's). One self-caught test bug
  along the way: re-using a stale in-memory Eloquent instance to reset a loan's status for a second test
  scenario silently no-opped (Eloquent's dirty-tracking saw "active" being set to its own already-cached
  "active" value and skipped it) -- fixed with a direct query-builder update instead of the model instance.
  Full regression suite: 998 passed (984 + this phase's 14), the same 2 pre-existing `RegistrationTest`
  failures, zero new regressions. `tests/e2e/library.py` (new file, 12 checks): the real production
  borrower-search bug caught and fixed live; add book -> add copy -> issue -> see it on loan -> return with
  no fine (off by default) -> a staff borrower seeing it on their own self-service page. Regression e2e
  re-confirmed clean on a fresh session, admin.py's hardcoded counts updated proactively (69 permissions, 13
  groups) and `role_home.py`'s teacher-menu assertion updated to match reality: `admin.py` 168/168,
  `role_home.py` 125/125, `receptionist.py` 78/78, `forms.py` 120/120, plus every other sibling suite.
- **Not verified:** real-world fine collection workflow friction (the UI supports "mark paid"/"waive" but
  nobody has watched a real front-desk cashier use it); a reservation/holds system for a popular book with a
  waiting list -- deliberately not built, the brief never asked for it and it would meaningfully grow this
  "foundation" phase's scope; no overdue/due-soon notification exists for this module at all (a deliberate
  scope cut -- `Student` is not `Notifiable` in this codebase, and the brief's own notification examples list
  never mentioned the library, so building guardian-forwarding logic for a feature that wasn't asked to
  notify anyone felt like solving a problem nobody raised).

## Academic & Student Expansion -- Phase 7 of 9: Student Discipline

Incident/category/action/status/attachments under permission protection -- the brief's own words. The most
directly-reused architecture of any phase so far: the `StudentMedicalRecord` template (Phase 4) for the
"more sensitive than the audit log" shape, and `EmploymentContract`'s (HR Phase B) private-attachment
pattern, applied with no new shapes invented.

- **The "redact the audit trail" pattern, reused for the second time.** `DisciplineIncident` deliberately
  carries no `Audited` trait, for the identical reason as `StudentMedicalRecord`: `view-audit-log` is a
  broader permission than `discipline-view`/`discipline-manage`, so an automatic field-level diff on every
  create/update would leak incident content to people who hold the former but not the latter. The model gets
  a plain `auditLabel()` only; the controller calls `AuditLog::record()` manually with `before`/`after` both
  left `null`. Verified the same way as Medical Records: a dedicated test asserts the full JSON-encoded audit
  entry never contains the sentinel incident text, and that both diff fields are genuinely `null`, not just
  unpopulated in the UI.
- **Permanent once created, by deliberate design -- no `destroy()` method, no DELETE route at all.** Matches
  `Payment`/`Invoice`/`AuditLog`'s own immutability in this codebase: a mistake in a recorded incident is
  corrected by editing its status, category, or description, never by erasing that it happened. Confirmed
  with a test asserting `DELETE /discipline/{id}` returns 405 -- not merely "refused by permission," but
  genuinely unreachable because no such route exists to reach.
- **The PUT-with-FormData fix applied correctly from the start.** `DisciplineIncidentDialog`'s attachment
  upload needed the same `transform((d) => ({...d, _method: 'put'}))` + `post()` (never a literal HTTP
  `PUT`) that `ContractDialog` in HR still gets wrong today (a known, out-of-scope latent bug flagged but not
  touched) -- applied correctly here on the first pass, now that the fix is well-proven.
- **Granted to `branch_admin` by default**, following Medical Records' precedent rather than Library's
  opt-in-only one: discipline is treated as an ordinary part of running a branch (like medical records),
  not a specialist duty some staff accounts opt into (like library issuing). Both `discipline-view` and
  `discipline-manage` folded into one new, 14th permission group.
- **No bugs found in the new code this phase** -- a genuine first for this nine-phase initiative. All 11 PHP
  tests and all 11 e2e checks passed on their first run, directly attributable to reusing the now
  thoroughly-proven Medical Records/Employment Contract templates rather than inventing a new shape.
- **Two real, pre-existing test-hygiene bugs were found and fixed during the regression sweep that followed
  -- neither caused by this phase, both exposed by it running last in a long chain of e2e suites:**
  `forms.py` deleted its own `announcements` row on every run but never the `notifications` rows pointing at
  it (not FK-cascaded), so 84 stale notification rows had silently accumulated across this session's many
  prior runs; once two matching rows existed, a strict-mode Playwright locator that expected exactly one
  started failing. Fixed by deleting the matching notification rows alongside the announcement in the
  script's own cleanup. Separately, `attendance.py`'s "change the day" check assumed the Ethiopian
  day/month/year triple-select (`aria-label="Day"`) that `DateInput` only renders when the school's calendar
  preference is `ethiopian`/`both` -- this demo school is seeded as `gregorian`, where `DateInput` renders a
  plain, unlabeled `<input type="date">` instead, so the check could never have passed against this fixture's
  actual configuration. Fixed to detect which control is actually rendered and drive either one. A third,
  unrelated encoding gap was also fixed: `attendance.py` lacked the `sys.stdout.reconfigure(encoding="utf-8")`
  line that newer scripts carry, so printing a `->`-style arrow character crashed the whole run under this
  console's codepage before the real "Day" bug could even surface -- added, matching the existing pattern in
  `forms.py`/`discipline.py`.
- **Tests:** `DisciplineIncidentTest` (11, new file) -- `manage-students` alone grants nothing; `discipline-view`
  can read but not write; `discipline-manage` can record and see it; an auditor gets read-only access
  regardless of explicit permission; branch scoping refuses another branch's student; discipline content
  never appears in the ordinary student list/search; the audit trail itself never contains the actual
  incident content (before/after both `null`); resolving an incident records who and when, only on the
  transition into `resolved`; an uploaded attachment is served only to an authorized viewer; there is no way
  to delete an incident (405, route doesn't exist); someone with no permission at all is refused everything.
  All 11 passed on the first run. Full regression suite: 1009 passed (998 + this phase's 11), the same 2
  pre-existing unrelated `RegistrationTest` failures, zero new regressions. `tests/e2e/discipline.py` (new
  file, 11 checks): a registrar with no discipline permission refused the route and never sees the button; a
  branch_admin records an incident, sees it listed, resolves it and the badge updates; the school-wide
  dashboard lists it and its status filter actually filters; an auditor can view but has no write controls.
  All 11 passed on the first run. Full 17-suite e2e regression re-confirmed clean after the two pre-existing
  fixes above: `admin.py` 168/168 (hardcoded counts updated to 71 permissions/41 branch_admin defaults/14
  groups), `forms.py` 120/120, `attendance.py` 69/69, plus every other sibling suite green.
- **Not verified:** real-world friction of the school-wide discipline dashboard at genuine incident volume
  (built and tested at demo-data scale only); whether school leadership would want any escalation/notification
  when a pattern of incidents accumulates for one student -- deliberately not built, the brief's own words
  only asked for "incident/category/action/status/attachments under permission protection," and the standing
  "do not spam" instruction argues against inventing a new notification path nothing asked for.

## Academic & Student Expansion -- Phase 8 of 9: Assignments/Homework

"A simple foundation for teacher assignments" -- the brief's own words, and its own explicit field list:
subject/class/teacher/title/description/due date/attachment/status. The first phase in this whole
nine-phase initiative needing NO seeder change at all: `manage-assignments` already existed in the
original base spec, reserved and documented as "not used by any screen" -- it was simply waiting for this
phase, already correctly grouped under `academics` and already default-granted to both `teacher` and
`branch_admin`.

- **`teacher_id` is always derived from the CURRENT `teaching_assignments` row, never picked freely, and
  re-checked live on every edit** -- the exact "never trust a stored id forever" principle
  `MarkBatchPolicy::enterMarks` already established. A teacher who is later reassigned away from a
  section+subject immediately loses the ability to edit assignments they created for it; a branch admin may
  manage any section+subject in their branch without holding the teaching assignment themselves, the same
  "can cover" shape `mark-attendance` already has.
- **`status` (draft/published) gives the brief's own required field real teeth:** a draft is never sent to
  anyone and never appears on a parent's homework page at all -- "parents/students should only see
  assignments through authorized portal functionality" (the brief's own words) is enforced by the query
  itself (`Assignment::published()`), not by a frontend-only hide. Publishing is a one-way, one-shot
  transition (`Assignment::publish()`) that notifies guardians exactly once; editing an already-published
  assignment's content afterward never re-notifies, matching the "do not spam" instruction that governs
  every notification in this codebase.
- **Both notification types the brief's own section 10 explicitly asked for were built, not deferred:**
  "assignment created" (`AssignmentPublished`, sent the moment an assignment is published) and "assignment
  deadline approaching" (`AssignmentDueSoon`, a new `assignments:notify-due-soon` scheduled command mirroring
  the EXISTING `announcements:notify-due` cron exactly, dailyAt 06:30, guarded by `due_soon_notified_at` so a
  missed or retried run never repeats a reminder). This is a deliberate contrast with Library/Discipline
  (Phases 6-7), which built no notifications at all -- those two were never named in the brief's own
  notification wishlist, while assignments explicitly were; the same "do not spam" instruction cuts
  differently depending on what was actually asked for, not into a single blanket rule.
- **One notification per enrolled student, to that student's own guardians** (the same per-student shape
  `StudentMarkedAbsent` already uses) rather than one broadcast to the whole class's guardians at once -- so
  each guardian's notification link routes to their own child's homework page, never a generic class-wide one.
- **The parent-facing page is a new `FamilyController::homework()` action**, not a new "Student Portal"
  surface -- matching this initiative's very first confirmed decision that student/parent portal work means
  enriching the EXISTING parent portal, never flipping on student login. Same child-switcher shape as
  `family.attendance`/`family.report-cards`, same `StudentPolicy::viewRecords` authorization.
- **No bugs were found in the new PHP code this phase** -- all 15 feature tests passed on the first run, a
  second phase running now with zero first-pass PHP defects (after Phase 7), attributable to directly reusing
  MarkBatch's live-reauthorization principle and Discipline/Medical Records' attachment and portal-visibility
  patterns rather than inventing new shapes.
- **One real product bug, caught only by the existing `role_home.py` regression suite, not by any new test:**
  the new teacher/admin nav label "Assignments" matched that script's own long-standing `DEV_WORDS` denylist
  of jargon this project deliberately keeps out of the sidebar (`Structure`, `Audit`, `Promote`, `Dashboard`,
  and -- already, from before this phase -- `Assignment`). The brief's OWN field is literally named
  "assignment," so the word felt natural while writing the feature, but this project's plain-language
  standard for a low-literacy audience overrides that instinct in the one place space is tightest and words
  must be instantly scannable: the sidebar. Renamed the nav label (and the matching page title) to
  "Homework" -- the same word already used on the parent-facing side -- while deliberately leaving the
  model, controller, route, and i18n KEY names as `assignment(s)` (a code-level name, never read by an actual
  user) and leaving the teacher-facing page's own body copy alone (a CRUD page read by staff, not a guided
  low-literacy flow, is held to a less strict wording standard than the sidebar itself). Two smaller issues
  were also caught in the TEST scripts themselves, not the product: the e2e script's own subject-name
  assertion used a case-sensitive substring check against text rendered through an `uppercase` CSS class
  (Chromium's `inner_text()` reflects the post-transform visual text, "MATHEMATICS" not "Mathematics") --
  fixed to compare case-insensitively; and the usability session's dev server had to be rebuilt and
  re-served after adding the new migration, since `prepare-session.sh` only applies new migrations on a
  fresh build, not to an already-running session's throwaway database.
- **A process lesson from this phase's OWN regression sweep, not a product issue:** re-running a partial
  subset of e2e suites against an already-mutated session (rather than a freshly rebuilt one) double-applied
  `forms.py`'s own promotion side effects and `admin.py`'s own temporary-branch/section mutations, producing
  failures in THREE unrelated suites (`receptionist.py`, `section_balancing.py`, `timetable.py`) that vanished
  completely once the full 18-suite sweep was re-run start to finish against one single fresh session. Every
  e2e script in this suite assumes it runs exactly once, in the established order, against a session nothing
  else has already touched -- confirmed, not a new rule, but worth restating since it bit this phase's own
  verification process directly.
- **Tests:** `AssignmentTest` (15, new file) -- permission required; a teacher scoped to their own current
  teaching assignments (cannot create for a class they do not teach); a branch admin covering any class in
  their branch, credited to the ACTUAL subject teacher, never the admin who clicked save; branch scoping;
  refusing creation when no teacher currently holds that section+subject; publishing notifying each enrolled
  student's guardians exactly once; editing a published assignment never re-notifying; a draft never
  reaching a guardian's homework page; a guardian seeing only their own child's section; an uploaded
  attachment served only to an authorized staff member or guardian; a teacher who loses the teaching
  assignment losing edit access immediately (and the new holder gaining it); the due-soon command notifying
  once and refusing to repeat on a second run; no way to delete someone else's assignment; someone with no
  permission refused everything. All 15 passed on the first run. Full PHP regression suite: 1024 passed
  (1009 + this phase's 15), the same 2 pre-existing unrelated `RegistrationTest` failures, zero new
  regressions. `tests/e2e/assignments.py` (new file, 10 checks): an accountant (no `manage-assignments` by
  default) refused the route and the nav item; a teacher creating a draft that stays invisible to a guardian;
  publishing making it visible with subject and due date; removing it taking it off the list again. All 10
  passed after the test-script fixes above. Full 18-suite e2e regression (`period_attendance.py` run BEFORE
  `forms.py`, matching the now twice-confirmed lesson that forms.py's own promotion test depletes Grade 1 A's
  roster; `admin.py` last, as always) re-confirmed clean on one single fresh session: every suite green,
  `role_home.py` 125/125 with the renamed nav label, `admin.py` 168/168.
- **Not verified:** real submission/grading workflow -- deliberately out of scope, "do not build a giant LMS
  yet" is the brief's own instruction; a dashboard widget summarizing a teacher's own assignment load
  (the base spec's "placeholders for future... assignment widgets" note was left as a placeholder, since the
  brief's own Phase 8 text asked only for the foundation itself, not a dashboard rollup); bulk-assigning the
  same homework across multiple sections at once.

## Academic & Student Expansion -- Phase 9 of 9 (FINAL): Student Portal Improvements

The brief's own closing module, confirmed at the very start of this nine-phase initiative to come last
since it was expected to tie together earlier phases into one coherent parent-facing view. "Student Portal"
was confirmed, before any code in this whole initiative, to mean enriching the EXISTING parent portal, never
finally flipping on the long-dormant `student` login role. The brief's own "potential features" list --
timetable, attendance, assignments, results, report card, announcements -- was already five-sixths covered
by the time this phase started (attendance/report-card from the base build, homework from Phase 8,
announcements/calendar already open to any signed-in parent with no permission gate). Two genuine gaps
remained, confirmed by investigation rather than assumed: a child's weekly timetable (explicitly deferred
here by a Phase 1 code comment: `TimetableChanged`'s own docblock says "parents see the timetable through
the portal itself, Phase 9"), and -- confirmed via `AskUserQuestion` as a genuine scope fork, since the
brief lists "results" and "report card" as separate items -- a per-exam results breakdown distinct from the
report card's blended subject averages.

- **`FamilyController::timetable()`** mirrors the EXISTING teacher self-service `TimetableController::mine()`
  almost exactly, just scoped to `$student->currentEnrollment->section` instead of `$user->timetableSlots()`
  -- same current-term lookup, same per-slot shape (teacher name added, since a parent needs it where a
  teacher viewing their own schedule does not). `Family/Timetable.jsx` reuses the EXISTING day x period grid
  table from `Academics/Timetable/Mine.jsx` nearly verbatim, wrapped in the same child-switcher pattern
  `Family/Attendance.jsx`/`Family/Homework.jsx` already established.
- **`FamilyController::results()`** is a genuinely new view: every PUBLISHED exam score for a child, grouped
  by term, newest first, across every term and year they have ever been enrolled in -- never an unpublished
  or draft mark, the same visibility rule the report card itself already follows. Deliberately built straight
  from `Mark`/`MarkBatch` rather than the existing `ReportCardSnapshot` archive, which investigation showed
  only exists for a term someone happened to print a PDF for -- reading from the authoritative, always-
  complete source instead of an opportunistic side effect avoided shipping a "results history" that could
  silently have gaps.
- **No bugs were found in the new PHP or frontend code this phase at all** -- both the 4 new PHP tests and
  the 9 new e2e checks passed on their first run, the cleanest single-phase result of this entire nine-phase
  initiative, attributable to every new piece directly mirroring an already-proven pattern (the teacher's own
  timetable view, the attendance/homework child-switcher shape, the report card's own publish-gated
  visibility rule) with nothing novel invented. Phase 8's own nav-jargon lesson was checked proactively this
  time rather than learned the hard way again: neither "Results" nor "Timetable" appear anywhere on
  `role_home.py`'s own `DEV_WORDS` denylist, confirmed clean on the very first regression run.
- **Tests:** four new methods added to the EXISTING `ParentPortalTest` (not a new file -- this phase adds no
  new entity of its own, only new read-only views over data other phases already created, exactly matching
  that file's own stated purpose) -- a child's real timetable shown and a stranger's child refused; an
  empty timetable for a child with no slots this term handled gracefully; published results shown newest-term
  first while a draft batch's score never appears anywhere in the payload; an empty results list for a child
  with nothing published yet. All 4 passed on the first run (one test-only type-comparison fix: `assertSame`
  on a DB-returned int vs. a literal float failed for an unrelated reason having nothing to do with product
  behavior, fixed to `assertEquals`). Full PHP regression suite: 1028 passed (1024 + this phase's 4), the
  same 2 pre-existing unrelated `RegistrationTest` failures, zero new regressions. `tests/e2e/family_portal.py`
  (new file, 9 checks): an accountant refused both new routes outright; a parent seeing both new menu items;
  their child's real weekly schedule with subject and teacher; a published exam's score shown exactly, a
  draft exam's score and name never appearing anywhere on the page. All 9 passed on the first run. Full
  20-suite e2e regression (period_attendance.py before forms.py, admin.py last, run exactly once against one
  single fresh session -- the hard-won process lesson from this same phase's OWN Phase 8 verification,
  applied correctly from the start this time) confirmed clean end to end.
- **Not verified:** real guardian reaction to seeing individual exam scores land throughout a term rather
  than waiting for one final report card (a genuine behavior change in how results reach a family, never
  watched with a real parent); whether the per-exam results list remains readable for a student with many
  years of history (no pagination was added -- "simple foundation" again, and no demo account yet has enough
  terms of data to say whether this will ever matter in practice).

## WHOLE INITIATIVE FINAL STATE, all nine phases of "Academic & Student Expansion"

Every genuinely ambiguous, consequential fork across all nine phases was confirmed via `AskUserQuestion`
before being built, never assumed -- the four foundational decisions made before Phase 1 even started
(Student Portal = enrich the parent portal; proceed 1-9 in the brief's own order; Library fines stay
self-contained; Section Balancing is recommendations, never silent auto-assignment), plus one more at the
very start of this final phase (build a genuine per-exam results view, not just the existing report card).
Two real, production-impacting bugs were caught by this initiative's own testing discipline before reaching
a user: Phase 6's `Collection::map()`/`merge()` Eloquent-vs-plain-array crash in the library borrower search,
and Phase 8's nav label hitting this project's own long-standing plain-language jargon denylist. Established
architectural principles were reused, not reinvented, phase after phase: the school-wide-catalog vs.
branch-scoped-instance split (`Period`/`Room`, then `Book`/`BookCopy`); the single-writer-gateway-service
pattern (`Stock`/`Ledger`/`SectionCapacity`, then `App\Services\Library`); the "redact the audit trail when a
model is more sensitive than `view-audit-log` itself" pattern (Medical Records, then Discipline); and the
"never trust a stored id forever, re-check the live relationship" principle (`MarkBatchPolicy::enterMarks`,
reused directly by Assignments' teacher re-authorization). Full initiative total: 9 phases, each shipped with
its own PHP feature tests and its own dedicated e2e browser suite, a full regression re-confirmed clean after
every single one, and zero functionality lost from the EXISTING registration/attendance/exams/marks/report
cards/parent portal/permissions/notifications/audit logs the brief opened by requiring untouched. Deliberately
never built, by explicit scope decision across multiple phases rather than oversight: student login itself (a
role that exists in the codebase and stays a deliberate non-feature); a library reservation/holds system; any
escalation/notification when discipline incidents accumulate; a submission/grading workflow for assignments
("not a giant LMS yet," the brief's own words); pagination on the new results history. No Phase 10 is
currently planned -- the brief's own full nine-module scope is now implemented.

## Phase log

### Pilot — Parent payment proof (done, before the phases were renumbered)

- **Before:** one form, four fields at once (amount, method dropdown, reference, file input) inside the invoice page; small controls; success shown only as a top-of-page flash; the rejected file stayed attached.
- **Now:** `Components/PaymentProofWizard.jsx` (used by `Pages/Invoices/Show.jsx`, parents only): *How did you pay?* (4 cards, none preselected) → *How much?* (big number field, "I paid the full amount" button, over-balance caught in plain words) → *Add your proof* (take/choose a photo with preview, optional reference) → *Check and send* (summary + what happens next) → an in-place success panel. Sends the same four fields to `PaymentController@store`; no backend change.
- **Verified** (`tests/e2e/payment_wizard.py`, 44 checks, real Chromium at 390px, throwaway SQLite DB): step logic, ≥56px targets, Enter advances, wrong-type/oversize file messages, disguised-file server rejection → jump back + file cleared, Back keeps answers, exactly one `pending` payment stored with the right values, no double-send after success, no JS errors, no horizontal overflow in am/om/ti. `npm run test:i18n` (241 keys × 4 languages), `npm run build`, full `php artisan test`.
- **Bug this caught before it shipped:** the auto-submit-on-reaching-review pitfall above.
- **Not verified:** any real parent using it. The Amharic/Oromo/Tigrinya strings are AI drafts, tracked `draft` in `resources/js/lang/review-status.json` (`wizard`, `payments`). The rest of the invoice page around the wizard is still English-only.

### Phase 1 — Shared design system (done)

Built `Components/ui` (table above), a dev-only gallery at `/dev/ui-kit`, larger labelled header/sidebar/dropdown targets, and moved the payment-proof wizard and the ID-card page onto it. **Verified** by the 60-check gallery test (light + dark contrast, all four languages), the unchanged 44-check payment-wizard test, `UiKitRouteTest`, i18n parity, the build and the full backend suite. **Bugs it caught before release:** Tailwind not scanning `.js` (unreadable dark info alerts), the 3.8:1 success button, Cancel not getting focus in the confirm dialog. **Not verified:** any real person using it; the new strings are AI drafts.

### Phase 2 — Role-based home page and navigation (done)

`HomeTasks` + `ActionTile` + `homeTasks.js`; plain-language menu labels; folding long sidebars; dead developer placeholders removed from the teacher dashboard; the permission gap above fixed. **Verified:** `tests/e2e/role_home.py` — 128 checks across eight accounts (owner, principal, registrar, teacher, accountant, cashier, parent, auditor): right tiles in priority order, 3–6 tiles, ≥ 96px tall, **every tile and every menu link requested and opened (never 403/404)**, pages outside each role still refused (403), folding/remembering/auto-opening, plain menu words, no raw keys or overflow in Amharic/Oromo/Tigrinya on a phone, no money on the registrar's home. Plus `DashboardPermissionsTest`, the earlier 60-check UI-kit and 24-check session-data suites, i18n parity, the build, and the full backend suite. **Not verified:** whether a real receptionist/teacher/accountant/parent finds the right tile faster — that is what the Phase 0 sessions are for; the new `menu`/`home` strings are AI drafts.

### Phase 3 — Receptionist experience (done)

Wizard, class cards, registration confirmation, Find a student, profile, printing (above). **Backend changes, all small:** `StudentController@index` returns `phoneMatchIds`, `justRegistered`, `canManage`; `@store` also flashes `registered_student`; `@create`/`@edit` sections carry grade, section name and class-teacher name; the profile carries a guardian's phone only for people who manage students. **Verified:** `tests/e2e/receptionist.py` — 76 checks in a real browser as `registrar@demo.test` from the home tile through registration (with a temporarily *full* class), the ID card, finding the student six different ways, the profile (link/unlink a parent, edit, move class, withdraw controls), an auditor and a teacher being refused, a phone layout, and Amharic/Oromo/Tigrinya screens; `ReceptionistFlowTest` (11 tests: phone search however typed, branch scope, **no phone number in the page**, one-time confirmation and its scope, the unchanged registration rules, section-card fields, who sees a parent's phone); the earlier 128/60/24-check suites; i18n parity; build; the full backend suite. **Not verified:** a real receptionist doing it — the Phase 0 sessions are the test that matters; the new `enroll`/`find`/`student` strings are AI drafts.

### Candidate next phases (my read of the current code — a proposal, not a decision)

Ordered by "how much a low-literacy user is exposed to it" × "how badly the current screen departs from the principles":

1. **Enrollment wizard itself** (receptionist's core task): step 1 asks six fields at once, step circles are 36px, Section and gender are small dropdowns, guardian step mixes a radio choice + up to four fields. Rework onto the shared components (sections as cards, gender as two big buttons, split the child's names from the birth date). Highest value, but it is the reference pattern, so change it carefully with its own browser test.
2. **Staff account creation + permissions** (administrator): tier, title, branch and a long permission checklist on one form.
3. **Accountant flows:** verify/reject payment, refund, generate invoices — mostly correct already (Verify is disabled when it would overpay), but tap targets and wording are small.
4. **Finding a student** (receptionist): search/filter discoverability on the students list.
5. **Attendance** is already close to the principles (one screen, big status buttons); revisit last.

Each phase: inspect → say what exists → change only that → drive it in a real browser → run the regression suites → record it here and, once real users try it, in `UAT.md`.
