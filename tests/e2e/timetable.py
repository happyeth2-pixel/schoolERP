"""Browser test: Academic Expansion Phase 1 -- the weekly Timetable, plus its two small supporting
catalogs (Periods, Rooms).

  * someone with no timetable permission at all is refused /timetable, /periods, and /rooms
  * Periods and Rooms each support a basic add/remove round trip through their own dialog
  * adding a timetable slot with no conflict shows it on the class's weekly grid
  * double-booking the same teacher at the same day/period (from a DIFFERENT class) is refused inline,
    and an authorized branch_admin can save it anyway with a recorded reason -- shown as "Overridden"
  * a teacher sees both of their own slots (across different classes) on My Timetable; a teacher with
    nothing scheduled sees the empty state

Uses the usability-session data; creates its own throwaway term/subject/period/room, removed at the end.
Reuses the existing "Grade 1 A" / "Grade 2 A" sections and the two demo teacher accounts. Only run it
against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/timetable.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
TERM_NAME = "E2E Timetable Term"
SUBJECT_NAME = "E2E Timetable Subject"
ROOM_NAME = "E2E Room 101"
TEMP_ROOM_NAME = "E2E Temp Room"


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
    q("delete from timetable_slots where subject_id in (select id from subjects where name=?)", SUBJECT_NAME)
    q("delete from subjects where name=?", SUBJECT_NAME)
    q("delete from terms where name=?", TERM_NAME)
    q("delete from rooms where name in (?, ?)", ROOM_NAME, TEMP_ROOM_NAME)
    q("delete from periods where school_id=(select id from schools where code='DEMO') and period_number in (1, 9)")


cleanup()

SCHOOL_ID = q("select id from schools where code='DEMO'")[0][0]
BRANCH_ID = q("select id from branches where school_id=? and code='MAIN'", SCHOOL_ID)[0][0]
YEAR_ID = q("select id from academic_years where school_id=? and is_current=1", SCHOOL_ID)[0][0]
TEACHER_ID, TEACHER_NAME = q("select id, name from users where email='teacher@demo.test'")[0]
TEACHER2_ID = q("select id from users where email='teacher2@demo.test'")[0][0]

q("insert into terms (academic_year_id, name, start_date, end_date, created_at, updated_at) values (?, ?, '2026-09-01', '2027-01-31', datetime('now'), datetime('now'))", YEAR_ID, TERM_NAME)
TERM_ID = q("select id from terms where name=?", TERM_NAME)[0][0]

q("insert into subjects (school_id, name, created_at, updated_at) values (?, ?, datetime('now'), datetime('now'))", SCHOOL_ID, SUBJECT_NAME)

q("insert into periods (school_id, period_number, start_time, end_time, created_at, updated_at) values (?, 1, '08:00:00', '08:40:00', datetime('now'), datetime('now'))", SCHOOL_ID)
PERIOD1_ID = q("select id from periods where school_id=? and period_number=1", SCHOOL_ID)[0][0]

q("insert into rooms (branch_id, name, capacity, created_at, updated_at) values (?, ?, 30, datetime('now'), datetime('now'))", BRANCH_ID, ROOM_NAME)

SECTION_A_ID = q(
    "select s.id from sections s join grades g on g.id=s.grade_id "
    "where g.school_id=? and g.name='Grade 1' and s.name='A' and s.academic_year_id=? and s.branch_id=?",
    SCHOOL_ID, YEAR_ID, BRANCH_ID,
)[0][0]
SECTION_B_ID = q(
    "select s.id from sections s join grades g on g.id=s.grade_id "
    "where g.school_id=? and g.name='Grade 2' and s.name='A' and s.academic_year_id=? and s.branch_id=?",
    SCHOOL_ID, YEAR_ID, BRANCH_ID,
)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- no permission at all --
    actx, _ = login(browser, "accountant@demo.test")
    check("accountant: /timetable is refused", status_of(actx, "/timetable") == 403)
    check("accountant: /periods is refused", status_of(actx, "/periods") == 403)
    check("accountant: /rooms is refused", status_of(actx, "/rooms") == 403)
    actx.close()

    # -- branch_admin: Periods CRUD round trip --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/periods")
    hp.get_by_role("button", name="Add period").click()
    hp.fill("#period_number", "9")
    hp.fill("#period_start", "09:20")
    hp.fill("#period_end", "10:00")
    hp.get_by_role("button", name="Save period").click()
    check("periods: a newly added period shows in the list", until(lambda: "Period 9" in body(hp)))
    hp.locator('[data-testid="period-row"]', has_text="Period 9").get_by_role("button", name="Remove").click()
    hp.get_by_role("button", name="Remove period").click()
    check("periods: removing it takes it back out of the list", until(lambda: "Period 9" not in body(hp)))

    # -- branch_admin: Rooms CRUD round trip --
    hp.goto(f"{BASE}/rooms")
    hp.get_by_role("button", name="Add room").click()
    hp.fill("#room_name", TEMP_ROOM_NAME)
    hp.get_by_role("button", name="Save room").click()
    check("rooms: a newly added room shows in the list", until(lambda: TEMP_ROOM_NAME in body(hp)))
    hp.locator('[data-testid="room-row"]', has_text=TEMP_ROOM_NAME).get_by_role("button", name="Remove").click()
    hp.get_by_role("button", name="Remove room").click()
    check("rooms: removing it takes it back out of the list", until(lambda: TEMP_ROOM_NAME not in body(hp)))

    # -- branch_admin: build the timetable for Grade 1 A --
    hp.goto(f"{BASE}/timetable")
    hp.locator('[data-testid="term-select"]').select_option(str(TERM_ID))
    hp.wait_for_url(re.compile(f"term_id={TERM_ID}"))
    hp.locator('[data-testid="entity-select"]').select_option(str(SECTION_A_ID))
    hp.wait_for_url(re.compile(f"entity_id={SECTION_A_ID}"))
    hp.get_by_role("button", name="Add", exact=True).first.click()
    hp.locator('[data-testid="timetable-slot-form"]').wait_for()
    hp.locator('#slot_subject').select_option(label=SUBJECT_NAME)
    hp.locator('#slot_teacher').select_option(label=TEACHER_NAME)
    hp.get_by_role("button", name="Save", exact=True).click()
    hp.locator('[data-testid="timetable-slot-form"]').wait_for(state="hidden")
    check("timetable: a conflict-free slot shows on the class grid", until(lambda: SUBJECT_NAME in body(hp) and TEACHER_NAME in body(hp)))

    # -- same teacher, same day/period, a DIFFERENT class: a teacher double-booking --
    hp.locator('[data-testid="entity-select"]').select_option(str(SECTION_B_ID))
    hp.wait_for_url(re.compile(f"entity_id={SECTION_B_ID}"))
    hp.get_by_role("button", name="Add", exact=True).first.click()
    hp.locator('[data-testid="timetable-slot-form"]').wait_for()
    hp.locator('#slot_subject').select_option(label=SUBJECT_NAME)
    hp.locator('#slot_teacher').select_option(label=TEACHER_NAME)
    hp.get_by_role("button", name="Save", exact=True).click()
    check("timetable: a teacher double-booking is refused inline, with an override offered", until(lambda: "This clashes with another slot" in body(hp)))

    hp.fill("#slot_override_reason", "Covering a timetable gap this week.")
    hp.get_by_role("button", name="Save anyway").click()
    check("timetable: an authorized override saves it as Overridden", until(lambda: "Overridden" in body(hp)))
    hctx.close()

    # -- self-service: the assigned teacher sees both classes --
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/my-timetable")
    check("my timetable: the teacher sees both classes they're assigned to", until(lambda: "Grade 1 A" in body(tp) and "Grade 2 A" in body(tp)))
    tctx.close()

    # -- self-service: an uninvolved teacher sees nothing --
    t2ctx, t2p = login(browser, "teacher2@demo.test")
    t2p.goto(f"{BASE}/my-timetable")
    check("my timetable: a teacher with nothing scheduled sees the empty state", until(lambda: "Nothing on your timetable yet" in body(t2p)))
    t2ctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "teacher@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/my-timetable")
    mpage.wait_for_load_state("networkidle")
    check("phone: my timetable has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
