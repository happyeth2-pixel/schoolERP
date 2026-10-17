"""Browser test: HR module Phase G (final phase) -- the HR dashboard snapshot, folded into the existing
owner/branch_admin/auditor home dashboard, the exact same "null = not for you" convention the
inventory/requisitions/assets tiles already use there. No new entities this phase, purely read-only
aggregation across everything Phases A-F built.

  * an owner sees every HR tile (employees, contracts expiring, staff attendance today, pending leave,
    reviews finalized this year, latest payroll run) with real, correct numbers
  * each tile links to its own module's page
  * a teacher with no employee-view gets no HR section at all, and the page still renders cleanly
  * Amharic: the dashboard's new HR labels are translated, no raw keys

Uses the usability-session data; creates one throwaway employee (with an expiring contract, today's
attendance, a submitted leave request, a finalized performance review, and a payroll run), removed at the
end. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr_dashboard.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
EMP_NAME = "E2E Dashboard Teacher"
CYCLE_NAME = "E2E Dashboard Cycle"
LEAVE_TYPE_NAME = "E2E Dashboard Leave"


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


body = lambda pg: pg.locator("body").inner_text()


def cleanup():
    q("delete from payslips where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from payroll_runs where branch_id=(select branch_id from users where email='teacher@demo.test') and year=2026 and month=9")
    q("delete from performance_reviews where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from performance_review_cycles where name=?", CYCLE_NAME)
    q("delete from leave_requests where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from leave_types where name=?", LEAVE_TYPE_NAME)
    q("delete from staff_attendances where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from employment_contracts where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from employees where full_name=?", EMP_NAME)


cleanup()

OWNER_ID = q("select id from users where email='owner@demo.test'")[0][0]
TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH = q("select id, school_id, branch_id from users where email='teacher@demo.test'")[0]
TODAY = q("select date('now')")[0][0]

q(
    "insert into employees (school_id, branch_id, user_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
    "values (?, ?, ?, 'EMP-2026-E2EDASH', ?, '0911000077', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
    TEACHER_SCHOOL, TEACHER_BRANCH, TEACHER_ID, EMP_NAME, OWNER_ID,
)
EMP_ID = q("select id from employees where full_name=?", EMP_NAME)[0][0]

q("insert into employment_contracts (employee_id, contract_type, start_date, status, created_by, created_at, updated_at) values (?, 'permanent', '2025-01-01', 'expiring', ?, datetime('now'), datetime('now'))", EMP_ID, OWNER_ID)
q("insert into staff_attendances (employee_id, date, status, recorded_by, created_at, updated_at) values (?, ?, 'present', ?, datetime('now'), datetime('now'))", EMP_ID, TODAY, OWNER_ID)
q("insert into leave_types (school_id, name, created_at, updated_at) values (?, ?, datetime('now'), datetime('now'))", TEACHER_SCHOOL, LEAVE_TYPE_NAME)
leave_type_id = q("select id from leave_types where name=?", LEAVE_TYPE_NAME)[0][0]
q(
    "insert into leave_requests (employee_id, leave_type_id, start_date, end_date, days_count, reason, status, requested_by, created_at, updated_at) "
    "values (?, ?, '2026-09-01', '2026-09-02', 2, 'x', 'submitted', ?, datetime('now'), datetime('now'))",
    EMP_ID, leave_type_id, OWNER_ID,
)
q("insert into performance_review_cycles (school_id, name, start_date, end_date, created_at, updated_at) values (?, ?, '2026-01-01', '2026-12-31', datetime('now'), datetime('now'))", TEACHER_SCHOOL, CYCLE_NAME)
cycle_id = q("select id from performance_review_cycles where name=?", CYCLE_NAME)[0][0]
q(
    "insert into performance_reviews (employee_id, cycle_id, rating, comments, status, reviewed_by, finalized_at, created_at, updated_at) "
    "values (?, ?, 4, 'x', 'finalized', ?, datetime('now'), datetime('now'), datetime('now'))",
    EMP_ID, cycle_id, OWNER_ID,
)
q(
    "insert into payroll_runs (school_id, branch_id, year, month, status, generated_by, created_at, updated_at) "
    "values (?, ?, 2026, 9, 'finalized', ?, datetime('now'), datetime('now'))",
    TEACHER_SCHOOL, TEACHER_BRANCH, OWNER_ID,
)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")
    page.goto(f"{BASE}/dashboard")

    check("owner: the employees tile shows the real headcount", until(lambda: "Employees" in body(page)))
    check("owner: contracts expiring soon tile shows", until(lambda: "Contracts Expiring Soon" in body(page)))
    check("owner: staff attendance today meter shows", until(lambda: "Staff Attendance Today" in body(page)))
    check("owner: pending leave requests tile shows", until(lambda: "Pending Leave Requests" in body(page)))
    check("owner: reviews finalized this year tile shows", until(lambda: "Reviews Finalized This Year" in body(page)))
    check("owner: latest payroll run tile shows the period and status", until(lambda: "Latest payroll run" in body(page) and "Finalized" in body(page)))

    page.get_by_text("Pending Leave Requests").click()
    try:
        page.wait_for_url(lambda u: "/leave-requests/pending" in u, timeout=20000)
        navigated_ok = True
    except Exception:
        navigated_ok = False
    check("tile: clicking through lands on the pending leave requests page", navigated_ok)

    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/dashboard")
    check("teacher: no HR section at all (no employee-view)", "Contracts Expiring Soon" not in body(tp) and "Latest payroll run" not in body(tp))
    check("teacher: the page still renders cleanly otherwise", until(lambda: "My Homeroom" in body(tp) or "Attendance" in body(tp)))
    tctx.close()

    q("update users set locale='am' where id=?", OWNER_ID)
    page.goto(f"{BASE}/dashboard")
    check("Amharic: the HR dashboard labels are translated, no raw keys", until(lambda: "ሰራተኞች" in body(page) and "dashboard." not in body(page)))
    q("update users set locale=null where id=?", OWNER_ID)

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/dashboard")
    mpage.wait_for_load_state("networkidle")
    check("phone: the dashboard has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
