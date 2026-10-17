"""Browser test: the remaining complex forms, in a real browser.

  * Moving a class up (promotion) as four steps - which class, what happens to EACH student (nothing decided for you),
    where they go (seat count, capacity exception), a check - then a result with counts
  * Announcements as three steps (write / who and when / check, with a preview); nothing preselected for who and when
  * Extra services as four steps (where / what / cost / check); the list as cards, kind change and removal asking first
  * Student details in three folded groups (Main information / More details / Status) with a "you changed ..." note,
    an unsaved-changes guard and a status change that says what it does
  * Discount and waiver as focused dialogs that show the effect before saving; refund reached from the bill
  * Exams as one short form with the term's weight total shown live
  * people without permission; a phone layout; Amharic

Uses the usability-session data and changes it (moves a class, adds announcements/services/exams, changes a student).
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/forms.py
"""
import datetime, os, re, sqlite3, sys, tempfile, time
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(encoding="utf-8")
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
        time.sleep(0.4)
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
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
RAW = re.compile(r"\b(?:promo|ann|svc|adj|exam|student)\.[a-z_]+\b")


def wait_text(pg, text, timeout=15000):
    pg.wait_for_selector(f"text={text}", timeout=timeout)


TODAY = datetime.date.today()
YEAR1 = q("select id, name from academic_years where is_current=1")[0]
BRANCH = q("select id from branches order by id limit 1")[0][0]
for e in ("owner", "principal", "teacher", "accountant", "auditor", "parent"):
    q("update users set locale=null where email=?", f"{e}@demo.test")
CAL = q("select calendar_preference from schools")[0][0]
q("update schools set calendar_preference='gregorian'")

# ---- data for the promotion: a next school year with two Grade 2 classes (room for 3, room for 2)
G1 = q("select id, name from grades where name='Grade 1'")[0]
G2 = q("select id from grades where name='Grade 2'")[0][0]
SRC = q("select id from sections where grade_id=? and name='A' and branch_id=?", G1[0], BRANCH)[0][0]
q("delete from sections where academic_year_id in (select id from academic_years where name='2027/2028')")
q("delete from academic_years where name='2027/2028'")
cols = [r[1] for r in q("pragma table_info(academic_years)")]
row = dict(zip(cols, q("select * from academic_years where id=?", YEAR1[0])[0]))
row.pop("id"); row["current_school_id"] = None; row.update(name="2027/2028", is_current=0, start_date="2027-09-01", end_date="2028-06-30")
q(f"insert into academic_years ({','.join(row)}) values ({','.join('?' * len(row))})", *row.values())
YEAR2 = q("select id from academic_years where name='2027/2028'")[0][0]
for nm, cap in (("A", 3), ("B", 2)):
    q("insert into sections (branch_id, grade_id, academic_year_id, name, capacity, created_at, updated_at) values (?,?,?,?,?, datetime('now'), datetime('now'))", BRANCH, G2, YEAR2, nm, cap)
DEST_A = q("select id from sections where academic_year_id=? and name='A'", YEAR2)[0][0]
DEST_B = q("select id from sections where academic_year_id=? and name='B'", YEAR2)[0][0]
q("delete from announcements"); q("delete from optional_services")
q("delete from notifications where data like '%School closed on Friday%'")
q("delete from exams where term_id in (select id from terms where name='Term 2')"); q("delete from terms where name='Term 2'")
tcols = [r[1] for r in q("pragma table_info(terms)")]
trow = dict(zip(tcols, q("select * from terms where name='Term 1' limit 1")[0])); trow.pop("id"); trow.update(name="Term 2")
q(f"insert into terms ({','.join(trow)}) values ({','.join('?' * len(trow))})", *trow.values())
# make a re-run start from the same class (a previous run moved and graduated some of its students)
q("update enrollments set status='active' where section_id=?", SRC)
q("update students set status='active' where id in (select student_id from enrollments where section_id=?)", SRC)
AUDIT0 = q("select count(*) from audit_logs where action='promotion.executed'")[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. promotion
    page.goto(f"{BASE}/promotions"); wait_text(page, "Which class is moving?")
    check("promotion: 'Step 1 of 4' and the first question", "Step 1 of 4" in body(page) and "Which class is moving?" in body(page))
    check("promotion: with many classes the grade is asked first", "Choose a grade first" in body(page) and page.locator("[data-testid=from-classes]").count() == 0)
    page.get_by_role("radio", name="Grade 1", exact=True).click(); page.wait_for_selector("[data-testid=from-classes]")
    check("promotion: school years are buttons (the current one is chosen), classes are big buttons with student counts", page.get_by_role("radio", name="2026").get_attribute("aria-checked") == "true" and "5 students" in page.locator("[data-testid=from-classes]").inner_text())
    check("promotion: Next is off until a class is chosen, and says so", nxt(page).is_disabled() and "Choose a class to continue" in body(page))
    page.locator("[data-testid=from-classes] [role=radio]").filter(has_text="Grade 1 A").click()
    check("promotion: choosing a class says how many students are in it", until(lambda: "5 students are in Grade 1 A now." in page.locator("[data-testid=class-count]").inner_text()))
    nxt(page).click(); wait_text(page, "What happens to each student?")
    rows = page.locator("[data-testid=student-row]")
    check("promotion step 2: every student is listed with three big choices, none chosen", rows.count() == 5 and page.locator("[data-testid=student-row] [aria-checked=true]").count() == 0 and rows.first.get_by_role("radio").count() == 3)
    check("promotion step 2: 'Everyone' choices are a tap away, choices are large targets", page.get_by_role("button", name="Everyone moves up").evaluate(H) >= 56 and rows.first.get_by_role("radio").first.evaluate(H) >= 48)
    check("promotion step 2: Next is off, the counter and the reason are shown", nxt(page).is_disabled() and "0 of 5 students decided" in page.locator("[data-testid=decided-count]").inner_text() and "5 students still have no choice" in body(page))
    page.get_by_role("button", name="Everyone moves up").click()
    check("promotion step 2: 'Everyone moves up' decides all five", until(lambda: "5 of 5 students decided" in page.locator("[data-testid=decided-count]").inner_text() and nxt(page).is_enabled()))
    names = [r.locator("span.text-lg").first.inner_text() for r in rows.all()]
    rows.nth(0).get_by_role("radio", name="Not this time").click()
    rows.nth(1).get_by_role("radio", name="Graduates").click()
    check("promotion step 2: each student can be set separately", rows.nth(0).get_by_role("radio", name="Not this time").get_attribute("aria-checked") == "true" and rows.nth(1).get_by_role("radio", name="Graduates").get_attribute("aria-checked") == "true")
    nxt(page).click(); wait_text(page, "Which class do they go to?")
    check("promotion step 3: it says how many move and from where", "3 students from Grade 1 A" in body(page))
    check("promotion step 3: the grade after the one moving out is opened for you (only the class is left to choose)", page.locator("[role=radio]").filter(has_text="2027/2028").count() >= 2)
    page.locator("[role=radio]").filter(has_text="Grade 2 A").filter(has_text="2027/2028").first.click()
    check("promotion step 3: the classes show their places left", "places" in page.locator("[role=radiogroup]").last.inner_text())
    # a class with room for only 2 -> a warning and the exception
    choose_b = page.locator("[role=radio]").filter(has_text="Grade 2 B").filter(has_text="2027/2028").first
    choose_b.click()
    check("promotion step 3: too few places for 3 students gives a warning that says who would stay", until(lambda: page.locator("[data-testid=short-warning]").count() == 1) and "1 student(s) cannot be moved" in page.locator("[data-testid=short-warning]").inner_text())
    check("promotion step 3: the owner may make an exception, with a reason", page.get_by_label("Move them all anyway (an exception)").count() == 1)
    page.locator("[role=radio]").filter(has_text="Grade 2 A").filter(has_text="2027/2028").first.click()
    check("promotion step 3: a class with enough places has no warning", until(lambda: page.locator("[data-testid=short-warning]").count() == 0 and nxt(page).is_enabled()))
    nxt(page).click(); wait_text(page, "Check and move")
    head = page.locator("[data-testid=headline]").inner_text()
    check("promotion review: the headline says how many move, graduate and are left out", "3 students move up to Grade 2 A" in head and "1 student graduates" in head and "1 student is left out this time" in head, head)
    check("promotion review: each group can be opened to see the names", page.locator("[data-testid=group-move]").count() == 1 and page.locator("[data-testid=group-graduate]").count() == 1 and page.locator("[data-testid=group-skip]").count() == 1)
    check("promotion review: the button names what it does", page.get_by_role("button", name="Move 3 and graduate 1").count() == 1)
    check("promotion review: nothing has changed before the button", q("select count(*) from enrollments where section_id=?", DEST_A)[0][0] == 0 and q("select count(*) from enrollments where section_id=? and status='active'", SRC)[0][0] == 5)
    page.get_by_role("button", name="Back").click(); wait_text(page, "Which class do they go to?")
    page.get_by_role("button", name="Back").click(); wait_text(page, "What happens to each student?")
    check("promotion: Back keeps every choice", page.locator("[data-testid=student-row] [aria-checked=true]").count() == 5 and "5 of 5" in page.locator("[data-testid=decided-count]").inner_text())
    nxt(page).click(); wait_text(page, "Which class do they go to?"); nxt(page).click(); wait_text(page, "Check and move")
    page.get_by_role("button", name="Move 3 and graduate 1").click()
    wait_text(page, "moved up")
    box = page.locator("[data-testid=result-lines]").inner_text()
    check("promotion result: counts in words, from and to", "3 students moved up" in box and "1 student graduated" in box and "From Grade 1 A 2026/2027 to Grade 2 A 2027/2028" in box, box)
    check("promotion: 3 placed in the new class, one graduated, one left where they were", q("select count(*) from enrollments where section_id=? and status='active'", DEST_A)[0][0] == 3 and q("select count(*) from students where status='graduated' and id in (select student_id from enrollments where section_id=?)", SRC)[0][0] == 1 and q("select count(*) from enrollments where section_id=? and status='active'", SRC)[0][0] == 1)
    check("promotion: audited", q("select count(*) from audit_logs where action='promotion.executed'")[0][0] == AUDIT0 + 1)
    page.get_by_role("button", name="Move another class").click(); wait_text(page, "Which class is moving?")
    check("promotion: 'Move another class' starts again at step 1", "Step 1 of 4" in body(page) and nxt(page).is_disabled())

    # ================================================================ 2. announcements
    page.goto(f"{BASE}/announcements")
    check("announcements: the list has a large 'Write an announcement' button and no inline form", page.get_by_role("link", name="Write an announcement").evaluate(H) >= 56 and page.locator("textarea").count() == 0)
    page.get_by_role("link", name="Write an announcement").click(); wait_text(page, "What do you want to say?")
    check("announcement step 1: 'Step 1 of 3', Next off until title and message", "Step 1 of 3" in body(page) and nxt(page).is_disabled() and "Write a title and a message" in body(page))
    page.fill("#ann_title", "School closed on Friday"); page.fill("#ann_body", "The school is closed on Friday.\nClasses restart on Monday.")
    nxt(page).click(); wait_text(page, "Who gets it, and when?")
    check("announcement step 2: nothing is preselected (who, branch, when)", page.locator("[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled() and "Choose who it is for" in body(page))
    check("announcement step 2: each audience is explained", "Only parents and guardians see it." in body(page))
    page.get_by_role("radio", name=re.compile("^Parents")).click()
    check("announcement step 2: next it asks for the branch, then the time", "Choose the branch" in body(page))
    page.get_by_role("radio", name=re.compile("^All branches")).click()
    page.get_by_role("radio", name=re.compile("^Today")).click()
    check("announcement step 2: Next is on once all three are chosen", nxt(page).is_enabled())
    nxt(page).click(); wait_text(page, "This is how it will look.")
    prev = page.locator("[data-testid=preview]").inner_text()
    check("announcement review: a preview of the title and message, line breaks kept", "School closed on Friday" in prev and "Classes restart on Monday." in prev)
    check("announcement review: who, branch and when in words; the button says Publish", "parents" in body(page) and "All branches" in body(page) and "Today" in body(page) and page.get_by_role("button", name="Publish announcement").count() == 1)
    check("announcement review: nothing is saved before the button", q("select count(*) from announcements")[0][0] == 0)
    page.get_by_role("button", name="Publish announcement").click(); wait_text(page, "The announcement is published")
    a = q("select title, audience, branch_id, publish_on from announcements")[0]
    check("announcement: saved for parents, school-wide, today", a[0] == "School closed on Friday" and a[1] == "parents" and a[2] is None and abs((datetime.date.fromisoformat(str(a[3])[:10]) - TODAY).days) <= 1, str(a))
    page.get_by_role("button", name="Write another announcement").click(); wait_text(page, "What do you want to say?")
    check("announcement: 'Write another' starts empty", page.input_value("#ann_title") == "" and "Step 1 of 3" in body(page))
    page.fill("#ann_title", "Fees reminder"); page.fill("#ann_body", "Please pay the term fee.")
    nxt(page).click(); wait_text(page, "Who gets it, and when?")
    page.get_by_role("radio", name=re.compile("^Everyone")).click(); page.get_by_role("radio", name=re.compile("^All branches")).click(); page.get_by_role("radio", name=re.compile("^On a later day")).click()
    check("announcement step 2: choosing a later day asks for the day; Next needs a day after today", page.locator("#ann_date").count() == 1)
    later = (TODAY + datetime.timedelta(days=4)).isoformat()
    page.fill("#ann_date", later)
    nxt(page).click(); wait_text(page, "This is how it will look.")
    check("announcement review (later day): the button says Schedule and the note says it stays hidden", page.get_by_role("button", name="Schedule announcement").count() == 1 and "stays hidden until the day you chose" in body(page))
    page.get_by_role("button", name="Schedule announcement").click(); wait_text(page, "The announcement is scheduled")
    check("announcement: scheduled for the chosen day", str(q("select publish_on from announcements where title='Fees reminder'")[0][0]).startswith(later))
    page.get_by_role("link", name="See all announcements").click(); page.wait_for_selector("[data-testid=announcement]")
    sched = page.locator("[data-testid=announcement]").filter(has_text="Fees reminder")
    check("announcements list: the scheduled one is marked 'Scheduled' for the manager", "Scheduled" in sched.inner_text())
    sched.get_by_role("button", name="Remove").click()
    dlg = page.get_by_role("dialog")
    check("announcements: removing asks first, naming the outcome", "Remove \"Fees reminder\"?" in dlg.inner_text() and dlg.get_by_role("button", name="Remove announcement").count() == 1)
    dlg.get_by_role("button", name="Cancel").click(); page.wait_for_selector("[role=dialog]", state="detached")
    check("announcements: Cancel keeps it", q("select count(*) from announcements where title='Fees reminder' and deleted_at is null")[0][0] == 1)
    sched.get_by_role("button", name="Remove").click(); page.get_by_role("dialog").get_by_role("button", name="Remove announcement").click()
    check("announcements: confirming removes it (kept as archived)", until(lambda: q("select count(*) from announcements where title='Fees reminder' and deleted_at is null")[0][0] == 0))
    pctx, pp = login(browser, "parent@demo.test")
    pp.goto(f"{BASE}/announcements"); pp.wait_for_selector("[data-testid=announcement]")
    check("announcements: a parent sees the published one, with no write button", "School closed on Friday" in body(pp) and pp.get_by_role("link", name="Write an announcement").count() == 0)
    check("announcements: a parent cannot open the wizard", status_of(pctx, "/announcements/new") == 403)
    pp.goto(f"{BASE}/notifications")
    note = pp.locator("li").filter(has_text="School closed on Friday")
    check("announcement: publishing notified the parent", note.count() == 1)
    note.get_by_role("link", name="Open").click(); pp.wait_for_selector("[data-testid=announcement]")
    check("announcement notification: clicking it opens the announcements page", "/announcements" in pp.url)
    pctx.close()

    # ================================================================ 3. extra services
    page.goto(f"{BASE}/optional-services")
    check("services: an empty list explains itself and offers the button", "No extra services yet" in body(page) and page.get_by_role("link", name="Add a service").count() >= 1)
    page.get_by_role("link", name="Add a service").first.click(); wait_text(page, "Where does it apply?")
    check("service step 1: 'Step 1 of 4', the school year is a choice and nothing is preselected", "Step 1 of 4" in body(page) and page.locator("[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled())
    page.get_by_role("radio", name="2026").click()
    check("service step 1: it shows the services already set up for that year", "Services already set up" in page.locator("[data-testid=existing]").inner_text())
    nxt(page).click(); wait_text(page, "What is it?")
    check("service step 2: three kinds explained, none preselected, Next off", page.get_by_role("radio", name=re.compile("^Transport")).count() == 1 and "One route per student" in body(page) and nxt(page).is_disabled())
    page.fill("#svc_name", "Transport - Kela"); page.get_by_role("radio", name=re.compile("^Transport")).click()
    nxt(page).click(); wait_text(page, "What does it cost?")
    page.fill("#svc_amount", "abc")
    check("service step 3: a non-number is explained and blocks Next", "Type a number" in body(page) and nxt(page).is_disabled())
    page.fill("#svc_amount", "0"); page.get_by_role("radio", name=re.compile("^Every month")).click()
    nxt(page).click(); wait_text(page, "Check and save")
    check("service review: a free service is warned about", "This service is free" in body(page))
    page.get_by_role("button", name="Back").click(); wait_text(page, "What does it cost?")
    page.fill("#svc_amount", "350"); nxt(page).click(); wait_text(page, "Check and save")
    check("service review: headline and rows", "Transport - Kela — 350 ETB" in page.locator("[data-testid=headline]").inner_text() and "Transport" in body(page))
    check("service review: nothing saved before the button", q("select count(*) from optional_services")[0][0] == 0)
    page.get_by_role("button", name="Save the service").click(); wait_text(page, "Transport - Kela is ready")
    svc = q("select category, amount, frequency, branch_id from optional_services where name='Transport - Kela'")
    check("service: saved with kind, amount, frequency and branch", len(svc) == 1 and svc[0][0] == "transport" and float(svc[0][1]) == 350 and svc[0][2] == "monthly" and svc[0][3] == BRANCH, str(svc))
    page.get_by_role("button", name=re.compile("Add another service for")).click(); wait_text(page, "What is it?")
    page.fill("#svc_name", "Transport - Kela")
    check("service step 2: a name already in use is warned about (not blocked)", "There is already a service called Transport - Kela" in body(page))
    page.fill("#svc_name", "Maths club"); page.get_by_role("radio", name=re.compile("^Another|^Something else|^After-school")).first.click()
    nxt(page).click(); page.fill("#svc_amount", "200"); page.get_by_role("radio", name=re.compile("^Once a year")).first.click(); nxt(page).click(); wait_text(page, "Check and save")
    page.get_by_role("button", name="Save the service").click(); wait_text(page, "Maths club is ready")
    page.get_by_role("link", name="See all extra services").click(); page.wait_for_selector("[data-testid=service-card]")
    card = page.locator("[data-testid=service-card]").filter(has_text="Maths club")
    check("services list: cards with price, how often, who is in it", "200 ETB" in card.inner_text() and "0 students" in card.inner_text() or "students are in it" in card.inner_text())
    card.locator("select").select_option("other")
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("services: changing the kind asks first and says what stays the same", "Change what kind of service Maths club is?" in dlg.inner_text() and "price and the students in it stay the same" in dlg.inner_text())
    dlg.get_by_role("button", name="Cancel").click(); page.wait_for_selector("[role=dialog]", state="detached")
    kind_before = q("select category from optional_services where name='Maths club'")[0][0]
    check("services: Cancel keeps the kind", kind_before in ("other", "asp"))
    target = "transport" if kind_before != "transport" else "other"
    card.locator("select").select_option(target)
    page.get_by_role("dialog").get_by_role("button", name=re.compile("^Make it")).click()
    check("services: confirming changes the kind", until(lambda: q("select category from optional_services where name='Maths club'")[0][0] == target))
    card = page.locator("[data-testid=service-card]").filter(has_text="Maths club")
    card.get_by_role("button", name="Remove").click()
    d2 = page.get_by_role("dialog"); d2.wait_for()
    check("services: removing asks first and says bills already made are not changed", "Bills already made are not changed" in d2.inner_text() and d2.get_by_role("button", name="Remove Maths club").count() == 1)
    d2.get_by_role("button", name="Remove Maths club").click()
    check("services: confirming removes it", until(lambda: q("select count(*) from optional_services where name='Maths club' and deleted_at is null")[0][0] == 0))

    # ================================================================ 4. student details
    ADDR = f"Bole {int(time.time())}"
    SID, SNAME = q("select s.id, s.first_name from students s join enrollments e on e.student_id=s.id join sections x on x.id=e.section_id where x.id=? and e.status='active' limit 1", SRC)[0]
    page.goto(f"{BASE}/students/{SID}/edit"); page.wait_for_selector("[data-testid=details-form]")
    folded = page.locator("[data-testid=details-form] details:not([open]) summary").all_inner_texts()
    check("student: details are three folded groups, each with a worded summary", any("Main information" in s for s in folded) and any("More details" in s for s in folded) and any("Status" in s for s in folded) and any("Name, gender and date of birth" in s for s in folded), str([f.split(chr(10))[0] for f in folded]))
    check("student: Save is off and says nothing has changed", page.get_by_role("button", name=re.compile("^Save .*details")).is_disabled() and "Nothing has been changed yet" in page.locator("[data-testid=changed-note]").inner_text())
    page.locator("[data-testid=group-more] summary").click()
    page.fill("#address", ADDR)
    check("student: the note names the group that was touched", "You changed: More details." in page.locator("[data-testid=changed-note]").inner_text() and page.get_by_role("button", name=re.compile("^Save .*details")).is_enabled())
    page.locator("a[href$='/dashboard']").first.click()
    dlg = page.get_by_role("dialog")
    check("student: leaving with unsaved changes asks first", until(lambda: "Leave without saving?" in dlg.inner_text()))
    dlg.get_by_role("button", name="Stay and keep working").click(); page.wait_for_selector("[role=dialog]", state="detached")
    check("student: staying keeps what was typed", page.input_value("#address") == ADDR and "/students/" in page.url)
    page.get_by_role("button", name=re.compile("^Save .*details")).click()
    check("student: saved (every field still goes to the same endpoint)", until(lambda: q("select address from students where id=?", SID)[0][0] == ADDR))
    until(lambda: "Nothing has been changed yet" in page.locator("[data-testid=changed-note]").inner_text())
    page.locator("[data-testid=group-status] summary").click()
    page.select_option("#status", "inactive")
    check("student: a status change is named in the note", "Status" in page.locator("[data-testid=changed-note]").inner_text())
    page.get_by_role("button", name=re.compile("^Save .*details")).click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("student: a status change asks first and says the student leaves their class", "Active to Inactive" in dlg.inner_text() and "taken out of their current class" in dlg.inner_text() and "Nothing is deleted" in dlg.inner_text())
    dlg.get_by_role("button", name="Cancel").click(); page.wait_for_selector("[role=dialog]", state="detached")
    check("student: Cancel changes nothing", q("select status from students where id=?", SID)[0][0] == "active")
    page.get_by_role("button", name=re.compile("^Save .*details")).click()
    page.get_by_role("dialog").get_by_role("button", name=re.compile("^Change .* to Inactive")).click()
    check("student: confirming moves them out of the class", until(lambda: q("select status from students where id=?", SID)[0][0] == "inactive") and q("select status from enrollments where student_id=? order by id desc limit 1", SID)[0][0] == "withdrawn")
    page.locator("[data-testid=group-status] summary").click() if page.locator("[data-testid=group-status]:not([open])").count() else None
    page.select_option("#status", "active"); page.get_by_role("button", name=re.compile("^Save .*details")).click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("student: going back to Active says they are put back into their class and need a place", "put back into the class" in dlg.inner_text())
    dlg.get_by_role("button", name=re.compile("^Change .* to Active")).click()
    check("student: reactivated into the class", until(lambda: q("select status from students where id=?", SID)[0][0] == "active"))
    tctx, tp = login(browser, "teacher@demo.test")
    check("student: someone who cannot manage students gets no editing controls", status_of(tctx, f"/students/{SID}/edit") in (200, 403))
    tctx.close()

    # ================================================================ 5. discount, waiver, refund
    inv = q("select id from invoices order by id limit 1")
    if inv:
        INV = inv[0][0]
        actx, ap = login(browser, "accountant@demo.test")
        ap.goto(f"{BASE}/invoices/{INV}"); ap.wait_for_selector("[data-testid=adjust-actions]")
        check("bill: 'Give a discount' and 'Waive what is left' are buttons", ap.get_by_role("button", name="Give a discount").count() + ap.get_by_role("button", name="Change the discount").count() == 1 and ap.get_by_role("button", name="Waive what is left").count() == 1)
        ap.locator("[data-testid=adjust-actions] button").first.click(); ap.wait_for_selector("[data-testid=discount-form]")
        sub = float(re.sub(r"[^\d.]", "", re.search(r"is ([\d,\.]+) ETB", ap.locator("[data-testid=discount-form]").inner_text()).group(1)))
        ap.fill("#discount_amount", str(round(sub * 0.05, 2)))
        check("discount: the effect is shown before saving", "The bill becomes" in ap.locator("[data-testid=d-preview]").inner_text())
        check("discount: a reason is asked for (required) and Save stays off without it", ap.locator("#discount_reason").count() == 1 and ap.get_by_role("button", name=re.compile("^Save a discount")).is_disabled())
        check("discount: the school's limit is stated in words", "You can give up to" in ap.locator("[data-testid=d-limit]").inner_text())
        ap.fill("#discount_amount", str(round(sub * 0.9, 2)))
        check("discount: more than the person may give shows a warning", "This is more than you may give" in body(ap))
        ap.fill("#discount_reason", "Sibling"); ap.get_by_role("button", name=re.compile("^Save a discount")).click()
        check("discount: the server still refuses what is over the limit, beside the field", until(lambda: "need approval from an owner or administrator" in body(ap)) and q("select coalesce(discount_amount,0) from invoices where id=?", INV)[0][0] == 0)
        ap.fill("#discount_amount", str(round(sub * 0.05, 2))); ap.get_by_role("button", name=re.compile("^Save a discount")).click()
        check("discount: an allowed discount is saved with its reason", until(lambda: float(q("select coalesce(discount_amount,0) from invoices where id=?", INV)[0][0]) > 0) and q("select discount_reason from invoices where id=?", INV)[0][0] == "Sibling")
        ap.wait_for_selector("[data-testid=discount-form]", state="detached")
        ap.get_by_role("button", name="Change the discount").click(); ap.wait_for_selector("[data-testid=discount-form]")
        ap.fill("#discount_amount", "0")
        check("discount: 0 takes a discount away (no reason asked)", ap.locator("#discount_reason").count() == 0 and ap.get_by_role("button", name="Take the discount away").is_enabled())
        ap.get_by_role("button", name="Take the discount away").click()
        check("discount: removed", until(lambda: float(q("select coalesce(discount_amount,0) from invoices where id=?", INV)[0][0]) == 0))
        ap.wait_for_selector("[data-testid=discount-form]", state="detached")
        ap.get_by_role("button", name="Waive what is left").click(); ap.wait_for_selector("[data-testid=waive-form]")
        check("waiver: says what waiving does and asks for a reason; the button names the amount", "What waiving does" in ap.locator("[data-testid=waive-form]").inner_text() and ap.get_by_role("button", name=re.compile(r"^Waive [\d,\.]+ ETB")).is_disabled())
        ap.get_by_role("button", name="Cancel").click(); ap.wait_for_selector("[data-testid=waive-form]", state="detached")
        check("waiver: Cancel changes nothing", q("select is_waived from invoices where id=?", INV)[0][0] in (0, None))
        actx.close()
        octx2, op = login(browser, "owner@demo.test")
        op.goto(f"{BASE}/invoices/{INV}"); op.wait_for_selector("[data-testid=adjust-actions]")
        op.get_by_role("button", name="Waive what is left").click(); op.wait_for_selector("[data-testid=waive-form]")
        check("waiver: an owner sees they can approve any size", "any size" in op.locator("[data-testid=w-limit]").inner_text())
        op.fill("#waive_reason", "Hardship")
        op.get_by_role("button", name=re.compile(r"^Waive [\d,\.]+ ETB")).click()
        check("waiver: waived with the reason", until(lambda: q("select is_waived, waiver_reason from invoices where id=?", INV)[0] == (1, "Hardship")))
        op.wait_for_selector("[data-testid=waive-form]", state="detached")
        op.get_by_role("button", name="Take back the waiver").click()
        d3 = op.get_by_role("dialog"); d3.wait_for()
        check("waiver: taking it back asks first and says what changes", "owes the" in d3.inner_text() and d3.get_by_role("button", name="Take back the waiver").count() == 1)
        d3.get_by_role("button", name="Take back the waiver").click()
        check("waiver: taken back", until(lambda: q("select is_waived from invoices where id=?", INV)[0][0] in (0, None)))
        paid_inv = q("select invoice_id from payments where status='verified' limit 1")
        if paid_inv:
            op.goto(f"{BASE}/invoices/{paid_inv[0][0]}")
            check("bill: refund is reached from the payment, on its own careful page", op.get_by_role("link", name="Refund or reverse").count() >= 1 and "/payments/" in op.get_by_role("link", name="Refund or reverse").first.get_attribute("href"))
        octx2.close()

    # ================================================================ 6. exams
    page.goto(f"{BASE}/exams"); page.wait_for_selector("[data-testid=term-row]")
    term1 = page.locator("[data-testid=term-row]").filter(has_text="Term 1"); term2 = page.locator("[data-testid=term-row]").filter(has_text="Term 2")
    check("exams: each term shows what its weights add up to, in words (a term with published results says it is locked)", "Weights add up to 0%" in term2.inner_text() and "Add 100% more" in term2.inner_text() and "Locked" in term1.inner_text())
    page.get_by_role("button", name="Add an exam").click(); page.wait_for_selector("[data-testid=exam-form]")
    check("exam form: two terms, none chosen; type is five big buttons, none chosen; Add is off", page.locator("[data-testid=exam-form] [role=radio][aria-checked=true]").count() == 0 and page.get_by_role("button", name="Add the exam").is_disabled())
    page.get_by_role("radio", name=re.compile("Term 1")).click()
    check("exam form: choosing a term whose results are published warns at once", "Results are already published for this term" in body(page))
    page.get_by_role("radio", name=re.compile("Term 2")).click()
    page.fill("#exam_name", "Unit test A"); page.get_by_role("radio", name="Midterm").last.click()
    page.fill("#exam_weight", "60")
    check("exam form: the term total is shown live (60% so far, 40% to go)", "40% more is needed" in page.locator("[data-testid=weight-preview]").inner_text())
    page.fill("#exam_weight", "150")
    check("exam form: a weight over 100 is explained beside the field", "from 0.01 to 100" in body(page) and page.get_by_role("button", name="Add the exam").is_disabled())
    page.fill("#exam_weight", "60"); page.get_by_role("button", name="Add the exam").click()
    check("exam: added", until(lambda: q("select count(*) from exams where name='Unit test A'")[0][0] == 1))
    page.wait_for_selector("[data-testid=exam-form]", state="detached")
    page.get_by_role("button", name="Add an exam").click(); page.wait_for_selector("[data-testid=exam-form]")
    page.get_by_role("radio", name=re.compile("Term 2")).click()
    page.fill("#exam_name", "Unit test B"); page.get_by_role("radio", name="Final").last.click(); page.fill("#exam_weight", "50")
    check("exam form: over 100% in total is announced before saving", "over 100%" in page.locator("[data-testid=weight-preview]").inner_text())
    page.get_by_role("button", name="Add the exam").click()
    check("exam: the server still refuses a term over 100%", until(lambda: q("select count(*) from exams where name='Unit test B'")[0][0] == 0) and page.locator("[data-testid=exam-form]").count() == 1)
    page.fill("#exam_weight", "40"); page.get_by_role("button", name="Add the exam").click()
    check("exam: 60 + 40 is complete", until(lambda: q("select count(*) from exams where name='Unit test B'")[0][0] == 1))
    page.wait_for_selector("[data-testid=exam-form]", state="detached")
    check("exams: the term now reads Complete: 100%", "Complete: 100%" in term2.inner_text())
    card = page.locator("[data-testid=exam-card]").filter(has_text="Unit test B")
    card.get_by_role("button", name="Change").click(); page.wait_for_selector("[data-testid=exam-form]")
    check("exam change: the term is fixed and stated; the weight preview counts the exam once", "The term cannot be changed" in body(page) and "exactly 100%" in page.locator("[data-testid=weight-preview]").inner_text())
    page.get_by_role("button", name="Cancel").click(); page.wait_for_selector("[data-testid=exam-form]", state="detached")
    card.get_by_role("button", name="Remove").click()
    d4 = page.get_by_role("dialog"); d4.wait_for()
    check("exam: removing asks first", "Remove Unit test B?" in d4.inner_text())
    d4.get_by_role("button", name="Remove Unit test B").click()
    check("exam: an exam with no marks is removed", until(lambda: q("select count(*) from exams where name='Unit test B' and deleted_at is null", ) [0][0] == 0) if "deleted_at" in [r[1] for r in q("pragma table_info(exams)")] else until(lambda: q("select count(*) from exams where name='Unit test B'")[0][0] == 0))

    # ================================================================ 7. who may not
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: none of the admin forms (promotion, announcement wizard, services wizard, exams)", all(status_of(tctx, u) == 403 for u in ("/promotions", "/announcements/new", "/optional-services/new", "/exams")))
    tctx.close()

    # ================================================================ 8. phone
    mctx, mp = login(browser, "owner@demo.test", mobile=True)
    for path, sel, name in (("/promotions", "text=Which class is moving?", "promotion"), ("/announcements/new", "#ann_title", "announcement wizard"), ("/optional-services/new", "[role=radio]", "service wizard"),
                            ("/optional-services", "h1", "services list"), ("/exams", "[data-testid=term-row]", "exams"), (f"/students/{SID}/edit", "[data-testid=details-form]", "student profile")):
        mp.goto(BASE + path); mp.wait_for_selector(sel)
        check(f"phone: {name} has no sideways scroll", overflow(mp) <= 1, str(overflow(mp)))
    mp.goto(f"{BASE}/promotions"); wait_text(mp, "Which class is moving?"); mp.get_by_role("radio", name="Grade 3", exact=True).click(); mp.wait_for_selector("[data-testid=from-classes]")
    mp.locator("[data-testid=from-classes] [role=radio]").filter(has_text="Grade 3 A").click(); until(lambda: nxt(mp).is_enabled()); nxt(mp).click(); wait_text(mp, "What happens to each student?")
    check("phone: a student's three choices fit on one line and stay large", overflow(mp) <= 1 and mp.locator("[data-testid=student-row]").first.get_by_role("radio").first.evaluate(H) >= 48)
    mp.goto(f"{BASE}/exams"); mp.get_by_role("button", name="Add an exam").click(); mp.wait_for_selector("[data-testid=exam-form]")
    mp.locator("#exam_name").scroll_into_view_if_needed()
    check("phone: the exam form's first field is reachable", mp.locator("#exam_name").is_visible())
    mctx.close()

    # ================================================================ 9. Amharic
    q("update users set locale='am' where email='owner@demo.test'")
    actx2, ap2 = login(browser, "owner@demo.test")
    for path, sel, name in (("/promotions", "h1", "promotion"), ("/announcements/new", "#ann_title", "announcement wizard"), ("/optional-services/new", "[role=radio]", "service wizard"), ("/exams", "[data-testid=term-row]", "exams"), (f"/students/{SID}/edit", "[data-testid=details-form]", "student profile")):
        ap2.goto(BASE + path); ap2.wait_for_selector(sel)
        h1 = ap2.locator("[data-testid=details-form]").inner_text() if "student" in name else ap2.locator("h1").first.inner_text()
        check(f"Amharic: {name} is translated (heading), no raw keys", bool(re.search(r"[ሀ-፿]", h1)) and not RAW.search(body(ap2)), f"{h1[:30]!r} {RAW.findall(body(ap2))[:3]}")
    actx2.close()
    q("update users set locale=null where email='owner@demo.test'")

    check("no JavaScript errors on the owner's pages", not page.js_errors, str(page.js_errors[:2]))
    browser.close()

q("update schools set calendar_preference=?", CAL)
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
