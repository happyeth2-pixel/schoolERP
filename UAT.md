# User Acceptance Testing — Problems, Decisions, Fixes — School ERP

**Goal of this document:** a permanent history of *why* the product changed in response to real usage — not a bug tracker, a decision log. Every row exists because someone (ideally a real user, see the caveat below) actually did something, we watched, and then made a deliberate call about what to do about it.

**Status: the real log is empty.** Phase D (real user sessions, see `USABILITY_TESTING.md`) has not happened yet. This file's [Pre-UAT hardening](#pre-uat-hardening-ai-code-review-not-real-user-findings) section is a *different* thing — fixes made from my own code-reading while preparing for Phase D, kept in its own clearly-labeled section so it can never be mistaken for real user-testing history. See `USABILITY_TESTING.md`'s "Why I didn't fabricate this" for why that separation matters.

**Last updated:** 2026-09-25.

**Who is responsible:** owner-developer runs the cycle below; nothing here is meant to run itself.

---

## The cycle

```
Real user → performs task → you observe → record problem → classify → prioritize → fix → test again → release
```

Don't skip straight from "record problem" to "write code." A problem sits in this file first, gets classified and prioritized, and only then gets a fix — and after the fix, someone (ideally the same task, with a different person) tries it again before it counts as done.

## Classification

Every observed problem is exactly one of these four things — decide which before deciding what to do about it:

| Type | What it looks like | Example |
|---|---|---|
| **Bug** | Something doesn't do what the software itself claims it does. A button that does nothing, a number that's wrong, an error where there shouldn't be one. | Clicking "Verify" does nothing and no error appears. |
| **UX problem** | The software works correctly, but a real person doesn't understand it, expects something different, or takes an unnecessarily roundabout path. | A user doesn't understand what "Submit for Approval" means, or hesitates over which button means "save." |
| **Missing feature** | The software does what it does correctly, and what's needed simply isn't there yet. | A school asks for SMS payment reminders. |
| **Training / documentation problem** | The software and the design are both fine; the person just hasn't done this uncommon thing before and nobody told them how. | A once-a-year task like closing an academic year, done wrong the first time because nobody explained the order of operations. |

The same observation can look like more than one of these depending on how deep you dig — classify by what you'd actually change: code (bug), a label/flow (UX), a whole new capability (feature), or a help page (training). If you're unsure between UX problem and training problem, ask: would a *first-time* user of *any* skill level still get stuck here, or does this only trip up someone who forgot a procedure they'd been told about once? The former is UX; the latter is training.

## Prioritization: severity × frequency

Don't fix in the order things were noticed. Fix in this order:

1. **Anything touching wrong payments, wrong student records, privacy, permissions, marks, or data loss — always first**, regardless of how many people hit it. One accountant mis-verifying a payment because the UI misled them outranks ten people being briefly confused by a button label.
2. Everything else, by **severity × frequency**:
   - *Severity* — from the observation log in `USABILITY_TESTING.md`: **Blocking** (couldn't complete the task at all) > **Major** (completed only after real confusion or help) > **Minor** (brief hesitation, no real difficulty).
   - *Frequency* — did one person hit this, or did it show up across multiple roles/sessions? A Minor problem five different people independently hit is worth more than a Blocking problem one person hit due to something unusual about their setup.
3. Within the same severity × frequency tier, prefer the fix that's cheaper and lower-risk to ship — a copy change over a new screen, a new screen over a schema change.

## Log

| Date | Test scenario | Observed problem | Classification | Severity | Frequency | Priority | Decision | Fix | Retest result | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| *(none yet)* | | | | | | | | | | |

*(This table stays empty until a real session from `USABILITY_TESTING.md` produces a real observation. Do not fill it in with invented rows.)*

---

## Pre-UAT hardening (AI code-review, not real-user findings)

While building the `USABILITY_TESTING.md` protocol, I traced through the actual code for each task and flagged plausible friction points (its "AI heuristic walkthrough" appendix). I then checked each one against the real code rather than assuming it was real, since two of them turned out to be wrong on closer inspection. This table records that process — it is explicitly **not** the real-user cycle above, has no severity/frequency (no real user was involved to measure that from), and every row here still needs confirming in a real Phase D session before anyone should treat it as settled.

| Hypothesis (from `USABILITY_TESTING.md`) | Checked against | Verdict | Action taken |
|---|---|---|---|
| ID card printing (browser print dialog, no visible download option) might not be understood as "how you get a PDF" | `resources/js/Pages/IdCard/Show.jsx` | Real — confirmed no download button exists | Added a one-line hint under the Print button: choose "Save as PDF" as the destination to download instead of printing on paper. |
| Correcting an already-saved attendance record has no distinct "edit" affordance | `resources/js/Pages/Attendance/Take.jsx`, `AttendanceController` | Real — re-opening the same date is genuinely the only way, and the page didn't say so | Reworded the "last saved by" notice to say outright that changing a status and saving again updates the existing record; the Save button now reads "Update Attendance" instead of "Save Attendance" once a record already exists for that date. |
| Job title (free text) vs. permissions (the real access control) might read as one thing on the staff form | `resources/js/Pages/Staff/Index.jsx`, `StaffController` | Real — title and permissions sit on the same form with nothing distinguishing their roles | Added a line above the permission checklist (create *and* edit forms) stating plainly that the title is just a label and doesn't grant access. |
| Payment verification's amount-mismatch warning is easy to miss and act past anyway | `resources/js/Pages/Payments/Pending.jsx`, `PaymentController::verify` | **Not an issue** — the Verify button is already `disabled` when the amount exceeds the invoice balance, and the backend independently re-checks under a row lock in the same transaction | None — would have been a wasted fix for a problem that doesn't exist. Struck through in `USABILITY_TESTING.md`. |
| A teacher can't find their mark batch's approval status anywhere outside the Result Approvals (staff) screen | `resources/js/Pages/Marks/Index.jsx`, `navigation.jsx` | **Not an issue** — `Marks/Index.jsx` is the exact page the "Enter Marks" nav link opens, and it already shows a status badge (draft/submitted/sent back/published) per batch | None. Struck through in `USABILITY_TESTING.md`. |
| The wizard's "Class" step label might not obviously mean "which section" | `resources/js/Pages/Students/Create.jsx` | Unverified — plausible, but changing a label on a guess without seeing anyone actually hesitate over it risks optimizing for a problem that isn't real | None yet — left as a specific thing to watch for in the receptionist's real Phase D session, not changed pre-emptively. |
| A parent might not immediately recognize "payment accounts" on the invoice page as "how do I pay" | `resources/js/Pages/Invoices/Show.jsx` | Unverified — a genuine design trade-off (accounts live where they're needed, not on a separate page), not a code question a static read can settle | None yet — same reasoning: watch for it in a real parent session before touching it. |
| The parent's payment-proof form (four fields at once, small controls) may overwhelm a low-literacy parent *(from the owner's UX brief, not from a real user)* | `Components/PaymentProofWizard.jsx`, `Pages/Invoices/Show.jsx`, `PaymentController@store` | Plausible; redesign is low-risk because the same four fields go to the same endpoint | Replaced with a 4-step guided wizard (`UX_GUIDELINES.md`, Phase 1). Driving it in a real browser caught a genuine bug — the review step auto-submitting — fixed before release. Still needs a real parent to confirm it helps. |

Verified with: `npm run test:i18n`, `npm run build`, `php artisan test --filter="Attendance|StaffAccount|Staff"` (10 passed) after the three text fixes above — no behavior changed, only copy and a conditional button label, so the fuller regression suite wasn't re-run for this alone.

---

## What happens next

Once a real session from `USABILITY_TESTING.md` runs:
1. Add a row to the Log above for every problem observed — one row per problem, not one row per session.
2. Classify and prioritize using the rules above.
3. Fix the highest-priority items first, especially anything in the "always first" category.
4. **Repeat the same task with a different person** before marking a fix's retest result — not the same person, who now knows the answer.
5. Only then does the log row's Status move to "Fixed & confirmed."
