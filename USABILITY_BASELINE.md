# Usability Baseline — Real Users, Real Tasks — School ERP

**Purpose:** find out what real people actually struggle with, *before* anything else in the UX is redesigned. Nothing is redesigned in this phase. The protocol, task cards, environment setup and logging tool are in `USABILITY_TESTING.md`; how findings later become decisions is in `UAT.md`.

## Status

**No baseline exists yet: 0 sessions run, 0 real findings.** Every task card, the throwaway test environment, the data checks and the facilitator logger are ready and were exercised by script — but a script is not a receptionist. Nothing below Part A may be filled in unless a real person did the task in front of a facilitator. I (an AI) cannot stand in for those people, and I have not.

Two things to know about what "baseline" means here (details in `USABILITY_TESTING.md`, "Preparing the environment"):
- The app already contains the Phase 1 parent payment-proof wizard and three small text fixes from before this phase. The parent's payment-proof sub-task should note which version the participant used; the original form is still at `git HEAD`.
- One participant per role shows what *that person* struggled with, not how often. A problem seen by two or more participants (or across roles) is worth more than one seen once — record it, don't over-read it.

**Last updated:** 2026-09-26.

---

## Part A — Real observed findings

Only things a facilitator watched happen. Written in the eight fields; severity is assigned after the session from the event log (Blocking = could not complete without help · Major = significant confusion, wrong path, or a direct question that disrupted the task · Minor = brief hesitation, recovered alone).

### Session register

| Date | Role | Participant (no name needed) | Device / language | Facilitator | App version (wizard or original payment form) | Tasks attempted → outcome | Event log file |
|---|---|---|---|---|---|---|---|
| *(no sessions yet)* | | | | | | | |

### Findings

| 1 Task | 2 Role | 3 What happened | 4 Exact point of confusion | 5 Severity | 6 Likely cause | 7 Suggested improvement | 8 Evidence / observation |
|---|---|---|---|---|---|---|---|
| *(none — no session has been run)* | | | | | | | |

**Totals:** Blocking 0 · Major 0 · Minor 0.

*(Columns 6 and 7 are the facilitator's hypothesis and suggestion, not decisions. No suggestion here is implemented in this phase.)*

---

## Part B — Existing AI / design hypotheses (NOT findings)

These came from reading the code, from the owner's UX brief, or from the earlier AI walkthrough. **None has been observed with a real user.** Do not redesign anything on their strength; the point of Part A is to confirm or discard them. When a session shows one of these happening, it moves to Part A as a finding with evidence; when a session runs the relevant task and it does *not* happen, mark it "not observed" here.

| # | Hypothesis | Where it came from | Task that would show it | Status |
|---|---|---|---|---|
| B1 | The ID card is printed through the browser's print dialog, with no download button; a receptionist may not realise that is how it works. | Code read (`IdCard/Show.jsx`) | Receptionist: print the ID | Unverified. A one-line hint was already added under the Print button before this phase. |
| B2 | The enrolment wizard's step labelled "Class" may not read as "which section". | Code read (`Students/Create.jsx`) | Receptionist: place in a section | Unverified. Not changed. |
| B3 | Correcting an already-saved attendance mark has no distinct "edit" path (you re-open the same date and save again). | Code read (`Attendance/Take.jsx`) | Teacher: correct an absence | Unverified. A notice and an "Update Attendance" button label were added before this phase; Phase 4 then made the correction itself explicit (changed rows say what they were saved as, and the save lists each change and what parents will be told). |
| B4 | Refunds live inside the invoice/payment view rather than their own screen; an accountant may look for a menu item that isn't there. | Code read (`Invoices/Show.jsx`) | Accountant: refund | Unverified. |
| B5 | **Staff cannot record a payment themselves** — only a linked parent can submit one (`InvoicePolicy::pay`); staff only verify or reject it. A school that takes cash at the desk may look for "record payment" and not find it. | Code read (checked while preparing this phase; the fact is certain, its effect on users is not) | Accountant: partial payment / receipt | Unverified. The most important one to watch; do not point at it during the task. **Changed 2026-09-29:** staff holding `verify-payments` can now record money received at the school (see UX_GUIDELINES.md, "Two ways to get service"); the baseline observation above is kept as it was recorded. |
| B6 | Staff job title (free text) and permissions (the real access control) sit on one form and may read as one thing. | Code read (`Staff/Index.jsx`) | Administrator: create staff, configure permissions | Unverified. A clarifying sentence was already added before this phase. |
| B7 | A parent may not recognise the school's "payment accounts" on the invoice page as "how do I pay". | Code read (`Invoices/Show.jsx`) | Parent: view fees/payment information | Unverified. Not changed. |
| B8 | The enrolment wizard, the reference pattern, may itself overwhelm a low-literacy user: six fields on step 1, small step circles, dropdowns for section and gender. | Owner's UX brief + code read (`Students/Create.jsx`) | Receptionist: register a student | Unverified. Not changed. |
| B9 | The original parent payment-proof form (four fields at once) may overwhelm a parent. | Owner's UX brief | Parent: upload payment proof | Unverified — and already replaced by the Phase 1 wizard, so the original can only be observed from `git HEAD`. |
| B10 | Registering a **new** parent requires an email address (an existing rule); parents without an email may stall the receptionist at the parent-details step. | Code read (`StudentController@store` validation) + Phase 3 | Receptionist: link a guardian | Unverified. Not changed — it is a business rule. |
| B11 | The rebuilt six-step registration wizard may feel *longer* than the old four-step one even though each step is simpler. | Phase 3 redesign | Receptionist: register a student | Unverified. The old wizard is at `git HEAD` for comparison. |
| B12 | "Find a student" and "Print an ID card" both open the students list; a receptionist who only wants the card may not realise the *ID card* button on each result is the way. | Phase 2/3 tile design | Receptionist: print the ID | Unverified. |

| B13 | Attendance now starts with every student *unmarked* instead of pre-set to present. A teacher used to the old screen may expect them to be present already, or may not realise unmarked students are saved as nothing. | Phase 4 redesign | Teacher: take attendance | Unverified. The old behaviour is at `git HEAD` for comparison. |
| B14 | The "Check before you save" box (shown only when someone is marked absent, a saved mark changes, or students are unmarked) may feel like an obstacle to a teacher in a hurry, or its wording about parents may not be understood. | Phase 4 redesign | Teacher: take attendance; correct an absence | Unverified. |
| B15 | "Mark everyone present" now marks only the unmarked students; a teacher who wants to reset a whole class to present after mistakes has to tap them individually. | Phase 4 redesign | Teacher: correct an absence | Unverified. |

| B16 | One student at a time may feel slower than the old table for a teacher who already has the marks on paper in class-list order and wants to type them down a column. | Phase 5 redesign | Teacher: enter marks | Unverified. The old table is at `git HEAD` for comparison; "All students" is the overview, not a fast-entry grid. |
| B17 | "Save & Next" with an empty box saves that student as having no mark; a teacher may do this by accident and not notice until the finish screen. | Phase 5 redesign | Teacher: enter marks | Unverified. |
| B18 | Submitting is now a separate button with a consequence box; a teacher who used to press "Save & Submit for Approval" once may not find it, or may be unsure what "missing mark counts as 0/left out" means. | Phase 5 redesign | Teacher: submit marks | Unverified. |
| B19 | Marks are entered per exam in a list by class and subject; a teacher may not understand that each exam (Mid-term, Final) is a separate sheet. | Code read (`Marks/Index.jsx`) | Teacher: enter marks | Unverified. |

| B20 | The accountant now decides on a payment's own page instead of from the list; someone used to Verify/Reject buttons on every list row may not realise they must open the payment first. | Phase 6 redesign | Accountant: verify a payment | Unverified. The old list is at `git HEAD`. |
| B21 | "Payment Received" is the accept action, but staff still cannot *record* a payment themselves (only a parent submits one). An accountant taking cash at the desk may still look for "record a payment" (see B5). | Code read + Phase 6 | Accountant: record a partial payment | Unverified. **Changed 2026-09-29:** the desk path now exists (Help a parent > Fees > Record payment received). |
| B22 | The "Look twice: this may be a repeat" warning may be ignored, or may worry the accountant when the same reference legitimately covers two invoices. | Phase 6 redesign | Accountant: verify a payment | Unverified. |
| B23 | Refunds are offered on the payment's page for those holding `refund-payments`, but an accountant without that permission will find no refund control anywhere and may not know who to ask. | Phase 6 redesign | Accountant: refund | Unverified. |

| B24 | Bills are created per grade (all classes together); an accountant who wants to bill one class may look for a class choice that does not exist. | Phase 7 (a backend rule) | Accountant: create bills | Unverified. Not changed. |
| B25 | "Bill the whole year now" tells parents about every period today, including future terms; an accountant may not realise this until the warning on the last step. | Phase 7 | Accountant: create bills | Unverified. |
| B26 | The accountant may not understand why a student is "skipped" (already billed / left the school / nothing to bill) or fear a skipped student was forgotten. | Phase 7 | Accountant: create bills | Unverified. |

| B27 | Choosing a school year comes before the grade; an accountant may not realise fees belong to a school year, or may add next year's fee to the current year. | Phase 8 | Accountant: add a fee | Unverified. |
| B28 | A fee cannot move to another grade or year (only name, amount and frequency change); someone who added it to the wrong grade may not understand they must remove and re-add it. | Phase 8 (a backend rule) | Accountant: change a fee | Unverified. Not changed. |
| B29 | The new "already has a fee called ..." refusal may surprise someone who wants two fees with one name (e.g. two "Tuition" lines for different frequencies). | Phase 8 (new rule) | Accountant: add a fee | Unverified. |

| B30 | A parent with two children may not notice that the page shows one child at a time and that the buttons at the top switch it. | Phase 9 | Parent: check a child's fees | Unverified. |
| B31 | Some parents may not understand "Waiting for the school" after sending a proof (that the school checks it, and they will get a message). | Phase 9 | Parent: upload payment proof | Unverified. |
| B32 | Parents may still expect to pay inside the app; the app only shows accounts and takes a proof photo. | Phase 9 (a business rule) | Parent: view fees | Unverified. Not changed. |

| B33 | Administrators may still read the job title as what gives access (Registrar = can enrol students). The wizard now says otherwise twice, but whether that is believed is untested. | Phase 10 | Admin: add a staff member | Unverified. |
| B34 | The preselected "usual set" for a branch admin may be mistaken for the only right answer; whether 20 permissions is a sensible default for every job title is a school policy question. | Phase 10 | Admin: set permissions | Unverified. |
| B35 | An admin adding a teacher or accountant may look for a permission checklist and not find one (those roles are fixed by design). | Phase 10 (a business rule) | Admin: add a teacher | Unverified. Not changed. |
| B36 | Admins may look for "which teacher has this class" on the Grades and sections page and for "create a class" on the teaching page; both now link to each other. | Phase 10 | Admin: assign teachers | Unverified. |
| B37 | Audit entries still show recorded values as recorded (for example `branch_admin`); an auditor may find that too technical, or may prefer it. | Phase 10 | Auditor: read the log | Unverified. |
| B38 | Settings has one Save for the school's own settings and a separate Add for payment accounts; an owner may expect one Save for the whole page. | Phase 10 | Owner: change settings | Unverified. |
| B39 | Owners may not understand permissions such as "Go over a section's limit" or "Approve large discounts" well enough from the one-line description to decide. | Phase 10 | Owner: set permissions | Unverified. |

| B40 | In the promotion wizard a person may not expect to have to choose for every student (they used to start all ticked); "Everyone moves up" is one tap, but it is a change of habit. | Phase 11 | Office: move a class up | Unverified. |
| B41 | People may not realise that "Not this time" leaves a student in their old class rather than removing them. | Phase 11 | Office: move a class up | Unverified. |
| B42 | Announcement writers may expect "Everyone" to be the default and be slowed by having to choose; the choice is deliberate because it reaches many people. | Phase 11 | Admin: write an announcement | Unverified. |
| B43 | An owner may not understand "Transport" versus "Something else" for a service (the transport rule is one route per student). | Phase 11 | Admin: add a service | Unverified. |
| B44 | Staff may not find the student's details now that they are three folded groups instead of one; the summaries under each title are meant to say what is inside. | Phase 11 | Office: correct a student's address | Unverified. |
| B45 | The status change dialog explains leaving Active; whether "Inactive" vs "Suspended" vs "Expelled" is understood is untested. | Phase 11 | Office: change a status | Unverified. |
| B46 | People may look for refund on the bill page and be surprised it opens the payment's own page. | Phase 11 | Accountant: refund | Unverified. |

**Checked against the code and ruled out** (recorded so nobody re-raises them): a payment larger than the invoice balance can be verified by mistake (the Verify button is disabled and the server re-checks under a row lock); a teacher cannot see their mark sheet's approval status (the "Enter Marks" page shows a status badge per sheet). See `USABILITY_TESTING.md`, appendix.

**Not a defect, for the record:** while preparing this phase an automated click on a parent's report-card link appeared to do nothing; on inspection clicks and taps navigate correctly on desktop and phone (the script's selector was wrong). It is not a finding and not a hypothesis.
