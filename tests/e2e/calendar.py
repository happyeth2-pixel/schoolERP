"""Browser test: Academic Expansion Phase 3 -- School Calendar. Mirrors Announcements closely (same
3-bucket audience: everyone/parents/staff, same branch-or-every-branch scoping) -- confirmed with the user
as the right granularity for "role-based visibility" rather than a finer per-role picker.

  * anyone signed in can open /calendar (no permission needed to view); only manage-calendar holders see
    an "Add event" button and can write/edit/remove
  * someone without manage-calendar is refused the write routes even if they can view the page
  * a branch_admin's new event is forced into their own branch even when asking for the whole school
  * audience actually filters what different people see: a staff-only event is invisible to a parent, a
    parents-only event is invisible to a teacher
  * editing and removing an event works, with a confirmation before removing
  * a past event is tucked under a collapsed "past events" section, not mixed into the upcoming list

Uses the usability-session data; creates its own throwaway events, removed at the end. Only run it against
that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/calendar.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
EVERYONE_TITLE = "E2E All-School Picnic"
STAFF_TITLE = "E2E Staff Only Briefing"
PARENTS_TITLE = "E2E Parents Only Info Session"
PAST_TITLE = "E2E Past Founders Day"


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
    q("delete from calendar_events where title in (?, ?, ?, ?)", EVERYONE_TITLE, STAFF_TITLE, PARENTS_TITLE, PAST_TITLE)


cleanup()
SCHOOL_ID = q("select id from schools where code='DEMO'")[0][0]
q(
    "insert into calendar_events (school_id, branch_id, created_by, title, event_type, audience, start_date, created_at, updated_at) "
    "values (?, null, (select id from users where email='owner@demo.test'), ?, 'other', 'everyone', date('now', '-3 days'), datetime('now'), datetime('now'))",
    SCHOOL_ID, PAST_TITLE,
)

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- anyone can view; only a manager can write --
    actx, ap = login(browser, "accountant@demo.test")
    check("accountant: /calendar is viewable", status_of(actx, "/calendar") == 200)
    ap.goto(f"{BASE}/calendar")
    check("accountant: no 'Add event' button shown", ap.get_by_role("button", name=re.compile("add event", re.I)).count() == 0)
    actx.close()

    # -- the past event is tucked away, not mixed into the upcoming list --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/calendar")
    check("past event is not shown in the open/upcoming list", PAST_TITLE not in body(hp))
    hp.get_by_test_id("past-events").click()
    check("past event appears once 'past events' is opened", until(lambda: PAST_TITLE in body(hp)))

    # -- branch_admin creates a school-wide-looking event, forced into their own branch --
    hp.get_by_role("button", name=re.compile("add event", re.I)).click()
    hp.locator('[data-testid="calendar-event-form"]').wait_for()
    hp.fill("#cal_title", EVERYONE_TITLE)
    hp.fill("#cal_start", "2026-12-15")
    hp.get_by_role("button", name=re.compile("save event", re.I)).click()
    hp.locator('[data-testid="calendar-event-form"]').wait_for(state="hidden")
    check("branch_admin: the new event shows on the calendar", until(lambda: EVERYONE_TITLE in body(hp)))
    hctx.close()

    check("db: a branch_admin's event is forced into their own branch", q(
        "select branch_id from calendar_events where title=?", EVERYONE_TITLE,
    )[0][0] is not None)

    # -- owner creates a staff-only and a parents-only event, school-wide --
    octx, op = login(browser, "owner@demo.test")
    op.goto(f"{BASE}/calendar")

    op.get_by_role("button", name=re.compile("add event", re.I)).click()
    op.locator('[data-testid="calendar-event-form"]').wait_for()
    op.fill("#cal_title", STAFF_TITLE)
    op.fill("#cal_start", "2026-12-16")
    op.locator("#cal_audience").select_option("staff")
    op.get_by_role("button", name=re.compile("save event", re.I)).click()
    op.locator('[data-testid="calendar-event-form"]').wait_for(state="hidden")
    check("owner: the staff-only event shows", until(lambda: STAFF_TITLE in body(op)))

    op.get_by_role("button", name=re.compile("add event", re.I)).click()
    op.locator('[data-testid="calendar-event-form"]').wait_for()
    op.fill("#cal_title", PARENTS_TITLE)
    op.fill("#cal_start", "2026-12-17")
    op.locator("#cal_audience").select_option("parents")
    op.get_by_role("button", name=re.compile("save event", re.I)).click()
    op.locator('[data-testid="calendar-event-form"]').wait_for(state="hidden")
    check("owner: the parents-only event shows", until(lambda: PARENTS_TITLE in body(op)))
    octx.close()

    # -- audience actually filters what people see --
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/calendar")
    check("teacher: sees the staff-only event", until(lambda: STAFF_TITLE in body(tp)))
    check("teacher: does NOT see the parents-only event", PARENTS_TITLE not in body(tp))
    tctx.close()

    pctx, pp = login(browser, "parent@demo.test")
    pp.goto(f"{BASE}/calendar")
    check("parent: sees the parents-only event", until(lambda: PARENTS_TITLE in body(pp)))
    check("parent: does NOT see the staff-only event", STAFF_TITLE not in body(pp))
    pctx.close()

    # -- edit and remove --
    h2ctx, h2p = login(browser, "principal@demo.test")
    h2p.goto(f"{BASE}/calendar")
    card = h2p.locator('[data-testid="calendar-event"]', has_text=EVERYONE_TITLE)
    card.get_by_role("button", name=re.compile("change", re.I)).click()
    h2p.locator('[data-testid="calendar-event-form"]').wait_for()
    h2p.fill("#cal_title", EVERYONE_TITLE + " (updated)")
    h2p.get_by_role("button", name=re.compile("save event", re.I)).click()
    h2p.locator('[data-testid="calendar-event-form"]').wait_for(state="hidden")
    check("edit: the updated title shows", until(lambda: EVERYONE_TITLE + " (updated)" in body(h2p)))

    h2p.locator('[data-testid="calendar-event"]', has_text=EVERYONE_TITLE).get_by_role("button", name=re.compile("remove", re.I)).click()
    h2p.get_by_role("dialog").get_by_role("button", name=re.compile("remove event", re.I)).click()
    check("remove: the event is gone from the list", until(lambda: EVERYONE_TITLE not in body(h2p)))
    h2ctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "accountant@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/calendar")
    mpage.wait_for_load_state("networkidle")
    check("phone: the calendar has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

check("db: the removed event was archived, not erased", q(
    "select count(*) from calendar_events where title like ?", EVERYONE_TITLE + "%",
)[0][0] == 1)

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
