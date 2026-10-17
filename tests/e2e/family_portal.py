"""Browser test: Academic Expansion Phase 9 (FINAL) -- Student Portal improvements. Confirmed up front that
"Student Portal" means enriching the EXISTING parent portal, never flipping on student login. This phase
adds the two "potential features" the brief listed that genuinely had no home yet: a child's weekly
timetable (deferred here on purpose since Phase 1 -- see `TimetableChanged`'s own code comment) and a
child's published exam results, visible exam by exam as they land, not just as a final report card.

  * someone who is not a parent (an accountant) is refused both new pages outright
  * a parent sees their child's real weekly schedule -- subject, teacher, period
  * a parent sees a PUBLISHED exam's score, but never a draft one still being entered
  * both new pages are reachable from the parent's own menu

Uses the usability-session data (teacher@demo.test teaches Mathematics to Grade 3 A) and adds its own
throwaway term/exam/mark batch/timetable slot on top of it, removed at the end. Only run it against that
throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/family_portal.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
TERM_NAME = "E2E Portal Term"
EXAM_NAME = "E2E Portal Exam"
DRAFT_EXAM_NAME = "E2E Portal Draft Exam"


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
    q("delete from marks where mark_batch_id in (select id from mark_batches where exam_id in (select id from exams where name in (?, ?)))", EXAM_NAME, DRAFT_EXAM_NAME)
    q("delete from mark_batches where exam_id in (select id from exams where name in (?, ?))", EXAM_NAME, DRAFT_EXAM_NAME)
    q("delete from exams where name in (?, ?)", EXAM_NAME, DRAFT_EXAM_NAME)
    q("delete from timetable_slots where term_id in (select id from terms where name=?)", TERM_NAME)
    q("delete from periods where school_id=(select id from schools where code='DEMO') and period_number=78")
    q("delete from terms where name=?", TERM_NAME)


cleanup()

SCHOOL_ID = q("select id from schools where code='DEMO'")[0][0]
BRANCH_ID = q("select id from branches where school_id=? and code='MAIN'", SCHOOL_ID)[0][0]
YEAR_ID = q("select id from academic_years where school_id=? and is_current=1", SCHOOL_ID)[0][0]
TEACHER_ID, TEACHER_NAME = q("select id, name from users where email='teacher@demo.test'")[0]
SECTION_ID = q(
    "select s.id from sections s join grades g on g.id=s.grade_id "
    "where g.name='Grade 3' and s.name='A' and s.branch_id=?", BRANCH_ID,
)[0][0]
SUBJECT_ID = q(
    "select ta.subject_id from teaching_assignments ta where ta.teacher_id=? and ta.section_id=?",
    TEACHER_ID, SECTION_ID,
)[0][0]
SUBJECT_NAME = q("select name from subjects where id=?", SUBJECT_ID)[0][0]

STUDENT_ID, ENROLLMENT_ID, GUARDIAN_EMAIL = q(
    "select st.id, e.id, u.email from students st "
    "join enrollments e on e.student_id=st.id and e.status='active' "
    "join parent_student ps on ps.student_id=st.id join users u on u.id=ps.parent_id "
    "where e.section_id=? limit 1", SECTION_ID,
)[0]

q("insert into terms (academic_year_id, name, start_date, end_date, created_at, updated_at) values (?, ?, '2026-09-01', '2027-06-30', datetime('now'), datetime('now'))", YEAR_ID, TERM_NAME)
TERM_ID = q("select id from terms where name=?", TERM_NAME)[0][0]

q("insert into periods (school_id, period_number, start_time, end_time, created_at, updated_at) values (?, 78, '09:00:00', '09:40:00', datetime('now'), datetime('now'))", SCHOOL_ID)
PERIOD_ID = q("select id from periods where school_id=? and period_number=78", SCHOOL_ID)[0][0]

q(
    "insert into timetable_slots (school_id, branch_id, term_id, section_id, subject_id, teacher_id, period_id, day_of_week, created_by, created_at, updated_at) "
    "values (?, ?, ?, ?, ?, ?, ?, 1, ?, datetime('now'), datetime('now'))",
    SCHOOL_ID, BRANCH_ID, TERM_ID, SECTION_ID, SUBJECT_ID, TEACHER_ID, PERIOD_ID, TEACHER_ID,
)

# a PUBLISHED exam the parent should see
q("insert into exams (school_id, term_id, name, type, weight_percent, created_at, updated_at) values (?, ?, ?, 'midterm', 40, datetime('now'), datetime('now'))", SCHOOL_ID, TERM_ID, EXAM_NAME)
EXAM_ID = q("select id from exams where name=?", EXAM_NAME)[0][0]
q("insert into mark_batches (exam_id, section_id, subject_id, teacher_id, status, published_at, created_at, updated_at) values (?, ?, ?, ?, 'published', datetime('now'), datetime('now'), datetime('now'))", EXAM_ID, SECTION_ID, SUBJECT_ID, TEACHER_ID)
BATCH_ID = q("select id from mark_batches where exam_id=?", EXAM_ID)[0][0]
q("insert into marks (mark_batch_id, enrollment_id, score, max_score, created_at, updated_at) values (?, ?, 82, 100, datetime('now'), datetime('now'))", BATCH_ID, ENROLLMENT_ID)

# a DRAFT exam the parent must never see
q("insert into exams (school_id, term_id, name, type, weight_percent, created_at, updated_at) values (?, ?, ?, 'final', 60, datetime('now'), datetime('now'))", SCHOOL_ID, TERM_ID, DRAFT_EXAM_NAME)
DRAFT_EXAM_ID = q("select id from exams where name=?", DRAFT_EXAM_NAME)[0][0]
q("insert into mark_batches (exam_id, section_id, subject_id, teacher_id, status, created_at, updated_at) values (?, ?, ?, ?, 'draft', datetime('now'), datetime('now'))", DRAFT_EXAM_ID, SECTION_ID, SUBJECT_ID, TEACHER_ID)
DRAFT_BATCH_ID = q("select id from mark_batches where exam_id=?", DRAFT_EXAM_ID)[0][0]
q("insert into marks (mark_batch_id, enrollment_id, score, max_score, created_at, updated_at) values (?, ?, 41, 100, datetime('now'), datetime('now'))", DRAFT_BATCH_ID, ENROLLMENT_ID)

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- an accountant (not a parent) is refused both new pages --
    actx, ap = login(browser, "accountant@demo.test")
    check("accountant: /my/timetable is refused", status_of(actx, "/my/timetable") == 403)
    check("accountant: /my/results is refused", status_of(actx, "/my/results") == 403)
    actx.close()

    # -- a parent sees the new menu items, their child's real timetable, and a published result --
    gctx, gp = login(browser, GUARDIAN_EMAIL)
    gp.goto(f"{BASE}/dashboard")
    check("parent: 'Timetable' is on the menu", until(lambda: gp.get_by_role("link", name=re.compile("^timetable$", re.I)).count() > 0))
    check("parent: 'Results' is on the menu", gp.get_by_role("link", name=re.compile("^results$", re.I)).count() > 0)

    gp.get_by_role("link", name=re.compile("^timetable$", re.I)).click()
    gp.wait_for_url(re.compile(r"/my/timetable"))
    check("parent timetable: the term name is shown", until(lambda: TERM_NAME in body(gp)))
    check("parent timetable: the subject and teacher are shown", SUBJECT_NAME in body(gp) and TEACHER_NAME in body(gp))

    gp.goto(f"{BASE}/my/results")
    check("parent results: the published score is shown", until(lambda: "82 / 100" in body(gp)))
    check("parent results: the exam name is shown", EXAM_NAME in body(gp))
    check("parent results: a draft exam's score never appears", "41 / 100" not in body(gp) and DRAFT_EXAM_NAME not in body(gp))
    gctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", GUARDIAN_EMAIL); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/my/results")
    mpage.wait_for_load_state("networkidle")
    check("phone: the parent's results page has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
