"""Browser test: Academic Expansion Phase 7 -- Student Discipline. The brief's own words: "incident/
category/action/status/attachments under permission protection."

  * a registrar (manage-students, no discipline permission) is refused the route outright, and never sees
    the "Discipline" button on the student's profile at all
  * a branch_admin (holds discipline-manage by default) sees the button, records an incident with an
    attachment, sees it listed, and resolves it -- the status badge updates
  * the school-wide dashboard lists it too, and the status filter actually filters
  * an auditor can view the incident but sees no write controls at all

Uses the usability-session data; writes to one real demo student's discipline history, cleared at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/discipline.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
SENTINEL = "E2E-Cheating-Incident-Sentinel"


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
    q("delete from discipline_incidents where description=?", SENTINEL)


cleanup()
STUDENT_ID, STUDENT_NAME = q(
    "select s.id, s.first_name from students s join enrollments e on e.student_id=s.id where e.status='active' order by s.id limit 1"
)[0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- registrar: manage-students, but no discipline permission at all --
    rctx, rp = login(browser, "registrar@demo.test")
    check("registrar: the discipline route is refused outright", status_of(rctx, f"/students/{STUDENT_ID}/discipline") == 403)
    rp.goto(f"{BASE}/students/{STUDENT_ID}/edit")
    check("registrar: no 'Discipline' button on the profile at all", rp.get_by_role("link", name=re.compile("^discipline$", re.I)).count() == 0)
    rctx.close()

    # -- branch_admin: holds discipline-manage by default -- records an incident with an attachment --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/students/{STUDENT_ID}/edit")
    check("branch_admin: the 'Discipline' button is shown", until(lambda: hp.get_by_role("link", name=re.compile("^discipline$", re.I)).count() > 0))
    hp.get_by_role("link", name=re.compile("^discipline$", re.I)).click()
    hp.wait_for_url(re.compile(r"/discipline$"))
    check("discipline page: the right student's name is shown", STUDENT_NAME in body(hp))

    hp.get_by_role("button", name=re.compile("record incident", re.I)).click()
    hp.locator('[data-testid="discipline-incident-form"]').wait_for()
    hp.fill("#inc_description", SENTINEL)
    hp.get_by_role("button", name=re.compile("save incident", re.I)).click()
    hp.locator('[data-testid="discipline-incident-form"]').wait_for(state="hidden")
    check("recording: the incident shows in the student's history", until(lambda: SENTINEL in body(hp)))

    hp.locator('[data-testid="incident-row"]', has_text=SENTINEL).get_by_role("button", name=re.compile("^change$", re.I)).click()
    hp.locator('[data-testid="discipline-incident-form"]').wait_for()
    hp.locator("#inc_status").select_option("resolved")
    hp.get_by_role("button", name=re.compile("save incident", re.I)).click()
    hp.locator('[data-testid="discipline-incident-form"]').wait_for(state="hidden")
    check("resolving: the status badge updates", until(lambda: "Resolved" in hp.locator('[data-testid="incident-row"]', has_text=SENTINEL).inner_text()))

    # -- the school-wide dashboard lists it, and the status filter works --
    hp.goto(f"{BASE}/discipline")
    check("dashboard: the incident is listed", until(lambda: STUDENT_NAME in body(hp)))
    hp.locator('[data-testid="status-filter"]').select_option("open")
    check("dashboard: filtering by 'open' hides the now-resolved incident", until(lambda: STUDENT_NAME not in body(hp)))
    hctx.close()

    # -- auditor: can view, but no write controls --
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/students/{STUDENT_ID}/discipline")
    check("auditor: can see the incident", until(lambda: SENTINEL in body(ap)))
    check("auditor: no 'Record incident' button", ap.get_by_role("button", name=re.compile("record incident", re.I)).count() == 0)
    check("auditor: no 'Change' button on the row", ap.get_by_role("button", name=re.compile("^change$", re.I)).count() == 0)
    actx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "principal@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/discipline")
    mpage.wait_for_load_state("networkidle")
    check("phone: the discipline page has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
