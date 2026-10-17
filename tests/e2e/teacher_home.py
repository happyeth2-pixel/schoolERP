"""Browser test: the teacher's home knows their class, and attendance stays a homeroom duty.

  * a class teacher's home: "Good morning, <name>", "Your class: Grade X - Section Y", the number of students, today's
    attendance in words and one large "Take Attendance" button; with two classes, two cards
  * a teacher with ONE class is taken straight to marking (no class list), and its screen says "Today", the class and
    "0 / N marked"; "Mark everyone present", corrections, Save, the confirmation ("Attendance saved", "N students recorded",
    Done), and the home then says it is done
  * a subject teacher who is not the class teacher gets an explanation and no button, and the server refuses them
  * another teacher's class is refused; a branch admin covers any class of the branch and is shown a list; a phone layout

Uses the usability-session data; it briefly moves class-teacher assignments around and puts them back. Only run it
against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/teacher_home.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"


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


def login(browser, email, mobile=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    ctx = browser.new_context(**kw)
    page = ctx.new_page()
    page.js_errors = []
    page.on("pageerror", lambda e: page.js_errors.append(str(e)))
    page.goto(f"{BASE}/login")
    page.fill("#phone", email); page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks]", timeout=20000)
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
body = lambda pg: pg.locator("body").inner_text()
rows = lambda pg: pg.locator("[data-testid=student-row]")
prog = lambda pg: pg.locator("[data-testid=progress-text]").first.inner_text()

ME = q("select id, name from users where email='teacher@demo.test'")[0]
MINE = [r for r in q("select s.id, g.name, s.name, (select count(*) from enrollments e where e.section_id=s.id and e.status='active') from sections s join grades g on g.id=s.grade_id where s.class_teacher_id=? order by s.id", ME[0])]
(S1, G1, N1, C1), (S2, G2, N2, C2) = MINE[0], MINE[1]
OTHER_TEACHER = q("select id from users where email='teacher3@demo.test'")[0][0]
OTHERS_SECTION = q("select id from sections where class_teacher_id=? order by id limit 1", OTHER_TEACHER)[0][0]
# earlier suites may have made this teacher class teacher of more classes: for this test they have exactly the first two
EXTRA = [r[0] for r in q("select id from sections where class_teacher_id=? and id not in (?, ?)", ME[0], S1, S2)]
for sid in EXTRA:
    q("update sections set class_teacher_id=? where id=?", OTHER_TEACHER, sid)
BRANCH_ADMIN = "principal@demo.test"


def clear_marks(section_id):
    q("delete from attendance_records where enrollment_id in (select id from enrollments where section_id=?)", section_id)


for sid in (S1, S2):
    clear_marks(sid)
q("update users set locale=null where email='teacher@demo.test'")


def restore():
    q("update sections set class_teacher_id=? where id in (?, ?)", ME[0], S1, S2)


def restore_extra():
    for sid in EXTRA:
        q("update sections set class_teacher_id=? where id=?", ME[0], sid)


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 1. a class teacher with two classes
    ctx, page = login(browser, "teacher@demo.test")
    check("home: greets the teacher by name", re.fullmatch(rf"Good (morning|afternoon|evening), {re.escape(ME[1])}", page.locator("[data-testid=greeting]").inner_text()) is not None, page.locator("[data-testid=greeting]").inner_text())
    check("home: 'Your classes:' and one card per class, each 'Grade X — Section Y'", page.locator("[data-testid=your-class]").inner_text() == "Your classes:" and page.locator("[data-testid=homeroom-card]").count() == 2 and re.fullmatch(r"Grade \d+ — Section \w+", page.locator("[data-testid=homeroom-class]").first.inner_text()) is not None)
    card = page.locator("[data-testid=homeroom-card]").filter(has_text=f"{G1} — Section {N1}")
    check("home: the card says how many students, and 'Today's Attendance' with progress in words", f"{C1} students" in card.locator("[data-testid=homeroom-count]").inner_text() and "Today's Attendance" in card.inner_text() and f"0 / {C1} marked" in card.locator("[data-testid=progress-text]").inner_text(), card.inner_text().replace("\n", " | ")[:200])
    check("home: one large 'Take Attendance' button per class", card.locator("[data-testid=take-attendance]").inner_text() == "Take Attendance" and card.locator("[data-testid=take-attendance]").evaluate(H) >= 56)
    check("home: the class tiles are not repeated below (Enter marks, Notifications, Announcements) and Take attendance is under More", [t.strip() for t in page.locator("[data-testid=main-tiles] a span span").all_inner_texts()][:1] == ["Enter marks"] and page.locator("[data-testid=more-tasks]").text_content().count("Take attendance") >= 1)
    page.goto(f"{BASE}/attendance"); page.wait_for_selector("[data-testid=class-card]")
    check("two classes: /attendance still asks which class (only their own two)", page.locator("[data-testid=class-card]").count() == 2)
    check("another teacher's class is refused", status_of(ctx, f"/attendance/{OTHERS_SECTION}") == 403)
    ctx.close()

    # ================================================================ 2. ONE class: straight to marking
    q("update sections set class_teacher_id=? where id=?", OTHER_TEACHER, S2)      # now they are class teacher of just the first
    ctx, page = login(browser, "teacher@demo.test")
    check("home: with one class it says 'Your class:' and shows one card", page.locator("[data-testid=your-class]").inner_text() == "Your class:" and page.locator("[data-testid=homeroom-card]").count() == 1)
    page.locator("[data-testid=take-attendance]").click()
    page.wait_for_selector("[data-testid=student-row]")
    check("one class: the button goes straight to the attendance screen (no class list)", f"/attendance/{S1}" in page.url and page.locator("[data-testid=class-card]").count() == 0, page.url)
    check("screen: the heading is the class ('Grade X — Section Y'), it says Today, and progress is '0 / N marked'", page.locator("h1").inner_text() == f"{G1} — Section {N1}" and "Today" in body(page) and prog(page) == f"0 / {C1} marked", f"{page.locator('h1').inner_text()} | {prog(page)}")
    page.goto(f"{BASE}/attendance"); page.wait_for_selector("[data-testid=student-row]")
    check("one class: even /attendance goes straight to the class", f"/attendance/{S1}" in page.url, page.url)
    check("one class: the way back says 'Back to home', not a class list", page.get_by_role("link", name="Back to home").count() == 1 and page.get_by_role("link", name="Back to my classes").count() == 0)
    btns = page.locator("[data-testid=student-row]").first.locator("button[role=radio]")
    check("screen: each student has large Present / Absent / Late / Excused (and Sick) buttons, no dropdown", [b.strip() for b in btns.all_inner_texts()] == ["Present", "Absent", "Late", "Excused", "Sick"] and min(btns.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56 and page.locator("[data-testid=student-row] select").count() == 0)
    page.get_by_role("button", name="Mark everyone present").click()
    check("Mark everyone present: marks every student, the count follows", prog(page) == f"{C1} / {C1} marked")
    rows(page).nth(0).get_by_role("radio", name="Absent", exact=True).click()
    rows(page).nth(1).get_by_role("radio", name="Late", exact=True).click()
    page.get_by_role("button", name=re.compile("^Save attendance")).click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("Save: a first save with an absence explains who is told before it happens", "marked absent" in dlg.inner_text() and ("told right away" in dlg.inner_text() or "nobody will be told" in dlg.inner_text()), dlg.inner_text()[:160].replace("\n", " | "))
    dlg.get_by_role("button", name=re.compile("^Yes, save")).click()
    page.wait_for_selector("text=Attendance saved")
    conf = page.locator("[role=status]").filter(has_text="Attendance saved")
    check("confirmation: 'Attendance saved', 'N students recorded', and a large Done", "Attendance saved" in body(page) and f"{C1} students recorded" in body(page) and page.get_by_role("link", name="Done").evaluate(H) >= 56, body(page)[:160].replace("\n", " | "))
    saved = q("select status, count(*) from attendance_records where enrollment_id in (select id from enrollments where section_id=?) group by status", S1)
    check("saved: exactly what was marked (1 absent, 1 late, the rest present)", dict(saved) == {"absent": 1, "late": 1, "present": C1 - 2}, str(saved))
    page.get_by_role("link", name="Done").click(); page.wait_for_selector("[data-testid=teacher-home]")
    card = page.locator("[data-testid=homeroom-card]")
    check("Done: back on the home page, which now says attendance is complete and offers to check or change it", card.get_attribute("data-status") == "complete" and f"{C1} / {C1} marked" in card.inner_text() and card.locator("[data-testid=take-attendance]").inner_text() == "Check or change attendance")

    # a correction after parents were told: the consequence is explained BEFORE saving
    page.locator("[data-testid=take-attendance]").click(); page.wait_for_selector("[data-testid=student-row]")
    absent_row = rows(page).nth(0)      # the first student was the one marked absent above (same order)
    absent_row.get_by_role("radio", name="Present", exact=True).click()
    check("correction: the row says what it was before and who saved it", "Saved earlier as Absent" in absent_row.inner_text())
    page.get_by_role("button", name=re.compile("^Save attendance")).click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("correction: the dialog lists the change and says parents told about the absence will be told it was corrected", "Absent → Present" in dlg.inner_text() and "corrected" in dlg.inner_text().lower(), dlg.inner_text()[:220].replace("\n", " | "))
    dlg.get_by_role("button", name="Go back and check").click()
    check("correction: 'Go back and check' saves nothing", q("select count(*) from attendance_records where status='absent' and enrollment_id in (select id from enrollments where section_id=?)", S1)[0][0] == 1)
    ctx.close()

    # ================================================================ 3. a subject teacher who is not the class teacher
    q("update sections set class_teacher_id=? where id=?", OTHER_TEACHER, S1)      # now they only TEACH a subject in class 1
    q("insert or ignore into teaching_assignments (teacher_id, section_id, subject_id, created_at, updated_at) select ?, ?, subject_id, datetime('now'), datetime('now') from teaching_assignments limit 1", ME[0], S1)
    ctx, page = login(browser, "teacher@demo.test")
    check("subject teacher: their home has no class and no Take Attendance button", page.locator("[data-testid=homeroom-card]").count() == 0 and page.locator("[data-testid=take-attendance]").count() == 0 and page.locator("[data-testid=no-homeroom]").count() == 1)
    check("subject teacher: it says attendance belongs to the class teacher and to ask the branch admin if they are away", "class teacher" in page.locator("[data-testid=teacher-home]").inner_text().lower() and "branch admin" in page.locator("[data-testid=teacher-home]").inner_text().lower(), page.locator("[data-testid=teacher-home]").inner_text().replace("\n", " | ")[:260])
    check("subject teacher: the server refuses the class page, the save and the roster export", status_of(ctx, f"/attendance/{S1}") == 403 and status_of(ctx, f"/attendance/{S1}/export") == 403)
    before = q("select count(*) from attendance_records")[0][0]
    xsrf = None
    page.goto(f"{BASE}/attendance"); page.wait_for_selector("h1")
    check("subject teacher: /attendance explains instead of listing or redirecting", "/attendance/" not in page.url.split("/attendance")[1] and ("class (homeroom) teacher" in body(page) or "class teacher" in body(page).lower()), page.url)
    check("subject teacher: nothing was recorded", q("select count(*) from attendance_records")[0][0] == before)
    ctx.close()
    restore()

    # ================================================================ 4. a branch admin covers any class
    actx, ap = login(browser, BRANCH_ADMIN)
    ap.goto(f"{BASE}/attendance"); ap.wait_for_selector("[data-testid=class-card]")
    check("branch admin: sees every class of the branch as a list (no forced redirect)", ap.locator("[data-testid=class-card]").count() >= 5 and "/attendance/" not in ap.url.split("/attendance")[1])
    card = ap.locator(f"[data-testid=class-card][aria-label='{G2} {N2}']")
    check("branch admin: covering an absent class teacher: open the class and mark", card.count() == 1)
    card.get_by_role("link", name=re.compile("attendance", re.I)).click(); ap.wait_for_selector("[data-testid=student-row]")
    check("branch admin: the screen offers 'Back to my classes' (a list exists for them) and the same large buttons", ap.get_by_role("link", name="Back to my classes").count() == 1 and prog(ap) == f"0 / {C2} marked")
    ap.get_by_role("button", name="Mark everyone present").click()
    ap.get_by_role("button", name=re.compile("^Save attendance")).click()
    dlg = ap.get_by_role("dialog")
    if dlg.count():
        dlg.get_by_role("button", name=re.compile("^Yes, save")).click()
    ap.wait_for_selector("text=Attendance saved")
    who = q("select distinct marked_by from attendance_records where enrollment_id in (select id from enrollments where section_id=?)", S2)
    check("branch admin: the record names the administrator who covered", len(who) == 1 and who[0][0] == q("select id from users where email=?", BRANCH_ADMIN)[0][0], str(who))
    check("branch admin: Done goes back to the list of classes", ap.get_by_role("link", name="Done").get_attribute("href").endswith("/attendance"))
    actx.close()
    q("update sections set class_teacher_id=? where id=?", ME[0], S2)
    ctx, page = login(browser, "teacher@demo.test")
    page.goto(f"{BASE}/attendance/{S2}"); page.wait_for_selector("[data-testid=student-row]")
    check("the class teacher, back at school, sees who covered and that the day is already saved", "already saved" in page.locator("[data-testid=last-saved]").inner_text() and re.search(r"last by", page.locator("[data-testid=last-saved]").inner_text()) is not None)
    ctx.close()

    # ================================================================ 5. phone
    restore()
    q("update sections set class_teacher_id=? where id=?", OTHER_TEACHER, S2)
    clear_marks(S1)
    mctx, mp = login(browser, "teacher@demo.test", mobile=True)
    check("phone: the teacher home (greeting, class, Take Attendance) fits the screen", overflow(mp) <= 0 and mp.locator("[data-testid=take-attendance]").evaluate(H) >= 56 and mp.locator("[data-testid=greeting]").is_visible() and mp.locator("[data-testid=take-attendance]").is_visible())
    mp.locator("[data-testid=take-attendance]").click(); mp.wait_for_selector("[data-testid=student-row]")
    check("phone: the marking screen fits, the five status buttons are large and the Mark everyone present button is visible", overflow(mp) <= 0 and min(rows(mp).first.locator("button[role=radio]").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56 and mp.get_by_role("button", name="Mark everyone present").is_visible())
    mp.get_by_role("button", name="Mark everyone present").click()
    check("phone: the sticky bar keeps 'N / N marked' and Save in view", f"{C1} / {C1} marked" in mp.locator("[data-testid=bar-text]").inner_text() and mp.get_by_role("button", name=re.compile("^Save attendance")).is_visible())
    mp.get_by_role("button", name=re.compile("^Save attendance")).click()
    d = mp.get_by_role("dialog")
    if d.count():
        d.get_by_role("button", name=re.compile("^Yes, save")).click()
    mp.wait_for_selector("text=Attendance saved")
    check("phone: the confirmation and its Done button fit the screen", overflow(mp) <= 0 and mp.get_by_role("link", name="Done").evaluate(H) >= 56 and f"{C1} students recorded" in body(mp))
    mctx.close()

    # ================================================================ 6. other languages
    for loc in ("am", "om", "ti"):
        q("update users set locale=? where email='teacher@demo.test'", loc)
        lctx, lp = login(browser, "teacher@demo.test")
        raw = re.findall(r"\b(?:attendance|home|dashboard)\.[a-z_]+\b", lp.locator("main").inner_text())
        lp.locator("[data-testid=take-attendance]").click(); lp.wait_for_selector("[data-testid=student-row]")
        raw += re.findall(r"\b(?:attendance|home|dashboard)\.[a-z_]+\b", lp.locator("main").inner_text())
        check(f"{loc}: the teacher home and the attendance screen are translated (no raw keys) and fit", not raw and overflow(lp) <= 0, str(raw[:4]))
        lctx.close()
    q("update users set locale=null where email='teacher@demo.test'")
    check("no JavaScript errors", not page.js_errors, str(page.js_errors[:2]))
    browser.close()

restore()
restore_extra()
for sid in (S1, S2):
    clear_marks(sid)
q("delete from teaching_assignments where teacher_id=? and section_id=? and rowid not in (select min(rowid) from teaching_assignments where teacher_id=? and section_id=?)", ME[0], S1, ME[0], S1)
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
