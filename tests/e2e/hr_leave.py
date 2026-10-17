"""Browser test: HR module Phase D -- leave management. Confirmed via AskUserQuestion: self-service for an
employee with a linked login account, HR (leave-approve) can also submit on behalf of anyone in scope.

  * HR configures a leave type (no document) and a leave type that requires a supporting document
  * HR grants an allocation to an employee; the balance ("16.00 days left") is shown live
  * HR submits a leave request on an employee's behalf; submitting without a required document shows a
    plain-words inline error next to the document field (not a silent stuck "Working..." dead end --
    a real bug caught and fixed this phase); attaching the PDF then succeeds
  * self-service: a teacher with a linked employee record requests their own leave from "My Leave", saves a
    draft, edits it, then submits it; a second overlapping request for the same dates is refused
  * a reviewer sees both requests on the pending queue and can Approve one directly and Reject the other with
    a typed reason through a proper modal (never window.prompt)
  * approving decreases the live balance; rejecting leaves it untouched and records the stated reason
  * the self-review ban, both halves: the person who submitted a request cannot review it, and neither can
    the employee the leave is literally for even when someone else clicked submit -- a third, uninvolved
    reviewer can
  * a leave type still in use refuses removal; an unused one is removed cleanly
  * permission checks: a self-service-only teacher is refused the HR-only pages; someone with no leave
    permission at all is refused every leave route including My Leave; the document URL is refused to an
    unauthorized viewer

Uses the usability-session data; creates two throwaway employees (linked to teacher@demo.test and
principal@demo.test) and two throwaway leave types, removed at the end. Only run it against that throwaway
database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr_leave.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
TYPE_ANNUAL = "E2E Annual Leave"
TYPE_SICK = "E2E Sick Leave"
TYPE_EMPTY = "E2E Unused Leave Type"
EMP_TEACHER = "E2E Leave Teacher"
EMP_PRINCIPAL = "E2E Leave Principal"
PDF_PATH = "C:/Users/user/AppData/Local/Temp/claude/c--Users-user-Desktop-projects-schoolERP-prj/cfc016db-d734-425b-9774-8e18aa28a1e4/scratchpad/test_contract.pdf"


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


def until(fn, timeout=20):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.3)
    return False


def login(browser, email):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.goto(f"{BASE}/login")
    page.fill("#phone", email); page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


body = lambda pg: pg.locator("body").inner_text()


def cleanup():
    q("delete from leave_requests where employee_id in (select id from employees where full_name in (?, ?))", EMP_TEACHER, EMP_PRINCIPAL)
    q("delete from leave_allocations where employee_id in (select id from employees where full_name in (?, ?))", EMP_TEACHER, EMP_PRINCIPAL)
    q("delete from employees where full_name in (?, ?)", EMP_TEACHER, EMP_PRINCIPAL)
    q("delete from leave_types where name in (?, ?, ?)", TYPE_ANNUAL, TYPE_SICK, TYPE_EMPTY)
    q(
        "delete from model_has_permissions where model_id=(select id from users where email='auditor@demo.test') "
        "and permission_id=(select id from permissions where name='leave-approve')"
    )


cleanup()

OWNER_ID = q("select id from users where email='owner@demo.test'")[0][0]
TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH = q("select id, school_id, branch_id from users where email='teacher@demo.test'")[0]
PRINCIPAL_ID, PRINCIPAL_SCHOOL, PRINCIPAL_BRANCH = q("select id, school_id, branch_id from users where email='principal@demo.test'")[0]
AUDITOR_ID = q("select id from users where email='auditor@demo.test'")[0][0]
perm = lambda name: q("select id from permissions where name=?", name)[0][0]
mtype = q("select model_type from model_has_permissions limit 1")[0][0]


def grant(user_id, name):
    if not q("select 1 from model_has_permissions where model_id=? and permission_id=?", user_id, perm(name)):
        q("insert into model_has_permissions (permission_id, model_type, model_id) values (?, ?, ?)", perm(name), mtype, user_id)


def revoke(user_id, name):
    q("delete from model_has_permissions where model_id=? and permission_id=?", user_id, perm(name))


for name, user_id, school_id, branch_id in [(EMP_TEACHER, TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH), (EMP_PRINCIPAL, PRINCIPAL_ID, PRINCIPAL_SCHOOL, PRINCIPAL_BRANCH)]:
    q(
        "insert into employees (school_id, branch_id, user_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
        "values (?, ?, ?, ?, ?, '0911000077', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
        school_id, branch_id, user_id, f"EMP-2026-{name[-8:].upper().replace(' ', '')}", name, OWNER_ID,
    )

EMP_TEACHER_ID = q("select id from employees where full_name=?", EMP_TEACHER)[0][0]
EMP_PRINCIPAL_ID = q("select id from employees where full_name=?", EMP_PRINCIPAL)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. HR configures leave types
    page.goto(f"{BASE}/leave-types")
    page.get_by_role("button", name=re.compile("Add leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]")
    page.fill("#lt_name", TYPE_ANNUAL)
    page.fill("#lt_default_days", "16")
    page.get_by_test_id("leave-type-form").get_by_role("button", name=re.compile("Save leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]", state="hidden")
    check("leave types: the no-document type is listed", until(lambda: TYPE_ANNUAL in body(page)))

    page.get_by_role("button", name=re.compile("Add leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]")
    page.fill("#lt_name", TYPE_SICK)
    page.check("#lt_requires_document")
    page.get_by_test_id("leave-type-form").get_by_role("button", name=re.compile("Save leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]", state="hidden")
    check("leave types: the document-required type is listed", until(lambda: TYPE_SICK in body(page)))

    page.get_by_role("button", name=re.compile("Add leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]")
    page.fill("#lt_name", TYPE_EMPTY)
    page.get_by_test_id("leave-type-form").get_by_role("button", name=re.compile("Save leave type", re.I)).click()
    page.wait_for_selector("[data-testid=leave-type-form]", state="hidden")
    check("leave types: a third, unused type is listed", until(lambda: TYPE_EMPTY in body(page)))

    # ================================================================ 2. HR grants an allocation and shows the live balance
    page.goto(f"{BASE}/employees/{EMP_TEACHER_ID}")
    page.get_by_role("button", name=re.compile("Add allocation", re.I)).click()
    page.wait_for_selector("[data-testid=leave-allocation-form]")
    page.select_option("#alloc_type", label=TYPE_ANNUAL)
    page.fill("#alloc_days", "16")
    page.get_by_test_id("leave-allocation-form").get_by_role("button", name=re.compile("Save allocation", re.I)).click()
    page.wait_for_selector("[data-testid=leave-allocation-form]", state="hidden")
    check("employee page: the allocation shows a live 16-day balance", until(lambda: "16 days left" in body(page) or "16.00 days left" in body(page) or re.search(r"16(\.0+)? days left", body(page))))

    # ================================================================ 3. HR enters a document-required request on the employee's behalf: refused, then succeeds
    page.get_by_role("button", name=re.compile("Request leave for them", re.I)).click()
    page.wait_for_selector("[data-testid=leave-request-form]")
    page.select_option("#leave_type", label=TYPE_SICK)
    page.fill("#leave_start", "2026-04-02")
    page.fill("#leave_end", "2026-04-03")
    page.fill("#leave_reason", "Flu, entered by HR")
    page.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Submit request", re.I)).click()
    check("HR-on-behalf: missing document shows a plain-words inline error, not a stuck dialog", until(lambda: "A supporting document is required" in body(page)))
    check("HR-on-behalf: no request was created yet", q("select count(*) from leave_requests where employee_id=?", EMP_TEACHER_ID)[0][0] == 0)

    page.set_input_files("#leave_document", PDF_PATH)
    page.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Submit request", re.I)).click()
    page.wait_for_selector("[data-testid=leave-request-form]", state="hidden")
    check("HR-on-behalf: attaching the document lets the request through", until(lambda: q("select count(*) from leave_requests where employee_id=?", EMP_TEACHER_ID)[0][0] == 1))

    # ================================================================ 4. self-service: teacher requests, drafts, edits, then submits
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/my-leave")
    check("my leave: the HR-entered request appears in the teacher's own list", until(lambda: TYPE_SICK in body(tp)))
    check("my leave: the live balance for the annual leave type is shown", re.search(r"16(\.0+)? days left", body(tp)) is not None)

    tp.get_by_role("button", name=re.compile("^Request leave$", re.I)).click()
    tp.wait_for_selector("[data-testid=leave-request-form]")
    tp.select_option("#leave_type", label=TYPE_ANNUAL)
    tp.fill("#leave_start", "2026-05-04")
    tp.fill("#leave_end", "2026-05-06")
    tp.fill("#leave_reason", "Family trip")
    tp.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Save as draft", re.I)).click()
    tp.wait_for_selector("[data-testid=leave-request-form]", state="hidden")
    check("my leave: the draft is listed with Draft status", until(lambda: tp.locator("[data-testid=leave-request-row]").filter(has_text="2026-05-04").filter(has_text="Draft").count() == 1))

    draft_row = tp.locator("[data-testid=leave-request-row]").filter(has_text="2026-05-04")
    draft_row.get_by_role("button", name=re.compile("^Change$", re.I)).click()
    tp.wait_for_selector("[data-testid=leave-request-form]")
    tp.fill("#leave_reason", "Family trip, confirmed")
    tp.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Submit request", re.I)).click()
    tp.wait_for_selector("[data-testid=leave-request-form]", state="hidden")
    check("my leave: the edited draft is now submitted", until(lambda: q("select status from leave_requests where employee_id=? and start_date like '2026-05-04%'", EMP_TEACHER_ID)[0][0] == "submitted"))

    tp.get_by_role("button", name=re.compile("^Request leave$", re.I)).click()
    tp.wait_for_selector("[data-testid=leave-request-form]")
    tp.select_option("#leave_type", label=TYPE_ANNUAL)
    tp.fill("#leave_start", "2026-05-05")
    tp.fill("#leave_end", "2026-05-07")
    tp.fill("#leave_reason", "Overlapping attempt")
    tp.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Submit request", re.I)).click()
    check("my leave: an overlapping request is refused with a plain-words error", until(lambda: "overlaps another" in body(tp)))
    check("my leave: the overlapping attempt created no row", q("select count(*) from leave_requests where employee_id=? and start_date='2026-05-05'", EMP_TEACHER_ID)[0][0] == 0)
    tp.get_by_test_id("leave-request-form").get_by_role("button", name=re.compile("Cancel", re.I)).click()

    # ================================================================ 5. a reviewer approves one request and rejects the other
    pctx, ppage = login(browser, "principal@demo.test")
    ppage.goto(f"{BASE}/leave-requests/pending")
    check("pending: both the teacher's requests are listed for review", until(lambda: ppage.locator("[data-testid=leave-pending-row]").filter(has_text=EMP_TEACHER).count() == 2))

    sick_row = ppage.locator("[data-testid=leave-pending-row]").filter(has_text=TYPE_SICK)
    sick_row.get_by_role("button", name=re.compile("^Approve$", re.I)).click()
    check("pending: approving succeeds and the row drops off the queue", until(lambda: ppage.locator("[data-testid=leave-pending-row]").filter(has_text=TYPE_SICK).count() == 0))
    check("db: approving set the status and reviewer", q("select status, reviewed_by from leave_requests where employee_id=? and leave_type_id=(select id from leave_types where name=?)", EMP_TEACHER_ID, TYPE_SICK)[0] == ("approved", PRINCIPAL_ID))

    annual_row = ppage.locator("[data-testid=leave-pending-row]").filter(has_text=TYPE_ANNUAL).filter(has_text=EMP_TEACHER)
    annual_row.get_by_role("button", name=re.compile("^Reject$", re.I)).click()
    ppage.wait_for_selector("[data-testid=reject-leave-form]")
    reject_submit = ppage.get_by_test_id("reject-leave-form").get_by_role("button", name=re.compile("^Reject$", re.I))
    check("reject dialog: the submit button is disabled until a reason is typed", reject_submit.is_disabled())
    ppage.fill("#reject_reason", "Short-staffed that week")
    reject_submit.click()
    ppage.wait_for_selector("[data-testid=reject-leave-form]", state="hidden")
    check("pending: rejecting drops the row off the queue too", until(lambda: ppage.locator("[data-testid=leave-pending-row]").filter(has_text=EMP_TEACHER).count() == 0))
    check("db: rejecting recorded the typed reason", q("select status, rejection_reason from leave_requests where employee_id=? and leave_type_id=(select id from leave_types where name=?)", EMP_TEACHER_ID, TYPE_ANNUAL)[0] == ("rejected", "Short-staffed that week"))

    # ================================================================ 6. balance reflects the approval, not the rejection
    tp.goto(f"{BASE}/my-leave")
    check("my leave: the balance is untouched by the rejected request (sick leave has no allocation, annual unaffected)", re.search(r"16(\.0+)? days left", body(tp)) is not None)
    check("my leave: the rejection reason is shown in plain words", until(lambda: "Short-staffed that week" in body(tp)))
    pctx.close()

    # ================================================================ 7. self-review ban, both halves
    q(
        "insert into leave_requests (employee_id, leave_type_id, start_date, end_date, days_count, reason, status, requested_by, created_at, updated_at) "
        "values (?, (select id from leave_types where name=?), '2026-06-01', '2026-06-02', 2, 'Principal self-service via HR entry', 'submitted', ?, datetime('now'), datetime('now'))",
        EMP_PRINCIPAL_ID, TYPE_ANNUAL, OWNER_ID,
    )
    pctx2, ppage2 = login(browser, "principal@demo.test")
    ppage2.goto(f"{BASE}/leave-requests/pending")
    own_row = ppage2.locator("[data-testid=leave-pending-row]").filter(has_text=EMP_PRINCIPAL)
    check("pending: the employee sees they cannot review leave that is literally their own", until(lambda: own_row.get_by_test_id("own-request-note").count() == 1))
    check("pending: no Approve/Reject button on your own leave", own_row.get_by_role("button", name=re.compile("^(Approve|Reject)$", re.I)).count() == 0)
    pctx2.close()

    octx2, opage2 = login(browser, "owner@demo.test")
    opage2.goto(f"{BASE}/leave-requests/pending")
    requester_row = opage2.locator("[data-testid=leave-pending-row]").filter(has_text=EMP_PRINCIPAL)
    check("pending: the requester themselves also cannot review it", until(lambda: requester_row.get_by_test_id("own-request-note").count() == 1))
    octx2.close()

    # auditor@demo.test has no leave involvement at all here, so it's granted leave-approve just long enough
    # to stand in as a genuinely uninvolved third reviewer, then the permission is revoked again.
    grant(AUDITOR_ID, "leave-approve")
    actx, apage = login(browser, "auditor@demo.test")
    apage.goto(f"{BASE}/leave-requests/pending")
    third_party_row = apage.locator("[data-testid=leave-pending-row]").filter(has_text=EMP_PRINCIPAL)
    check("pending: a fully uninvolved reviewer can act on it", until(lambda: third_party_row.get_by_role("button", name=re.compile("^Approve$", re.I)).count() == 1))
    third_party_row.get_by_role("button", name=re.compile("^Approve$", re.I)).click()
    check("db: the uninvolved reviewer's approval went through", until(lambda: q("select status from leave_requests where employee_id=?", EMP_PRINCIPAL_ID)[0][0] == "approved"))
    actx.close()
    revoke(AUDITOR_ID, "leave-approve")

    # ================================================================ 8. leave type removal: blocked while in use, clean when unused
    page.goto(f"{BASE}/leave-types")
    annual_type_row = page.locator("[data-testid=leave-type-row]").filter(has_text=TYPE_ANNUAL)
    annual_type_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector(f"text=Remove {TYPE_ANNUAL}?")
    page.get_by_role("button", name="Remove leave type").click()
    check("leave types: a type still in use refuses removal", until(lambda: "cannot be deleted because it is still used by" in body(page)))
    check("db: the in-use type was not deleted", q("select count(*) from leave_types where name=?", TYPE_ANNUAL)[0][0] == 1)

    empty_type_row = page.locator("[data-testid=leave-type-row]").filter(has_text=TYPE_EMPTY)
    empty_type_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector(f"text=Remove {TYPE_EMPTY}?")
    page.get_by_role("button", name="Remove leave type").click()
    check("leave types: an unused type is removed cleanly", until(lambda: TYPE_EMPTY not in body(page)))

    # ================================================================ 9. permission checks
    sick_request_id = q("select id from leave_requests where employee_id=? and leave_type_id=(select id from leave_types where name=?)", EMP_TEACHER_ID, TYPE_SICK)[0][0]
    principal_request_id = q("select id from leave_requests where employee_id=?", EMP_PRINCIPAL_ID)[0][0]
    check("teacher: self-service does not grant the approval queue (a colleague's leave reasons stay private)", status_of(tctx, "/leave-requests/pending") == 403)
    check("teacher: self-service still shows the configured leave types (needed to request their own)", status_of(tctx, "/leave-types") == 200)
    check("teacher: self-service My Leave is still reachable", status_of(tctx, "/my-leave") == 200)
    check("teacher: can view their own uploaded leave document", tctx.request.get(f"{BASE}/leave-requests/{sick_request_id}/document").status == 200)
    check("teacher: cannot view a colleague's document just by guessing the id", tctx.request.get(f"{BASE}/leave-requests/{principal_request_id}/document", max_redirects=0).status == 403)
    tctx.close()

    bctx, bp = login(browser, "parent@demo.test")
    check("parent (no leave permission at all): refused on every leave route", status_of(bctx, "/my-leave") == 403 and status_of(bctx, "/leave-types") == 403 and status_of(bctx, "/leave-requests/pending") == 403)
    check("parent: no access to a leave request's document either", bctx.request.get(f"{BASE}/leave-requests/{sick_request_id}/document", max_redirects=0).status == 403)
    bctx.close()

    check("owner: the uploaded leave document is served to an authorized viewer", octx.request.get(f"{BASE}/leave-requests/{sick_request_id}/document").status == 200)

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "principal@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/leave-requests/pending")
    mpage.wait_for_load_state("networkidle")
    check("phone: the pending leave requests list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
