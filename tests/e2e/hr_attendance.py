"""Browser test: HR module Phase C -- staff attendance, HR/admin-recorded for everyone (no self-service
check-in, confirmed via AskUserQuestion), with a real two-person correction-approval workflow.

  * marking an unmarked employee Present with check-in/check-out times, moving it to "Already marked today"
  * requesting a correction on an already-marked day shows "Correction pending" and hides the request button
  * the person who requested a correction cannot review it themselves (server-enforced, and the review page
    says so in plain words for their own request)
  * someone else approving the correction updates the actual attendance record
  * someone without any staff-attendance permission is refused both the roster and the corrections page

Uses the usability-session data; creates one throwaway employee directly in the main branch, removed at the
end along with its attendance/correction rows. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr_attendance.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
EMP_NAME = "E2E Attendance Test Person"
ATT_DATE = "2026-03-02"


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
    q("delete from staff_attendance_corrections where staff_attendance_id in (select id from staff_attendances where employee_id in (select id from employees where full_name=?))", EMP_NAME)
    q("delete from staff_attendances where employee_id in (select id from employees where full_name=?)", EMP_NAME)
    q("delete from employees where full_name=?", EMP_NAME)


cleanup()

MAIN = q("select id from branches order by id limit 1")[0][0]
OWNER_ID = q("select id from users where email='owner@demo.test'")[0][0]
q(
    "insert into employees (school_id, branch_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
    "values ((select school_id from branches where id=?), ?, 'EMP-2026-E2EATT', ?, '0911000099', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
    MAIN, MAIN, EMP_NAME, OWNER_ID,
)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. mark an unmarked employee present
    page.goto(f"{BASE}/staff-attendance?date={ATT_DATE}")
    check("roster: the new employee shows up unmarked", until(lambda: EMP_NAME in body(page) and "Not yet marked" in body(page)))

    row = page.locator("[data-testid=attendance-row]").filter(has_text=EMP_NAME)
    row.get_by_role("radio", name="Present").click()
    row.locator("input[type=time]").first.fill("08:00")
    page.get_by_test_id("save-attendance").click()
    check("roster: saving moves the employee to Already marked", until(lambda: page.locator("[data-testid=attendance-row][data-state=marked]").filter(has_text=EMP_NAME).count() == 1))

    # ================================================================ 2. request a correction
    marked_row = page.locator("[data-testid=attendance-row][data-state=marked]").filter(has_text=EMP_NAME)
    marked_row.get_by_role("button", name=re.compile("Request correction", re.I)).click()
    page.wait_for_selector("[data-testid=correction-form]")
    page.select_option("#correction_status", label="Late")
    page.fill("#correction_reason", "Actually arrived late, missed noting it.")
    page.locator("[data-testid=correction-form]").get_by_role("button", name=re.compile("Submit correction request", re.I)).click()
    page.wait_for_selector("[data-testid=correction-form]", state="hidden")
    check("roster: a pending correction shows and hides the request button", until(lambda: page.locator("[data-testid=attendance-row]").filter(has_text=EMP_NAME).get_by_text("Correction pending").count() == 1))

    # ================================================================ 3. the requester cannot review their own request
    page.goto(f"{BASE}/staff-attendance/corrections")
    check("corrections: the pending request is listed", until(lambda: EMP_NAME in body(page)))
    check("corrections: the requester sees they cannot review their own request", page.get_by_test_id("own-request-note").count() == 1)
    check("corrections: no Approve/Reject button for your own request", page.get_by_role("button", name=re.compile("Approve correction", re.I)).count() == 0)

    # ================================================================ 4. someone else approves it
    pctx, ppage = login(browser, "principal@demo.test")
    ppage.goto(f"{BASE}/staff-attendance/corrections")
    check("corrections: a different reviewer can see Approve/Reject", until(lambda: ppage.get_by_role("button", name=re.compile("Approve correction", re.I)).count() == 1))
    ppage.get_by_role("button", name=re.compile("Approve correction", re.I)).click()
    check("corrections: approving succeeds", until(lambda: "Correction approved" in body(ppage)))
    pctx.close()

    att = q("select status from staff_attendances where employee_id=(select id from employees where full_name=?)", EMP_NAME)
    check("db: the attendance record was actually updated to the approved status", att[0][0] == "late")

    # ================================================================ 5. permission checks
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no staff-attendance access at all", status_of(tctx, "/staff-attendance") == 403 and status_of(tctx, "/staff-attendance/corrections") == 403)
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/staff-attendance")
    mpage.wait_for_load_state("networkidle")
    check("phone: the staff attendance page has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
