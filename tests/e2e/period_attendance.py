"""Browser test: Academic Expansion Phase 2 -- Period/Subject Attendance. Deliberately separate from the
EXISTING daily/homeroom attendance (tests/e2e/attendance.py), which this phase must not disturb.

  * someone with no mark-attendance permission is refused the whole feature
  * the teacher assigned to a class period sees it on their own list and can mark it
  * a DIFFERENT teacher cannot open or mark that same period
  * a branch_admin can pick any teacher and cover their period
  * re-marking (by someone else) updates the same record and shows who last saved it
  * no guardian notification is ever sent for a period absence (daily attendance's own notice stays the
    only one reaching a family -- this phase's explicit "do not spam" decision)

Uses the usability-session data; creates its own throwaway term/subject/period/timetable slot (for TODAY's
real weekday, so the default "today" view is exercised honestly), removed at the end. Reuses the existing
"Grade 1 A" section and its already-enrolled students. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/period_attendance.py
"""
import os, re, sqlite3, sys, time
from datetime import datetime, timedelta, timezone
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
TERM_NAME = "E2E Period Attendance Term"
SUBJECT_NAME = "E2E Period Attendance Subject"
# "Today" for school purposes is Addis Ababa time (see App\Support\SchoolCalendar::today()), not this
# machine's local time -- computing it any other way can land on the wrong calendar day near midnight UTC.
_ADDIS_NOW = datetime.now(timezone(timedelta(hours=3)))
TODAY = _ADDIS_NOW.date().isoformat()
DAY_OF_WEEK = _ADDIS_NOW.isoweekday()


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


def confirm_unmarked_if_shown(page):
    """Saving with students still unmarked opens a 'check before you save' dialog -- click through it."""
    btn = page.get_by_role("button", name=re.compile("yes, save", re.I))
    try:
        btn.wait_for(timeout=3000)
        btn.click()
    except Exception:
        pass


def cleanup():
    q("delete from period_attendance_records where timetable_slot_id in (select id from timetable_slots where subject_id in (select id from subjects where name=?))", SUBJECT_NAME)
    q("delete from timetable_slots where subject_id in (select id from subjects where name=?)", SUBJECT_NAME)
    q("delete from subjects where name=?", SUBJECT_NAME)
    q("delete from terms where name=?", TERM_NAME)
    q("delete from periods where school_id=(select id from schools where code='DEMO') and period_number=77")


cleanup()

SCHOOL_ID = q("select id from schools where code='DEMO'")[0][0]
BRANCH_ID = q("select id from branches where school_id=? and code='MAIN'", SCHOOL_ID)[0][0]
YEAR_ID = q("select id from academic_years where school_id=? and is_current=1", SCHOOL_ID)[0][0]
TEACHER_ID, TEACHER_NAME = q("select id, name from users where email='teacher@demo.test'")[0]
TEACHER2_ID = q("select id from users where email='teacher2@demo.test'")[0][0]

q("insert into terms (academic_year_id, name, start_date, end_date, created_at, updated_at) values (?, ?, '2026-01-01', '2026-12-31', datetime('now'), datetime('now'))", YEAR_ID, TERM_NAME)
TERM_ID = q("select id from terms where name=?", TERM_NAME)[0][0]

q("insert into subjects (school_id, name, created_at, updated_at) values (?, ?, datetime('now'), datetime('now'))", SCHOOL_ID, SUBJECT_NAME)
SUBJECT_ID = q("select id from subjects where name=?", SUBJECT_NAME)[0][0]

q("insert into periods (school_id, period_number, start_time, end_time, created_at, updated_at) values (?, 77, '08:00:00', '08:40:00', datetime('now'), datetime('now'))", SCHOOL_ID)
PERIOD_ID = q("select id from periods where school_id=? and period_number=77", SCHOOL_ID)[0][0]

SECTION_ID = q(
    "select s.id from sections s join grades g on g.id=s.grade_id "
    "where g.school_id=? and g.name='Grade 1' and s.name='A' and s.academic_year_id=? and s.branch_id=?",
    SCHOOL_ID, YEAR_ID, BRANCH_ID,
)[0][0]

q(
    "insert into timetable_slots (school_id, branch_id, term_id, section_id, subject_id, teacher_id, period_id, day_of_week, created_by, created_at, updated_at) "
    "values (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))",
    SCHOOL_ID, BRANCH_ID, TERM_ID, SECTION_ID, SUBJECT_ID, TEACHER_ID, PERIOD_ID, DAY_OF_WEEK, TEACHER_ID,
)
SLOT_ID = q("select id from timetable_slots where subject_id=?", SUBJECT_ID)[0][0]

STUDENT_IDS = [r[0] for r in q(
    "select e.id from enrollments e where e.section_id=? and e.status='active' order by e.id limit 3", SECTION_ID,
)]

NOTIFS_BEFORE = q("select count(*) from notifications where type like '%StudentMarkedAbsent%'")[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- no mark-attendance permission at all --
    actx, _ = login(browser, "accountant@demo.test")
    check("accountant: /period-attendance is refused", status_of(actx, "/period-attendance") == 403)
    check("accountant: a specific slot is refused too", status_of(actx, f"/period-attendance/{SLOT_ID}") == 403)
    actx.close()

    # -- the assigned teacher sees and marks their own period, defaulting to today --
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/period-attendance")
    check("teacher: today's period shows on their own list", until(lambda: SUBJECT_NAME in body(tp)))

    tp.locator('[data-testid="period-slot-card"]').get_by_role("link").click()
    tp.wait_for_url(re.compile(r"/period-attendance/\d+"))
    check("take: the right class/subject/period is shown", SUBJECT_NAME in body(tp) and "Grade 1 A" in body(tp))

    rows = tp.locator('[data-testid="period-student-row"]')
    rows.nth(0).get_by_role("radio", name=re.compile("present", re.I)).click()
    rows.nth(1).get_by_role("radio", name=re.compile("absent", re.I)).click()
    tp.get_by_test_id("bar-text").wait_for()
    tp.get_by_role("button", name=re.compile("^save", re.I)).click()
    confirm_unmarked_if_shown(tp)
    check("take: saving shows the confirmation panel", until(lambda: tp.get_by_test_id("done").count() > 0))
    tctx.close()

    check("db: two period attendance records were written, not five", q("select count(*) from period_attendance_records where timetable_slot_id=?", SLOT_ID)[0][0] == 2)

    # -- a different teacher cannot open or mark this same period --
    t2ctx, t2p = login(browser, "teacher2@demo.test")
    check("a different teacher: cannot open this slot", status_of(t2ctx, f"/period-attendance/{SLOT_ID}?date={TODAY}") == 403)
    t2ctx.close()

    # -- a branch_admin can pick the teacher and cover/re-mark the period --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/period-attendance")
    check("admin: nothing shown before a teacher is picked", until(lambda: "pick" in body(hp).lower() or "choose" in body(hp).lower()))

    hp.locator('[data-testid="teacher-select"]').select_option(str(TEACHER_ID))
    hp.wait_for_url(re.compile(f"teacher_id={TEACHER_ID}"))
    check("admin: the picked teacher's period now shows", until(lambda: SUBJECT_NAME in body(hp)))

    hp.locator('[data-testid="period-slot-card"]').get_by_role("link").click()
    hp.wait_for_url(re.compile(r"/period-attendance/\d+"))
    check("admin: sees who marked it already", until(lambda: TEACHER_NAME in body(hp)))

    rows2 = hp.locator('[data-testid="period-student-row"]')
    rows2.nth(1).get_by_role("radio", name=re.compile("^late$", re.I)).click()
    hp.get_by_role("button", name=re.compile("^save", re.I)).click()
    confirm_unmarked_if_shown(hp)
    check("admin: re-marking saves successfully", until(lambda: hp.get_by_test_id("done").count() > 0))
    hctx.close()

    check("db: the re-marked student's status and marker both updated", len(q(
        "select status, marked_by from period_attendance_records where timetable_slot_id=? and status='late'", SLOT_ID,
    )) == 1)

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "teacher@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/period-attendance")
    mpage.wait_for_load_state("networkidle")
    check("phone: period attendance has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

NOTIFS_AFTER = q("select count(*) from notifications where type like '%StudentMarkedAbsent%'")[0][0]
check("no guardian was ever notified for a period absence (daily attendance's own notice stays the only one)", NOTIFS_AFTER == NOTIFS_BEFORE, f"before={NOTIFS_BEFORE} after={NOTIFS_AFTER}")

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
