"""Browser test: HR module Phase A (employee profiles, departments, job titles) and Phase B (employment
contracts).

  * adding a department and a job title (job titles are a real, separate concept from a Spatie system role)
  * adding an employee through the wizard (personal -> employment -> other -> review), unlinked to any
    account at first, showing up on the employee list
  * linking the employee to an existing staff login account from the Edit page, then unlinking it again --
    both are plain actions on the HR record, never touching the account's own role/permissions
  * a department/job title that is still in use refuses to be removed, in plain words; one with nothing
    depending on it is removed cleanly
  * adding a draft contract (removable) and an active contract with an uploaded PDF document (not removable,
    no Remove button at all for a non-draft contract); the document is served only to an authorized viewer
  * someone without any HR permission is refused everywhere, including a contract's own document URL
  * Amharic: the employee list heading is translated, no raw keys

Uses the usability-session data; creates one throwaway department/position/employee, removed at the end,
and links/unlinks a demo teacher account without leaving it linked. Only run it against that throwaway
database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
DEPT_NAME = "E2E Finance Dept"
DEPT_EMPTY_NAME = "E2E Temp Dept"
POSITION_TITLE = "E2E Senior Accountant"
EMPLOYEE_NAME = "E2E Hiwot Alemu"


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
    q("delete from employment_contracts where employee_id in (select id from employees where full_name=?)", EMPLOYEE_NAME)
    q("delete from employees where full_name=?", EMPLOYEE_NAME)
    q("delete from positions where title=?", POSITION_TITLE)
    q("delete from departments where name in (?, ?)", DEPT_NAME, DEPT_EMPTY_NAME)


cleanup()

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. departments + job titles
    page.goto(f"{BASE}/departments")
    page.get_by_role("button", name=re.compile("Add department", re.I)).click()
    page.wait_for_selector("[data-testid=department-form]")
    page.fill("#dept_name", DEPT_NAME)
    page.locator("[data-testid=department-form]").get_by_role("button", name=re.compile("Save department", re.I)).click()
    check("department: added and listed", until(lambda: DEPT_NAME in body(page)))

    page.get_by_role("button", name=re.compile("Add department", re.I)).click()
    page.wait_for_selector("[data-testid=department-form]")
    page.fill("#dept_name", DEPT_EMPTY_NAME)
    page.locator("[data-testid=department-form]").get_by_role("button", name=re.compile("Save department", re.I)).click()
    check("department: a second, empty department is also listed", until(lambda: DEPT_EMPTY_NAME in body(page)))

    page.goto(f"{BASE}/positions")
    page.get_by_role("button", name=re.compile("Add job title", re.I)).click()
    page.wait_for_selector("[data-testid=position-form]")
    page.fill("#pos_title", POSITION_TITLE)
    page.select_option("#pos_department", label=DEPT_NAME)
    page.locator("[data-testid=position-form]").get_by_role("button", name=re.compile("Save job title", re.I)).click()
    check("job title: added, under the right department, and listed", until(lambda: POSITION_TITLE in body(page) and DEPT_NAME in body(page)))

    # ================================================================ 2. an employee through the wizard, unlinked
    page.goto(f"{BASE}/employees/new")
    page.fill("#emp_full_name", EMPLOYEE_NAME)
    page.fill("#emp_phone", "0911234567")
    page.get_by_role("button", name="Next").click()
    page.fill("#emp_hire_date", "2026-01-15")
    page.select_option("#emp_department", label=DEPT_NAME)
    page.select_option("#emp_position", label=POSITION_TITLE)
    page.get_by_role("button", name="Next").click()
    page.get_by_role("button", name="Next").click()
    check("employee wizard: review shows the name and job title", until(lambda: EMPLOYEE_NAME in body(page) and POSITION_TITLE in body(page)))
    page.get_by_role("button", name=re.compile("Save employee", re.I)).click()
    page.wait_for_url(re.compile(r"/employees/\d+$"))
    check("employee: saved and opens its own profile", until(lambda: EMPLOYEE_NAME in body(page) and "EMP-" in body(page)))
    check("employee: no login account linked yet", "No login account linked to this employee yet." in body(page))
    employee_url = page.url

    page.goto(f"{BASE}/employees")
    check("employee: listed with a no-account note", until(lambda: EMPLOYEE_NAME in body(page) and "No login account linked" in body(page)))

    # ================================================================ 3. linking and unlinking an account
    page.goto(employee_url)
    page.get_by_role("link", name=re.compile("Change details", re.I)).click()
    page.wait_for_url(re.compile(r"/employees/\d+/edit$"))
    samuel_option = page.eval_on_selector_all("#emp_account option", "(opts) => opts.find(o => o.textContent.includes('Samuel Girma'))?.value")
    page.select_option("#emp_account", samuel_option)
    page.get_by_role("button", name=re.compile("Save employee", re.I)).click()
    page.wait_for_url(re.compile(r"/employees/\d+$"))
    check("employee: linking an account shows it on the profile", until(lambda: "Linked to" in body(page) and "Samuel Girma" in body(page)))

    teacher_perms_before = q("select count(*) from model_has_permissions where model_id=(select id from users where email='teacher@demo.test')")[0][0]

    page.get_by_role("link", name=re.compile("Change details", re.I)).click()
    page.wait_for_url(re.compile(r"/employees/\d+/edit$"))
    page.select_option("#emp_account", label="No account linked")
    page.get_by_role("button", name=re.compile("Save employee", re.I)).click()
    page.wait_for_url(re.compile(r"/employees/\d+$"))
    check("employee: unlinking shows 'not linked' again", until(lambda: "No login account linked to this employee yet." in body(page)))

    teacher_perms_after = q("select count(*) from model_has_permissions where model_id=(select id from users where email='teacher@demo.test')")[0][0]
    check("linking/unlinking never touches the account's own permissions", teacher_perms_before == teacher_perms_after)

    # ================================================================ 4. deletion guards
    page.goto(f"{BASE}/departments")
    row = page.locator("[data-testid=department-row]").filter(has_text=DEPT_NAME)
    row.get_by_role("button", name=re.compile("Remove", re.I)).click()
    page.wait_for_selector("text=Remove E2E Finance Dept?")
    page.get_by_role("button", name="Remove department").click()
    check("department still in use: removal is refused in plain words", until(lambda: "cannot be deleted because it is still used by" in body(page)))

    empty_row = page.locator("[data-testid=department-row]").filter(has_text=DEPT_EMPTY_NAME)
    empty_row.get_by_role("button", name=re.compile("Remove", re.I)).click()
    page.wait_for_selector("text=Remove E2E Temp Dept?")
    page.get_by_role("button", name="Remove department").click()
    check("department with nothing depending on it: removed", until(lambda: DEPT_EMPTY_NAME not in body(page)))

    # ================================================================ 4b. employment contracts
    page.goto(employee_url)
    page.get_by_role("button", name=re.compile("Add contract", re.I)).click()
    page.wait_for_selector("[data-testid=contract-form]")
    page.fill("#contract_start", "2026-01-01")
    page.locator("[data-testid=contract-form]").get_by_role("button", name=re.compile("Save contract", re.I)).click()
    page.wait_for_selector("[data-testid=contract-form]", state="hidden")
    check("contract: a draft contract is added and listed", until(lambda: page.locator("[data-testid=contract-row]").count() == 1 and "Draft" in body(page)))
    draft_row = page.locator("[data-testid=contract-row]").first
    check("contract: a draft contract offers a Remove button", draft_row.get_by_role("button", name=re.compile("^Remove$", re.I)).count() == 1)

    page.get_by_role("button", name=re.compile("Add contract", re.I)).click()
    page.wait_for_selector("[data-testid=contract-form]")
    page.fill("#contract_start", "2025-06-01")
    page.select_option("#contract_status", label="Active")
    page.set_input_files("#contract_document", "C:/Users/user/AppData/Local/Temp/claude/c--Users-user-Desktop-projects-schoolERP-prj/cfc016db-d734-425b-9774-8e18aa28a1e4/scratchpad/test_contract.pdf")
    page.locator("[data-testid=contract-form]").get_by_role("button", name=re.compile("Save contract", re.I)).click()
    page.wait_for_selector("[data-testid=contract-form]", state="hidden")
    check("contract: an active contract with a document is added and listed", until(lambda: page.locator("[data-testid=contract-row]").count() == 2 and "Active" in body(page)))

    active_row = page.locator("[data-testid=contract-row]").filter(has_text="Active")
    check("contract: an active contract offers no Remove button (not draft)", active_row.get_by_role("button", name=re.compile("^Remove$", re.I)).count() == 0)
    doc_link = active_row.get_by_role("link", name=re.compile("View document", re.I))
    check("contract: the uploaded document has a view link", doc_link.count() == 1)
    doc_href = doc_link.get_attribute("href")
    check("contract: the document URL is served successfully to an authorized viewer", octx.request.get(doc_href).status == 200)

    draft_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector("text=Remove this draft contract?")
    page.get_by_role("button", name="Remove contract").click()
    check("contract: removing the draft succeeds, one contract remains", until(lambda: page.locator("[data-testid=contract-row]").count() == 1))

    # ================================================================ 5. permission checks
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no HR access at all", all(status_of(tctx, u) == 403 for u in ("/employees", "/employees/new", "/departments", "/positions")))
    check("teacher: no access to a contract document either", tctx.request.get(doc_href, max_redirects=0).status == 403)
    tctx.close()

    # ================================================================ 6. Amharic
    q("update users set locale='am' where email='owner@demo.test'")
    actx, ap = login(browser, "owner@demo.test")
    ap.goto(f"{BASE}/employees")
    check("Amharic: employee list is translated (heading not English), no raw keys", until(lambda: "ሰራተኞች" in body(ap) and "employee." not in body(ap)))
    actx.close()
    q("update users set locale=null where email='owner@demo.test'")

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/employees")
    mpage.wait_for_load_state("networkidle")
    check("phone: the employee list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
