# Usability Testing With Real School Users — School ERP

**Goal of this document:** find out where a real receptionist, teacher, accountant, parent, or administrator gets stuck, confused, or clicks the wrong thing — *before* they hit that in a real school, not by asking "do you like it?" but by watching them try to do their actual job.

**Status: this is the protocol, not the result.** No session has been run with a real person yet. Every task card, the facilitator script, and the observation log below are ready to use; the "Findings" section at the end is empty on purpose, waiting for real data. See [Why I didn't fabricate this](#why-i-didnt-fabricate-this) for why that matters.

**Last updated:** 2026-09-25.

**Who is responsible:** the owner-developer runs or arranges these sessions with real people who fill each role (or as close to it as can be arranged) — this is not something that can be delegated to the software itself.

---

## Why I didn't fabricate this

I was asked to give five different real people realistic tasks and record where they hesitate, what they misunderstand, what they click wrong, and what they ask — without an AI walking through the app pretending to be a receptionist, teacher, accountant, parent, and administrator, and then reporting invented "observations" as if a real session happened. Fabricating that would be worse than not doing it: it would look like real user-research data, get treated as validated, and hide exactly the problems this exercise exists to find. So this document is split cleanly into two kinds of content, never blended:

1. **The real thing** (this file's task cards, facilitator guide, and empty findings log) — for you to run with actual people.
2. **My own [heuristic walkthrough](#appendix-ai-heuristic-walkthrough-not-a-substitute)** — me tracing through the real code paths and flagging places a first-time user seems likely to trip, so you can either fix the obvious ones before spending a real person's time, or watch specifically for whether they actually do trip there. Labeled throughout as AI code-reading, not observation of a person.

---

## Method

- **Tasks describe a goal, not steps.** A task never says "click Students, then click Create, then fill in..." — it says what the person is trying to accomplish, the way their job would actually hand it to them. If the task sheet names a button, it has already given away what we're trying to learn.
- **One person per role**, and ideally someone who has never used this system before (or as close as you can get — an existing staff member who's only used it for their own narrow daily task still counts as fresh for anything outside that).
- **The facilitator does not teach the interface first.** No walkthrough, no "here's where things are." They get the task, and the system as it exists today.
- **The facilitator does not help at the first sign of hesitation.** Silence is data. Only step in if the person is genuinely stuck for an extended stretch (see the facilitator guide's exact threshold) or asks a direct question — and even then, help minimally and note that help was given.
- **Every hesitation, wrong click, dead end, and question is written down as it happens**, not reconstructed afterward from memory.

---

## Facilitator guide

**Before the session:**
- Use the throwaway environment from "Preparing the environment" below — never the production school's data, so a mistake (a bad payment record, a deleted student) costs nothing, and you can reset it for the next person.
- Have the task card ready on paper or a separate screen — not visible on the same screen as the app.
- Sit where you can see their screen and their hands, but resist the urge to narrate or point.

**During the session:**
- Read the task aloud once, in your own words if needed, then stop talking.
- Start a stopwatch when they begin.
- Write down, with a timestamp or rough elapsed time:
  - Every pause longer than ~10 seconds, and what screen they were looking at
  - Every click that doesn't lead where they expected (they click something, look confused, go back)
  - Every time they say something out loud ("I don't see...", "wait, is this...", "why did that...")
  - Every question they ask you, verbatim if you can
  - Whether they completed the task, completed it a different way than expected, or gave up
- **When to step in:** only if they've been visibly stuck (not just reading/thinking) for more than ~60–90 seconds with no progress, or they explicitly ask "what do I do." When you do, give the smallest possible nudge ("what would you expect to happen if you clicked that?") before giving the answer, and write down that an intervention happened and at what point.
- Don't correct them if they complete the task an unexpected way that still works — that's a finding, not an error.

**After each task:** ask one open question — "was anything about that confusing, or not what you expected?" — and write down the answer verbatim. Don't lead them ("did you find the button confusing?").

**Observation log** — one row per notable moment, per task:

| Role | Task | Elapsed time at moment | What happened | Hesitation / misclick / question / gave up | Facilitator intervened? | Severity (see below) |
|---|---|---|---|---|---|---|

**Severity, assigned after the session, not during:**
- **Blocking** — could not complete the task at all without help.
- **Major** — completed it, but only after significant confusion, a wrong path, or a direct question.
- **Minor** — brief hesitation or a comment, but found it without real difficulty.

---

## Preparing the environment (do this before every participant)

Real sessions run on a **throwaway copy with fake data** — never on the production school and never on the dev database. One command builds it, and the same command resets it between participants:

```
bash scripts/usability/prepare-session.sh --serve          # fresh fake school on http://127.0.0.1:8011
python scripts/usability/verify-session-data.py            # 24 checks: does every task have the data it assumes?
```

`prepare-session.sh` prints the accounts (all passwords `password`) and the **real names and amounts to read out** for the accountant, teacher and parent tasks. The seeded situation (`database/seeders/UsabilitySessionSeeder.php`, on top of the demo + sample-academic seeders): Grade 4 A has room; the teacher has an un-entered Mathematics sheet and no attendance taken today; Grade 9 has students and a termly fee but no invoices; a pending partial Telebirr proof sits in the accountant's queue; one fully paid invoice can be refunded; the parent has two children (one invoice 40% paid, one unpaid), an absence on record, two notifications and a published report card; Grade 10 has no section and no fee structure. Have a receipt photo on the participant's device for the parent task.

Facts a facilitator needs so they never "hint by accident":
- **Only a parent can submit a payment.** Staff cannot type a payment in; they verify or reject a parent's proof. So the accountant's "get the partial payment on the books" task is answered by the pending proof — whether an accountant finds that, or looks for a "record payment" button that does not exist, is exactly what we are here to see. Do not point at either.
- The demo *Principal* cannot open the audit log (by design); the administrator persona is **`owner@demo.test`**.
- **The app is not a clean "before".** Phase 1 of the UX work (the parent's guided payment-proof wizard) and three small text fixes listed in `UAT.md` are already in. For a strict baseline of the *original* payment-proof form, the parent's "upload payment proof" sub-task can be run on the old form, which is still at `git HEAD` (`git show HEAD:resources/js/Pages/Invoices/Show.jsx`). Say which version each participant used when you record it.

## Logging what you see

Open `scripts/usability/session-logger.html` on **your** screen (double-click; it works offline, saves in the browser, exports CSV). One button per thing we watch for: pause > 10 s, wrong click, backtracked, confusion, question asked, dead end, unexpected successful path, asked for help, misunderstood terminology, hesitation, facilitator intervened — each stamped with elapsed time and the screen, plus a verbatim note. Outcome: completed unaided / after help / a different way / gave up. After the session, "→ finding" turns an event into a finding in the 8-field format below; the tool refuses a finding that has no observation behind it.

## Task cards

Give the person **only the quoted text** — a goal, never the steps, never a button name. The italic notes are for you.

### Receptionist

> Bethlehem Girma, a girl born on 14 March 2016, is joining the school today in Grade 4. Her mother, Aster Girma, is here with you — phone 0911 22 33 44, email aster.girma@example.com. Get Bethlehem properly registered in a Grade 4 class with her mother connected to her, and give her mother Bethlehem's ID card on paper. Later this afternoon her mother phones with a question about her — show me how you'd pull up Bethlehem's record.

*Covers: register a student · place in a section · link a guardian · print the ID/document · find the student again. Watch: the enrolment wizard's steps and their labels, whether "the mother connected" is understood as part of the same flow, how the ID card gets onto paper (browser print dialog), how they search when "finding her again".*

### Teacher

> It's 8 a.m. and you are the homeroom teacher of Grade 3 A. Record who is at school today: **[roster student 2]** is absent and **[roster student 5]** arrived late. A few minutes later **[student 2]** turns up after all — put that right. Then enter this class's Mathematics results for the mid-term exam (here is the printed score list), hand them in for approval, and tell me where they stand now and what happens to them next.

*Covers: take attendance · correct an absence · enter marks · inspect the approval/status workflow. Read the roster names off the screen beforehand; print a score list for the section. Watch: how the correction is found, what "hand them in" and the status words mean to them, whether they can say what happens next without help.*

### Accountant

> Four jobs. (1) Grade 9's tuition for this term hasn't been billed yet — bill it. (2) The family of **[name from the prepare-session output]** phoned to say they paid half of their fee by Telebirr and sent a screenshot; make sure the school's records show that, and be able to show the family the school has received it. (3) The bank says the family of **[refund student]** accidentally sent their full payment twice, so the school owes some of it back — do what's needed. (4) The principal wants a spreadsheet of who still owes fees.

*Covers: generate an invoice · record a partial payment · verify a payment · receipt/proof workflow · refund · inspect/export a financial report. See "Preparing the environment": an accountant can verify a parent's proof or record money received at the desk (Help a parent > Fees > Record payment received). Watch: where they look first for "record a payment"; whether the partly paid invoice is recognisable in a list; how they reach the receipt and the refund; how they find the export.*

### Parent

> You have two children at the school. Your younger daughter, **[Grade 2 child]**, has been unwell — see how she has been attending this month. Then find out how much you still owe for her, where the school wants the money sent, and tell the school that you paid what is left by bank transfer today (the reference on your slip is FT26-778899; the photo of the slip is on this phone). After that, see whether the school has told you anything, and look at your older child's latest results.

*Covers: switch child · view fees/payment info · view attendance · upload payment proof · view notifications · view report card. Use a phone. Watch: how they discover there are two children and switch, whether "how much do I still owe" and "where do I send it" are found on the invoice, the proof upload, whether they notice the bell unprompted.*

### Administrator

> Yordanos Kebede is joining as the school librarian (yordanos.k@example.com, 0922 33 44 55). She should be able to see and register students but must not be able to touch money, marks or other staff. Set her up. Grade 10 opens next month with one class, taught Mathematics by Meseret Alemu — get that ready, and set Grade 10's yearly tuition at 3,000 birr. Finally, show me how you'd prove that what you just did was recorded.

*Covers: create staff · configure permissions · create a section · assign a teacher · create a fee structure · inspect the audit log. Use `owner@demo.test`. Watch: how "librarian" is entered (a title, not a role), how "only students" becomes permission ticks, whether section → teacher feels like one job or two, what they type when looking for their own actions in the audit log.*

## After each task, and how findings are written

After each task ask one open question — "was anything confusing or not what you expected?" — and log the answer verbatim. Don't lead.

Severity is assigned **after** the session, from the log, never while watching:
**Blocking** (could not complete without help) · **Major** (significant confusion, wrong path, or a direct question that disrupted the task) · **Minor** (brief hesitation, recovered on their own).

Every finding is written in the same eight fields (the logger exports exactly these columns): **1 Task · 2 Role · 3 What happened · 4 Exact point of confusion · 5 Severity · 6 Likely cause · 7 Suggested improvement · 8 Evidence/observation.** "Likely cause" and "Suggested improvement" are hypotheses and suggestions, not decisions — the baseline phase changes nothing.

## Findings

Real findings, the session register, and the separate list of unverified AI/design hypotheses live in **`USABILITY_BASELINE.md`**. As of this writing **no session has been run**; that document says so and stays empty of findings until one has.

---

## Appendix: AI heuristic walkthrough (not a substitute)

I traced through the actual routes, controllers, and page components for each task above — reading code, not operating the app as a person would — and I'm flagging where the design seems likely to cause the friction described in each task's "what this is really testing" note. This is ordinary code-reading, run through the lens of "would a first-time user expect this," and it is not a replacement for watching a real person: I have no read on tone, terminology familiarity, screen-reading speed, or what a receptionist's mental model of "enrollment" actually is. Treat every line below as a hypothesis to watch for in a real session, not a confirmed finding.

- **ID card printing** (`resources/js/Pages/IdCard/Show.jsx`) goes through the browser's native print dialog (`window.print()`) with a print-only CSS region, not a "Download PDF" button. A receptionist unfamiliar with browser print-to-PDF may not realize this is how it's meant to work, especially if they're expecting a direct download like the receipt/report-card PDFs elsewhere in the app.
- **The enrollment wizard's step labels** are single words ("Child," "Contact," "Class," "Parent") — "Class" as the label for "which section" is a plausible point of confusion for someone whose mental model is "grade" first, "section" second, since the step itself asks for a specific section.
- **Correcting an attendance mark** has no distinct "edit" affordance — it's the same take-attendance screen, re-opened for the same date, with the record just re-saved. Someone looking for an explicit "correct a mistake" path may not realize re-visiting the same day's roster is how they'd do it.
- ~~The mark-approval pipeline's status isn't shown to the teacher who submitted it anywhere obvious~~ — **checked and this is not an issue**: `Marks/Index.jsx`, which is exactly the page the "Enter Marks" nav link opens (the one a teacher uses to get to mark entry in the first place), lists every one of their batches with a status badge (draft/submitted/sent back/published). Struck through for the same reason as the payment-mismatch item above.
- ~~Payment verification's amount-mismatch warning is a plain-text sentence, easy to miss and act past anyway~~ — **checked and this is not an issue**: the "Verify" button is already `disabled` whenever the amount exceeds the invoice balance (`Payments/Pending.jsx`), and `PaymentController::verify` independently re-checks under a row lock in the same transaction regardless of what the UI did. Leaving this here, struck through, as a record that the hypothesis was checked against the actual code and ruled out — see `UAT.md` for how AI-identified items get verified before being trusted.
- **Refunds are reached from the invoice/payment view**, not from a dedicated "Refunds" area — an accountant thinking of a refund as its own workflow (the way "generate invoices" or "verify payments" are their own screens) may look for a menu item that doesn't exist.
- **Staff job titles vs. permissions are two different concepts on one form** (a free-text `title` like "Librarian" plus individually toggled permissions) — this is a deliberate design decision per the spec, but it means there is no "Librarian role" to select, which could read as a missing feature rather than an intentional one until the permission toggles are noticed.
- **The parent's "payment accounts"** (the school's bank/mobile-money details to pay into) live on the invoice page itself, not a separate "how to pay" page — reasonable, but worth watching whether a parent scrolls past it looking for a payment method selector before realizing the account details *are* the answer.

None of the above should be treated as validated until a real person actually does the corresponding task and either does or doesn't hit it.
