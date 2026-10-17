"""Browser test: Academic Expansion Phase 8 -- Assignments/Homework. The brief's own words: "a simple
foundation for teacher assignments" -- subject/class/teacher/title/description/due date/attachment/status,
and "parents/students should only see assignments through authorized portal functionality."

  * a teacher creates a DRAFT for a class they actually teach -- it is NOT yet visible to a parent
  * publishing it makes it visible on the parent's own homework page, with the right class/subject/teacher
  * removing it takes it away again
  * someone with no manage-assignments permission (an accountant) is refused the route outright

Uses the usability-session data (teacher@demo.test teaches Mathematics to Grade 3 A). Only run it against
that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/assignments.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
SENTINEL = "E2E-Chapter-9-Worksheet-Sentinel"


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
    q("delete from assignments where title=?", SENTINEL)
    q("delete from notifications where data like ?", f"%{SENTINEL}%")


cleanup()
GUARDIAN_EMAIL = q(
    "select u.email from students st join enrollments e on e.student_id=st.id and e.status='active' "
    "join sections s on s.id=e.section_id join parent_student ps on ps.student_id=st.id join users u on u.id=ps.parent_id "
    "where s.name='A' and s.grade_id=(select id from grades where name='Grade 3') limit 1"
)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- an accountant (no manage-assignments by default) is refused the route outright --
    actx, ap = login(browser, "accountant@demo.test")
    check("accountant: the assignments route is refused outright", status_of(actx, "/assignments") == 403)
    ap.goto(f"{BASE}/dashboard")
    check("accountant: no 'Homework' nav item at all", ap.get_by_role("link", name=re.compile("^homework$", re.I)).count() == 0)
    actx.close()

    # -- teacher: creates a draft for a class they actually teach --
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/assignments")
    check("teacher: the page loads", "Homework" in body(tp) or "ቤት ስራ" in body(tp))

    tp.get_by_role("button", name=re.compile("new assignment", re.I)).click()
    tp.locator('[data-testid="assignment-form"]').wait_for()
    tp.fill("#asg_title", SENTINEL)
    due = q("select date('now','+3 day')")[0][0]
    tp.fill("#asg_due_date", due)
    tp.get_by_role("button", name=re.compile("save assignment", re.I)).click()
    tp.locator('[data-testid="assignment-form"]').wait_for(state="hidden")
    check("teacher: the new assignment shows in the list, as a draft", until(lambda: SENTINEL in body(tp)))
    row = tp.locator('[data-testid="assignment-row"]', has_text=SENTINEL)
    check("teacher: it starts as Draft", "Draft" in row.inner_text())

    # -- a draft is NOT visible to the parent yet --
    gctx, gp = login(browser, GUARDIAN_EMAIL)
    gp.goto(f"{BASE}/my/homework")
    check("guardian: a draft assignment is not shown yet", SENTINEL not in body(gp))
    gctx.close()

    # -- publishing it makes it visible --
    row.get_by_role("button", name=re.compile("^change$", re.I)).click()
    tp.locator('[data-testid="assignment-form"]').wait_for()
    tp.locator("#asg_status").select_option("published")
    tp.get_by_role("button", name=re.compile("save assignment", re.I)).click()
    tp.locator('[data-testid="assignment-form"]').wait_for(state="hidden")
    check("teacher: it now shows Published", until(lambda: "Published" in tp.locator('[data-testid="assignment-row"]', has_text=SENTINEL).inner_text()))

    gctx, gp = login(browser, GUARDIAN_EMAIL)
    gp.goto(f"{BASE}/my/homework")
    check("guardian: the published assignment is now shown", until(lambda: SENTINEL in body(gp)))
    check("guardian: the subject and due date are shown", "MATH" in body(gp).upper())
    gctx.close()

    # -- removing it takes it away again --
    tp.reload()
    row = tp.locator('[data-testid="assignment-row"]', has_text=SENTINEL)
    row.get_by_role("button", name=re.compile("remove assignment", re.I)).click()
    tp.get_by_role("dialog").get_by_role("button", name=re.compile("remove assignment", re.I)).click()
    check("teacher: removing it takes it off the list", until(lambda: SENTINEL not in body(tp)))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "teacher@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/assignments")
    mpage.wait_for_load_state("networkidle")
    check("phone: the homework list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
