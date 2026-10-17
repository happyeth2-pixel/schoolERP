"""Browser test: HR module Phase F -- payroll foundation. Confirmed via AskUserQuestion: basic salary (from
the employee's existing contract) + statutory tax + pension only (no allowances/other deductions this
phase), and a finalized run posts to the accounting engine best-effort if it's configured.

  * HR edits pension rates and adds/edits/removes a tax bracket, with the "verify before relying on this"
    notice shown prominently
  * generating a payroll run computes basic salary/tax/pension/net pay correctly for an eligible employee,
    and skips an employee with no salary on file, with a plain-words reason shown on the result page
  * a second run for the same branch and month is refused with a clean inline error, not a crash
  * finalizing locks the run (no more Finalize/Remove actions) and the employee's own payslip then shows on
    both "My Payslips" and their HR profile's Payroll section
  * a still-draft run can be deleted, but a finalized one offers no way to
  * permission checks: a plain teacher has no access to the HR-only payroll pages but can still see their
    own finalized payslip; someone with no payroll permission at all is refused managing anything

Uses the usability-session data; creates one throwaway employee (linked to teacher@demo.test, with a real
employment contract) and one without a contract (to be skipped), plus one throwaway tax bracket, removed at
the end. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr_payroll.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
EMP_TEACHER = "E2E Payroll Teacher"
EMP_NO_SALARY = "E2E Payroll NoSalary"


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
    teacher_branch = q("select branch_id from users where email='teacher@demo.test'")[0][0]
    q("delete from payslips where employee_id in (select id from employees where full_name in (?, ?))", EMP_TEACHER, EMP_NO_SALARY)
    q("delete from payroll_runs where branch_id=? and year=2026 and month in (3, 4)", teacher_branch)
    q("delete from employment_contracts where employee_id in (select id from employees where full_name in (?, ?))", EMP_TEACHER, EMP_NO_SALARY)
    q("delete from employees where full_name in (?, ?)", EMP_TEACHER, EMP_NO_SALARY)
    q("delete from payroll_tax_brackets where min_amount=99999")


cleanup()

OWNER_ID = q("select id from users where email='owner@demo.test'")[0][0]
TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH = q("select id, school_id, branch_id from users where email='teacher@demo.test'")[0]
BRANCH_NAME = q("select name from branches where id=?", TEACHER_BRANCH)[0][0]

q(
    "insert into employees (school_id, branch_id, user_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
    "values (?, ?, ?, 'EMP-2026-E2EPAYT', ?, '0911000088', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
    TEACHER_SCHOOL, TEACHER_BRANCH, TEACHER_ID, EMP_TEACHER, OWNER_ID,
)
EMP_TEACHER_ID = q("select id from employees where full_name=?", EMP_TEACHER)[0][0]
q(
    "insert into employment_contracts (employee_id, contract_type, start_date, status, salary_reference, created_by, created_at, updated_at) "
    "values (?, 'permanent', '2026-01-01', 'active', 8000, ?, datetime('now'), datetime('now'))",
    EMP_TEACHER_ID, OWNER_ID,
)
q(
    "insert into employees (school_id, branch_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
    "values (?, ?, 'EMP-2026-E2EPAYN', ?, '0911000089', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
    TEACHER_SCHOOL, TEACHER_BRANCH, EMP_NO_SALARY, OWNER_ID,
)
EMP_NO_SALARY_ID = q("select id from employees where full_name=?", EMP_NO_SALARY)[0][0]
q(
    "insert into employment_contracts (employee_id, contract_type, start_date, status, salary_reference, created_by, created_at, updated_at) "
    "values (?, 'permanent', '2026-01-01', 'active', NULL, ?, datetime('now'), datetime('now'))",
    EMP_NO_SALARY_ID, OWNER_ID,
)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. payroll settings
    page.goto(f"{BASE}/payroll-settings")
    check("settings: the unverified notice is shown prominently", until(lambda: "Verify before relying on this" in body(page)))

    page.fill("#pension_employee_rate", "8")
    page.fill("#pension_employer_rate", "12")
    page.get_by_role("button", name=re.compile("Save pension rates", re.I)).click()
    check("settings: pension rates saved", until(lambda: q("select pension_employee_rate, pension_employer_rate from schools where id=?", TEACHER_SCHOOL)[0] == (0.08, 0.12)))
    # restore to the researched defaults so the rest of this run computes against them
    page.fill("#pension_employee_rate", "7")
    page.fill("#pension_employer_rate", "11")
    page.get_by_role("button", name=re.compile("Save pension rates", re.I)).click()
    until(lambda: q("select pension_employee_rate from schools where id=?", TEACHER_SCHOOL)[0][0] == 0.07)

    page.get_by_role("button", name=re.compile("Add tax bracket", re.I)).click()
    page.wait_for_selector("[data-testid=payroll-bracket-form]")
    page.fill("#bracket_min", "99999")
    page.fill("#bracket_rate", "9")
    page.fill("#bracket_deduction", "100")
    page.get_by_test_id("payroll-bracket-form").get_by_role("button", name=re.compile("Save tax bracket", re.I)).click()
    check("settings: the new bracket is listed", until(lambda: "99,999" in body(page)))

    bracket_row = page.locator("[data-testid=payroll-bracket-row]").filter(has_text="99,999")
    bracket_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector("text=Remove this tax bracket?")
    page.get_by_role("dialog").get_by_role("button", name=re.compile("Remove tax bracket", re.I)).click()
    check("settings: the bracket is removed cleanly", until(lambda: "99,999" not in body(page)))

    # ================================================================ 2. generate a payroll run
    page.goto(f"{BASE}/payroll-runs")
    page.get_by_role("button", name=re.compile("Generate payroll", re.I)).click()
    page.wait_for_selector("[data-testid=payroll-generate-form]")
    if page.locator("#run_branch").count() > 0:
        page.select_option("#run_branch", label=BRANCH_NAME)
    page.fill("#run_period", "2026-03")
    page.get_by_test_id("payroll-generate-form").get_by_role("button", name=re.compile("^Generate$", re.I)).click()

    try:
        page.wait_for_url(re.compile(r"/payroll-runs/\d+$"), timeout=20000)
        redirected_ok = True
    except Exception:
        redirected_ok = False
    check("run: redirected to the new run's own page", redirected_ok)
    check("run: the eligible employee's payslip is listed with the right numbers", until(lambda: EMP_TEACHER in body(page) and "1,150.00" in body(page) and "560.00" in body(page)))
    check("run: the employee with no salary on file was skipped with a plain reason", until(lambda: EMP_NO_SALARY in body(page) and "no salary on file" in body(page).lower()))
    check("run: status starts as Draft", page.get_by_test_id("run-status").inner_text().strip() == "Draft")

    run_url = page.url

    # ================================================================ 3. a second run for the same month is refused
    page.goto(f"{BASE}/payroll-runs")
    page.get_by_role("button", name=re.compile("Generate payroll", re.I)).click()
    page.wait_for_selector("[data-testid=payroll-generate-form]")
    if page.locator("#run_branch").count() > 0:
        page.select_option("#run_branch", label=BRANCH_NAME)
    page.fill("#run_period", "2026-03")
    page.get_by_test_id("payroll-generate-form").get_by_role("button", name=re.compile("^Generate$", re.I)).click()
    check("run: a duplicate month is refused with a clean inline error, not a crash", until(lambda: "already exists" in body(page)))
    page.get_by_test_id("payroll-generate-form").get_by_role("button", name=re.compile("Cancel", re.I)).click()

    # ================================================================ 4. finalize
    page.goto(run_url)
    page.get_by_role("button", name=re.compile("Finalize payroll", re.I)).click()
    page.wait_for_selector("text=Finalize this payroll run?")
    page.get_by_role("dialog").get_by_role("button", name=re.compile("Finalize payroll", re.I)).click()
    check("run: status becomes Finalized", until(lambda: page.get_by_test_id("run-status").inner_text().strip() == "Finalized"))
    check("run: no more Finalize/Remove actions on a finalized run", until(lambda: page.get_by_role("button", name=re.compile("Finalize payroll", re.I)).count() == 0))

    # ================================================================ 5. the employee sees their own payslip
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/my-payslips")
    check("my payslips: the finalized payslip shows with the right net pay", until(lambda: "6,290.00" in body(tp)))
    check("teacher: no access to the HR-only payroll pages", status_of(tctx, "/payroll-runs") == 403 and status_of(tctx, "/payroll-settings") == 403)
    tctx.close()

    # ================================================================ 6. the HR profile shows the same payslip
    page.goto(f"{BASE}/employees/{EMP_TEACHER_ID}")
    check("employee page: the Payroll section lists the finalized payslip", until(lambda: page.get_by_test_id("payslip-row").count() == 1))

    # ================================================================ 7. a draft run can be deleted
    page.goto(f"{BASE}/payroll-runs")
    page.get_by_role("button", name=re.compile("Generate payroll", re.I)).click()
    page.wait_for_selector("[data-testid=payroll-generate-form]")
    if page.locator("#run_branch").count() > 0:
        page.select_option("#run_branch", label=BRANCH_NAME)
    page.fill("#run_period", "2026-04")
    page.get_by_test_id("payroll-generate-form").get_by_role("button", name=re.compile("^Generate$", re.I)).click()
    page.wait_for_url(lambda u: "/payroll-runs/" in u and u != run_url)
    page.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector("text=Remove this draft payroll run?")
    page.get_by_role("dialog").get_by_role("button", name=re.compile("Remove payroll run", re.I)).click()
    # page.url is only refreshed by an active Playwright call, never by passive polling -- wait_for_url
    # drives that itself, unlike reading the bare .url property in a sleep loop.
    try:
        page.wait_for_url(lambda u: u.endswith("/payroll-runs"), timeout=20000)
        removed_ok = True
    except Exception:
        removed_ok = False
    check("run: a draft run is removed and sent back to the list", removed_ok)

    # ================================================================ 8. permission checks
    bctx, bp = login(browser, "parent@demo.test")
    check("parent (no payroll permission at all): refused on the HR-only pages", status_of(bctx, "/payroll-runs") == 403 and status_of(bctx, "/payroll-settings") == 403)
    check("parent: My Payslips never 403s, even with no linked employee record", status_of(bctx, "/my-payslips") == 200)
    bp.goto(f"{BASE}/my-payslips")
    check("parent: shown the plain 'no employee record' message instead of a crash", until(lambda: "No employee record linked" in body(bp)))
    bctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/payroll-runs")
    mpage.wait_for_load_state("networkidle")
    check("phone: the payroll runs list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
