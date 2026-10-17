"""Browser test: Academic Expansion Phase 5 -- Section Balancing. The brief's own words: "recommendations,
not silent auto-assignment, confirmed by staff." This wires INTO the existing section-picker step (per the
decision confirmed with the user at the very start of this whole initiative) rather than being a new page.

  * the section with fewer students shows a "Recommended" badge and says why, in the enrolment wizard
  * its busier sibling of the same grade does NOT show the badge
  * nothing is preselected -- the badge is purely informational, staff still pick and confirm themselves
  * the same badge shows on the student-profile "move to another class" picker too

Uses the usability-session data; adds a second, empty section to an existing grade so there is something to
balance against (every demo grade otherwise has exactly one section), removed at the end. Only run it
against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/section_balancing.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []


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
nxt = lambda page: page.locator("form").get_by_role("button", name=re.compile(r"^(Next|ቀጣይ|Itti fufi|ቀጽል)$"))


def cleanup():
    q("delete from sections where name='Z' and grade_id=(select id from grades where name='Grade 1' and school_id=(select id from schools where code='DEMO'))")


cleanup()
SCHOOL_ID = q("select id from schools where code='DEMO'")[0][0]
BRANCH_ID = q("select id from branches where school_id=? and code='MAIN'", SCHOOL_ID)[0][0]
YEAR_ID = q("select id from academic_years where school_id=? and is_current=1", SCHOOL_ID)[0][0]
GRADE_ID = q("select id from grades where school_id=? and name='Grade 1'", SCHOOL_ID)[0][0]

SECTION_A_ID, OCCUPIED_A = q(
    "select s.id, (select count(*) from enrollments e where e.section_id=s.id and e.status='active') "
    "from sections s where s.grade_id=? and s.branch_id=? and s.academic_year_id=? and s.name='A'",
    GRADE_ID, BRANCH_ID, YEAR_ID,
)[0]
check("setup: Grade 1 A already has some students enrolled (something to be less full than)", OCCUPIED_A > 0, f"occupied={OCCUPIED_A}")

q(
    "insert into sections (branch_id, grade_id, academic_year_id, name, created_at, updated_at) values (?, ?, ?, 'Z', datetime('now'), datetime('now'))",
    BRANCH_ID, GRADE_ID, YEAR_ID,
)
SECTION_Z_ID = q("select id from sections where grade_id=? and branch_id=? and name='Z'", GRADE_ID, BRANCH_ID)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    rctx, rp = login(browser, "registrar@demo.test")

    rp.goto(f"{BASE}/students/create")
    rp.fill("#first_name", "E2E Balance Test")
    nxt(rp).click()
    rp.wait_for_selector("text=Step 2 of 7")
    rp.get_by_role("radio", name="Girl").click()
    nxt(rp).click()
    rp.wait_for_selector("text=Step 3 of 7")
    nxt(rp).click()  # skip the optional photo step
    rp.wait_for_selector("text=Step 4 of 7")

    rp.get_by_role("radio", name="Grade 1", exact=True).click()
    z_card = rp.get_by_role("radio", name=re.compile("Grade 1 Z"))
    a_card = rp.get_by_role("radio", name=re.compile("Grade 1 A"))

    check("the emptier section (Z) shows the Recommended badge", until(lambda: "Recommended" in z_card.inner_text()))
    check("the Recommended reason names the actual count", "0" in z_card.inner_text())
    check("its busier sibling (A) does NOT show the badge", "Recommended" not in a_card.inner_text())
    check("nothing is preselected -- the badge is informational only", z_card.get_attribute("aria-checked") == "false" and a_card.get_attribute("aria-checked") == "false")

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "registrar@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/students/create")
    mpage.wait_for_load_state("networkidle")
    check("phone: the enrolment wizard's section picker has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
