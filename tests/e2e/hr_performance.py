"""Browser test: HR module Phase E -- performance reviews. Confirmed via AskUserQuestion: an employee can
view their OWN finalized review (read-only, never authored by themselves), and a single review is one
overall rating (1-5) + comments per cycle.

  * HR sets up two review cycles
  * HR writes a draft review for an employee, edits it, then finalizes it
  * a second review attempt for the same employee+cycle is refused with a plain-words inline error (the
    duplicate-review guard added this phase, matching the earlier ValidationException lesson from Leave)
  * a draft left for a different cycle stays invisible to the employee; only the finalized one shows on
    "My Performance"
  * the self-review ban: an HR admin who is also a listed employee never even sees the "Add review" button
    on their own profile -- a dead-end action is hidden rather than shown and silently refused
  * a review cycle still in use refuses removal; an unused one is removed cleanly
  * permission checks: a plain teacher has no access to the HR-only cycle/review-admin pages but can still
    see their own finalized review; someone with no performance permission at all is refused managing
    reviews; "My Performance" never 403s even for an account with no linked employee record at all

Uses the usability-session data; creates two throwaway employees (linked to teacher@demo.test and
principal@demo.test) and three throwaway review cycles, removed at the end. Only run it against that
throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/hr_performance.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
CYCLE_MIDYEAR = "E2E Mid-Year 2026"
CYCLE_ANNUAL = "E2E Annual 2026"
CYCLE_EMPTY = "E2E Unused Cycle"
EMP_TEACHER = "E2E Perf Teacher"
EMP_PRINCIPAL = "E2E Perf Principal"


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
    q("delete from performance_reviews where employee_id in (select id from employees where full_name in (?, ?))", EMP_TEACHER, EMP_PRINCIPAL)
    q("delete from employees where full_name in (?, ?)", EMP_TEACHER, EMP_PRINCIPAL)
    q("delete from performance_review_cycles where name in (?, ?, ?)", CYCLE_MIDYEAR, CYCLE_ANNUAL, CYCLE_EMPTY)


cleanup()

OWNER_ID = q("select id from users where email='owner@demo.test'")[0][0]
TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH = q("select id, school_id, branch_id from users where email='teacher@demo.test'")[0]
PRINCIPAL_ID, PRINCIPAL_SCHOOL, PRINCIPAL_BRANCH = q("select id, school_id, branch_id from users where email='principal@demo.test'")[0]

for name, user_id, school_id, branch_id in [(EMP_TEACHER, TEACHER_ID, TEACHER_SCHOOL, TEACHER_BRANCH), (EMP_PRINCIPAL, PRINCIPAL_ID, PRINCIPAL_SCHOOL, PRINCIPAL_BRANCH)]:
    q(
        "insert into employees (school_id, branch_id, user_id, employee_number, full_name, phone, hire_date, employment_status, employment_type, created_by, created_at, updated_at) "
        "values (?, ?, ?, ?, ?, '0911000066', '2026-01-01', 'active', 'full_time', ?, datetime('now'), datetime('now'))",
        school_id, branch_id, user_id, f"EMP-2026-{name[-8:].upper().replace(' ', '')}", name, OWNER_ID,
    )

EMP_TEACHER_ID = q("select id from employees where full_name=?", EMP_TEACHER)[0][0]
EMP_PRINCIPAL_ID = q("select id from employees where full_name=?", EMP_PRINCIPAL)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. HR sets up review cycles
    page.goto(f"{BASE}/performance-cycles")
    for cycle_name, start, end in [(CYCLE_MIDYEAR, "2026-01-01", "2026-06-30"), (CYCLE_ANNUAL, "2026-01-01", "2026-12-31"), (CYCLE_EMPTY, "2026-01-01", "2026-12-31")]:
        page.get_by_role("button", name=re.compile("Add review cycle", re.I)).click()
        page.wait_for_selector("[data-testid=performance-cycle-form]")
        page.fill("#cycle_name", cycle_name)
        page.fill("#cycle_start", start)
        page.fill("#cycle_end", end)
        page.get_by_test_id("performance-cycle-form").get_by_role("button", name=re.compile("Save review cycle", re.I)).click()
        page.wait_for_selector("[data-testid=performance-cycle-form]", state="hidden")
    check("cycles: all three are listed", until(lambda: CYCLE_MIDYEAR in body(page) and CYCLE_ANNUAL in body(page) and CYCLE_EMPTY in body(page)))

    # ================================================================ 2. HR writes a draft, edits it, finalizes it
    page.goto(f"{BASE}/employees/{EMP_TEACHER_ID}")
    page.get_by_role("button", name=re.compile("Add review", re.I)).click()
    page.wait_for_selector("[data-testid=performance-review-form]")
    page.select_option("#pr_cycle", label=CYCLE_MIDYEAR)
    page.get_by_role("radio", name=re.compile("Exceeds expectations", re.I)).click()
    page.fill("#pr_comments", "Good term overall.")
    page.get_by_test_id("performance-review-form").get_by_role("button", name=re.compile("Save as draft", re.I)).click()
    check("employee page: the draft review is listed", until(lambda: page.locator("[data-testid=performance-review-row]").filter(has_text="Draft").count() == 1))

    page.locator("[data-testid=performance-review-row]").filter(has_text=CYCLE_MIDYEAR).get_by_role("button", name=re.compile("^Change$", re.I)).click()
    page.wait_for_selector("[data-testid=performance-review-form]")
    page.get_by_role("radio", name=re.compile("^5 - Outstanding$", re.I)).click()
    page.fill("#pr_comments", "Revised: an outstanding term.")
    page.get_by_test_id("performance-review-form").get_by_role("button", name=re.compile("Finalize review", re.I)).click()
    check("employee page: the edited review is now finalized", until(lambda: page.locator("[data-testid=performance-review-row]").filter(has_text="Finalized").count() == 1))
    check("db: the finalized rating and comments were saved", q("select rating, comments from performance_reviews where employee_id=? and cycle_id=(select id from performance_review_cycles where name=?)", EMP_TEACHER_ID, CYCLE_MIDYEAR)[0] == (5, "Revised: an outstanding term."))

    # ================================================================ 3. a second review for the same employee+cycle is refused in plain words
    page.get_by_role("button", name=re.compile("Add review", re.I)).click()
    page.wait_for_selector("[data-testid=performance-review-form]")
    page.select_option("#pr_cycle", label=CYCLE_MIDYEAR)
    page.get_by_role("radio", name=re.compile("Needs improvement", re.I)).click()
    page.fill("#pr_comments", "Duplicate attempt.")
    page.get_by_test_id("performance-review-form").get_by_role("button", name=re.compile("Save as draft", re.I)).click()
    check("duplicate review: refused with a plain-words inline error, not a stuck or silent dialog", until(lambda: "already has a review for" in body(page)))
    check("db: no second row was created", q("select count(*) from performance_reviews where employee_id=? and cycle_id=(select id from performance_review_cycles where name=?)", EMP_TEACHER_ID, CYCLE_MIDYEAR)[0][0] == 1)
    page.get_by_test_id("performance-review-form").get_by_role("button", name=re.compile("Cancel", re.I)).click()

    # ================================================================ 4. a second draft, for a different cycle, stays invisible to the employee
    page.get_by_role("button", name=re.compile("Add review", re.I)).click()
    page.wait_for_selector("[data-testid=performance-review-form]")
    page.select_option("#pr_cycle", label=CYCLE_ANNUAL)
    page.get_by_role("radio", name=re.compile("Developing", re.I)).click()
    page.fill("#pr_comments", "Mid-cycle note, not ready to finalize.")
    page.get_by_test_id("performance-review-form").get_by_role("button", name=re.compile("Save as draft", re.I)).click()
    page.wait_for_selector("[data-testid=performance-review-form]", state="hidden")
    check("employee page: both reviews now listed (one draft, one finalized)", until(lambda: page.locator("[data-testid=performance-review-row]").count() == 2))

    # ================================================================ 5. the employee sees only the finalized review on My Performance
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/my-performance")
    check("my performance: the finalized review is shown with its rating and comments", until(lambda: "Outstanding" in body(tp) and "Revised: an outstanding term." in body(tp)))
    check("my performance: the still-draft review is NOT shown", "Mid-cycle note" not in body(tp))
    check("teacher: no access to the HR-only cycle/review-admin pages", status_of(tctx, "/performance-cycles") == 403)
    tctx.close()

    # ================================================================ 6. the self-review ban: the Add review action never even appears
    pctx, ppage = login(browser, "principal@demo.test")
    ppage.goto(f"{BASE}/employees/{EMP_PRINCIPAL_ID}")
    check("self-review ban: the employee page loads normally", until(lambda: EMP_PRINCIPAL in body(ppage)))
    check("self-review ban: no Add review button on your own profile", ppage.get_by_role("button", name=re.compile("Add review", re.I)).count() == 0)
    pctx.close()

    # ================================================================ 7. cycle removal: blocked while in use, clean when unused
    page.goto(f"{BASE}/performance-cycles")
    midyear_row = page.locator("[data-testid=performance-cycle-row]").filter(has_text=CYCLE_MIDYEAR)
    midyear_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector(f"text=Remove {CYCLE_MIDYEAR}?")
    page.get_by_role("button", name="Remove review cycle").click()
    check("cycles: a cycle still in use refuses removal", until(lambda: "cannot be deleted because it is still used by" in body(page)))
    check("db: the in-use cycle was not deleted", q("select count(*) from performance_review_cycles where name=?", CYCLE_MIDYEAR)[0][0] == 1)

    empty_row = page.locator("[data-testid=performance-cycle-row]").filter(has_text=CYCLE_EMPTY)
    empty_row.get_by_role("button", name=re.compile("^Remove$", re.I)).click()
    page.wait_for_selector(f"text=Remove {CYCLE_EMPTY}?")
    page.get_by_role("button", name="Remove review cycle").click()
    check("cycles: an unused cycle is removed cleanly", until(lambda: CYCLE_EMPTY not in body(page)))

    # ================================================================ 8. permission checks
    bctx, bp = login(browser, "parent@demo.test")
    check("parent (no performance permission at all): refused on the HR-only pages", status_of(bctx, "/performance-cycles") == 403)
    check("parent: My Performance never 403s, even with no linked employee record", status_of(bctx, "/my-performance") == 200)
    bp.goto(f"{BASE}/my-performance")
    check("parent: shown the plain 'no employee record' message instead of a crash", until(lambda: "No employee record linked" in body(bp)))
    bctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/performance-cycles")
    mpage.wait_for_load_state("networkidle")
    check("phone: the performance cycles list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
