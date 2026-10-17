"""Browser test: the teacher's attendance workflow, start to finish, in a real browser.

  * class choice (one card, progress in words) -> the student list -> mark -> save -> a strong confirmation screen
  * partial marking (unmarked students are obvious and stay UNMARKED, never silently "present"), the "not marked yet"
    filter, the warning before saving, the "Mark the rest" follow-up
  * "Mark everyone present" (only the unmarked, so nobody already marked is overwritten) and a one-tap save
  * corrections: what changes is named before saving; parents' notifications happen exactly as before
  * validation and authorization (another teacher, a parent, a covering branch admin)
  * phone layout, and an Amharic screen with no raw translation keys

Uses the usability-session data (teacher@demo.test is homeroom teacher of Grade 3 A) and changes it: it takes and
corrects that class's attendance for today. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/attendance.py
"""
import os, re, sqlite3, sys, tempfile, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="attendance_e2e_")
results = []
H = "e => Math.round(e.offsetHeight)"
W = "e => Math.round(e.offsetWidth)"


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


def until(fn, timeout=15):
    """Poll (the dev server is single-threaded; never assume a delay)."""
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


# ---- the class and its students
teacher = q("select id from users where email='teacher@demo.test'")[0][0]
sec = q("select s.id, g.name || ' ' || s.name from sections s join grades g on g.id=s.grade_id join academic_years y on y.id=s.academic_year_id where s.class_teacher_id=? and y.is_current=1 order by s.id", teacher)[0]
OWN = q("select count(*) from sections s join academic_years y on y.id=s.academic_year_id where s.class_teacher_id=? and y.is_current=1", teacher)[0][0]
SEC, LABEL = sec
enrollments = [r[0] for r in q("select id from enrollments where section_id=? and status='active'", SEC)]
N = len(enrollments)
marks = lambda: {r[0]: r[1] for r in q("select enrollment_id, status from attendance_records where enrollment_id in (%s)" % ",".join("?" * N), *enrollments)}
q("delete from attendance_records where enrollment_id in (%s)" % ",".join("?" * N), *enrollments)
parents = {r[0] for r in q("select ps.parent_id from parent_student ps join enrollments e on e.student_id=ps.student_id where e.section_id=?", SEC)}
notified = lambda kind: q("select count(*) from notifications where type like ? and notifiable_id in (%s)" % ",".join("?" * max(1, len(parents))), f"%{kind}", *(parents or {0}))[0][0]
q("update users set locale=null where email='teacher@demo.test'")
check("the test class has enough students and at least one linked parent", N >= 5 and len(parents) >= 1, f"{LABEL}: {N} students, {len(parents)} parents")

rows = lambda page: page.locator("[data-testid=student-row]")
prog = lambda page: page.locator("[data-testid=progress-text]").first.inner_text()
name_of = lambda row: row.locator("p").first.inner_text()
radio = lambda row, word: row.get_by_role("radio", name=word, exact=True)
save_btn = lambda page: page.get_by_role("button", name=re.compile("^Save attendance"))


def has_parent_row(page):
    """A row whose student has a linked parent (so an absence is visibly a notification event)."""
    ids = {r[0] for r in q("select s.first_name from students s join parent_student ps on ps.student_id=s.id join enrollments e on e.student_id=s.id where e.section_id=?", SEC)}
    for i in range(rows(page).count()):
        if any(n in name_of(rows(page).nth(i)) for n in ids):
            return i
    return 0


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "teacher@demo.test")

    # ================================================================ 1. choose the class
    page.goto(f"{BASE}/attendance"); page.wait_for_selector("[data-testid=class-card]")
    card = page.locator(f"[data-testid=class-card][aria-label='{LABEL}']")
    check("class choice: a teacher sees exactly their own classes, each as one large card", page.locator("[data-testid=class-card]").count() == OWN and card.count() == 1)
    check("class choice: progress is in words ('0 of N students marked') and the state is not colour alone", f"0 of {N} students marked" in card.inner_text() and "Not started" in card.inner_text())
    go = card.get_by_role("link", name="Take attendance")
    check("class choice: one clearly-named, large button", go.count() == 1 and go.evaluate(H) >= 56)
    page.screenshot(path=f"{SHOTS}/1_class.png", full_page=True)
    go.click(); page.wait_for_selector("[data-testid=student-row]")

    # ================================================================ 2. the list
    check("list: progress '0 of N students marked' and 'N not marked yet'", prog(page) == f"0 / {N} marked" and f"{N} not marked yet" in page.locator("[data-testid=progress-state]").first.inner_text(), prog(page))
    check("list: every student starts UNMARKED and says so (nobody is silently present)", rows(page).count() == N and page.locator("[data-state=unmarked]").count() == N and page.locator("text=Not marked yet").count() >= N)
    check("list: nothing is pre-selected", page.locator("[data-testid=student-row] [role=radio][aria-checked=true]").count() == 0)
    first = rows(page).first
    words = first.locator("[role=radio]").all_inner_texts()
    check("list: the five statuses the app supports, as words", [w.strip() for w in words] == ["Present", "Absent", "Late", "Excused", "Sick"], str(words))
    hs = first.locator("[role=radio]").evaluate_all("els => els.map(e => e.offsetHeight)")
    check("list: attendance buttons are large (>= 56px tall)", min(hs) >= 56, str(hs))
    check("list: 'Mark everyone present' is a prominent, large button", page.get_by_role("button", name="Mark everyone present").evaluate(H) >= 56)
    check("list: Save is off and says why", save_btn(page).is_disabled() and page.locator("text=Mark at least one student to save.").count() == 1)
    page.screenshot(path=f"{SHOTS}/2_list_empty.png", full_page=True)

    # ================================================================ 3. partial marking
    ai = has_parent_row(page)
    order = [ai] + [i for i in range(N) if i != ai]
    absent_row, late_row, present_row = rows(page).nth(order[0]), rows(page).nth(order[1]), rows(page).nth(order[2])
    absent_name = name_of(absent_row)
    radio(absent_row, "Absent").click(); radio(late_row, "Late").click(); radio(present_row, "Present").click()
    check("partial: the progress follows every tap ('3 of N') and the missing count drops", prog(page) == f"3 / {N} marked" and f"{N - 3} not marked yet" in page.locator("[data-testid=progress-state]").first.inner_text(), prog(page))
    check("partial: the chosen status is obviously selected; marked rows stop looking unmarked", radio(absent_row, "Absent").get_attribute("aria-checked") == "true" and absent_row.get_attribute("data-state") == "marked" and page.locator("[data-state=unmarked]").count() == N - 3)
    check("partial: the breakdown names the split in words", all(w in page.locator("[aria-label='How the marks are split']").inner_text() for w in ["Present: 1", "Absent: 1", "Late: 1"]))
    page.get_by_role("radio", name=re.compile(r"^Not marked yet \(")).click()
    check("partial: 'Not marked yet' shows only the students still to do", rows(page).count() == N - 3 and page.locator("[data-state=unmarked]").count() == N - 3)
    page.get_by_role("radio", name="All students").click()
    check("partial: ...and 'All students' brings everyone back", rows(page).count() == N)
    page.screenshot(path=f"{SHOTS}/3_partial.png", full_page=True)

    # ================================================================ 4. save with a warning first
    save_btn(page).click()
    dlg = page.locator("[role=dialog]")
    dlg.wait_for()
    body = dlg.inner_text()
    check("save: it says what saving will do BEFORE it does it (absence -> parents told; unmarked stay unmarked)", "Check before you save" in body and "1 marked absent" in body and "told right away" in body and f"{N - 3} students are not marked" in body and "not present, not absent" in body, body.replace("\n", " | ")[:300])
    check("save: nothing has been saved yet", len(marks()) == 0)
    dlg.get_by_role("button", name="Go back and check").click()
    page.wait_for_selector("[role=dialog]", state="detached")
    check("save: 'Go back and check' returns to the list with the marks kept and nothing saved", prog(page) == f"3 / {N} marked" and len(marks()) == 0)
    save_btn(page).click(); dlg.wait_for()
    dlg.get_by_role("button", name="Yes, save attendance").click()
    page.wait_for_selector("text=Attendance saved", timeout=30000)
    got = marks()
    check("saved: exactly the three marked students are recorded; the others have NO record", len(got) == 3 and sorted(got.values()) == ["absent", "late", "present"], str(got))
    conf = page.locator("[role=status]:has-text('Attendance saved')")
    txt = conf.inner_text().replace("\n", " ")
    check("confirmation: a strong screen ('Attendance saved', '3 students recorded', the class and date)", "3 students recorded" in txt and LABEL in txt, txt[:160])
    check("confirmation: it shows the split and says who was told", "Absent: 1" in txt and "were told" in txt or "was told" in txt, txt[:300])
    check("confirmation: it does not hide that students are still unmarked, and offers 'Mark the rest'", f"{N - 3} students are still not marked" in txt and conf.get_by_role("button", name="Mark the rest").count() == 1)
    acts = conf.locator("a, button")
    check("confirmation: a clear next action (Done) and a way to review, both large", conf.get_by_role("link", name="Done").evaluate(H) >= 56 and conf.get_by_role("button", name="Check or change this attendance").evaluate(H) >= 56)
    check("parents: the absence was announced to the parent (as before)", until(lambda: notified("StudentMarkedAbsent") >= 1))
    page.screenshot(path=f"{SHOTS}/4_saved_partial.png", full_page=True)

    # ================================================================ 5. mark the rest
    t_absent_before = q("select updated_at from attendance_records where status='absent' and enrollment_id in (%s)" % ",".join("?" * N), *enrollments)[0][0]
    conf.get_by_role("button", name="Mark the rest").click()
    page.wait_for_selector("[data-testid=student-row]")
    check("mark the rest: only the students still to do are listed", rows(page).count() == N - 3 and page.locator("[data-state=unmarked]").count() == N - 3)
    btn = page.get_by_role("button", name=f"Mark the {N - 3} unmarked students present")
    check("mark the rest: the button names exactly what it does", btn.count() == 1 and btn.evaluate(H) >= 56)
    btn.click()
    check("mark the rest: once nobody is unmarked the filter goes away and the whole class is shown again", rows(page).count() == N and page.get_by_role("radio", name="All students").count() == 0)
    check("mark the rest: marks ONLY the unmarked (the absent/late/present already saved are untouched)", prog(page) == f"{N} / {N} marked" and radio(rows(page).nth(order[0]), "Absent").get_attribute("aria-checked") == "true" and page.locator("text=Everyone is marked").count() >= 1)
    save_btn_now = page.get_by_role("button", name=re.compile("^Save attendance"))
    save_btn_now.click()
    check("an all-marked save with no absence and no correction needs no extra question", page.wait_for_selector("text=Attendance saved", timeout=30000) is not None and page.locator("[role=dialog]").count() == 0)
    got = marks()
    check("saved: everyone recorded; the earlier absent/late/present marks are unchanged", len(got) == N and sorted(v for v in got.values() if v != "present") == ["absent", "late"], str(got))
    check("saved: a mark that did not change was not re-saved (not re-stamped)", q("select updated_at from attendance_records where status='absent' and enrollment_id in (%s)" % ",".join("?" * N), *enrollments)[0][0] == t_absent_before)
    check("confirmation: '{N} students recorded' and 'Everyone is marked'".replace("{N}", str(N)), f"{N} students recorded" in page.locator("[role=status]:has-text('Attendance saved')").inner_text() and page.locator("text=Everyone is marked").count() >= 1)

    # ================================================================ 6. a fresh all-present day
    q("delete from attendance_records where enrollment_id in (%s)" % ",".join("?" * N), *enrollments)
    before_absent = notified("StudentMarkedAbsent")
    page.goto(f"{BASE}/attendance/{SEC}"); page.wait_for_selector("[data-testid=student-row]")
    page.get_by_role("button", name="Mark everyone present").click()
    check("all present: one tap marks the whole class present", prog(page) == f"{N} / {N} marked" and page.locator("[data-testid=student-row] button[aria-checked=true]").count() == N and page.get_by_role("button", name="Mark everyone present").count() == 0)
    save_btn(page).click()
    page.wait_for_selector("text=Attendance saved", timeout=30000)
    check("all present: saving is one tap (no dialog) and records everyone present", page.locator("[role=dialog]").count() == 0 and sorted(marks().values()) == ["present"] * N)
    check("all present: nobody's parent was told anything", notified("StudentMarkedAbsent") == before_absent)

    # ================================================================ 7. a correction
    page.get_by_role("button", name="Check or change this attendance").click()
    page.wait_for_selector("[data-testid=student-row]")
    check("correction: the page says this day was already saved and how to correct it", "already saved" in page.locator("[data-testid=last-saved]").inner_text() and page.get_by_role("button", name="Save attendance changes").is_disabled() and page.locator("text=Nothing has changed since the last save.").count() == 1)
    ai = has_parent_row(page)
    row_a = rows(page).nth(ai); row_b = rows(page).nth((ai + 1) % N)
    name_a, name_b = name_of(row_a), name_of(row_b)
    radio(row_a, "Absent").click(); radio(row_b, "Late").click()
    check("correction: a changed row says what it was saved as and that you are changing it", row_a.get_attribute("data-state") == "changed" and "Saved earlier as Present" in row_a.locator("[data-testid=was-note]").inner_text() and "You are changing it" in row_a.inner_text())
    page.get_by_role("button", name="Save attendance changes").click(); dlg.wait_for()
    body = dlg.inner_text().replace("\n", " ")
    check("correction: before saving, each change is listed with old -> new and the parent consequence is stated", "Marks already saved that will change (2)" in body and f"{name_a}: Present → Absent" in body and f"{name_b}: Present → Late" in body and "marked absent" in body and "told right away" in body, body[:300])
    check("correction: nothing changed yet", sorted(marks().values()) == ["present"] * N)
    dlg.get_by_role("button", name="Yes, save attendance").click()
    page.wait_for_selector("text=Attendance saved", timeout=30000)
    got = marks()
    check("correction: the two marks were updated, the class still has one record per student", len(got) == N and sorted(v for v in got.values() if v != "present") == ["absent", "late"], str(got))
    check("correction: the parent is told about the new absence", until(lambda: notified("StudentMarkedAbsent") == before_absent + 1))
    ctext = page.locator("[role=status]:has-text('Attendance saved')").inner_text()
    check("correction: the confirmation says 2 earlier marks were changed", "2 earlier marks were changed" in ctext, ctext[:200])
    check("correction: it is recorded in the audit trail", q("select count(*) from audit_logs where action='attendance.status_changed'")[0][0] >= 2)

    # undo the absence: the parent must be told it was CORRECTED
    before_corr = notified("AttendanceCorrected")
    page.get_by_role("button", name="Check or change this attendance").click()
    page.wait_for_selector("[data-testid=student-row]")
    radio(rows(page).nth(ai), "Present").click()
    page.get_by_role("button", name="Save attendance changes").click(); dlg.wait_for()
    body = dlg.inner_text().replace("\n", " ")
    check("correcting an absence: the warning says parents told about it will be told it was corrected", "Absent → Present" in body and "will be told it was corrected" in body, body[:260])
    dlg.get_by_role("button", name="Yes, save attendance").click()
    page.wait_for_selector("text=Attendance saved", timeout=30000)
    check("correcting an absence: the parent receives the 'corrected' notice (as before)", until(lambda: notified("AttendanceCorrected") == before_corr + 1))
    check("correcting an absence: the confirmation says who was told", "corrected absence" in page.locator("[role=status]:has-text('Attendance saved')").inner_text())

    # ================================================================ 8. another day, unsaved marks
    y = q("select date('now','-1 day')")[0][0]
    page.goto(f"{BASE}/attendance/{SEC}?date={y}"); page.wait_for_selector("[data-testid=student-row]")
    warn = page.locator("[role=alert]:has-text(\"This is not today's attendance\")")
    check("another day: a clear warning that this is not today", warn.count() == 1)
    radio(rows(page).first, "Sick").click()
    page.locator("summary:has-text('Take attendance for another day')").click()
    details = page.locator("details:has(summary:has-text('Take attendance for another day'))")
    date_input = details.locator('input[type="date"]')
    if date_input.count() > 0:
        date_input.fill(q("select date('now','-2 day')")[0][0])
    else:
        day = details.get_by_label("Day")
        cur = day.input_value()
        day.select_option("1" if cur != "1" else "2")
    d2 = page.locator("[role=dialog]"); d2.wait_for()
    check("another day: changing the date with unsaved marks asks first", "Change the day without saving?" in d2.inner_text() and "1 mark that is not saved" in d2.inner_text(), d2.inner_text()[:160])
    d2.get_by_role("button", name="Stay and keep marking").click()
    check("another day: 'Stay' keeps the marks", radio(rows(page).first, "Sick").get_attribute("aria-checked") == "true")

    # ================================================================ 9. validation (the server refuses bad input)
    def post(path, payload):
        return page.evaluate("""async ([path, payload]) => {
            const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
            const r = await fetch(path, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}, body: JSON.stringify(payload)});
            return [r.status, await r.text()];
        }""", [path, payload])
    e0 = enrollments[0]
    s1, b1 = post(f"/attendance/{SEC}", {"date": y, "records": []})
    s2, b2 = post(f"/attendance/{SEC}", {"date": y, "records": [{"enrollment_id": e0, "status": "holiday"}]})
    s3, _ = post(f"/attendance/{SEC}", {"records": [{"enrollment_id": e0, "status": "present"}]})
    other = q("select id from enrollments where section_id != ? and status='active' limit 1", SEC)[0][0]
    s4, _ = post(f"/attendance/{SEC}", {"date": y, "records": [{"enrollment_id": other, "status": "present"}]})
    check("validation: an empty save, an unknown status, a missing date and a student from another class are all refused", (s1, s2, s3, s4) == (422, 422, 422, 422), str((s1, s2, s3, s4)))
    check("validation: nothing was written for that day", q("select count(*) from attendance_records where date(date)=? and enrollment_id in (%s)" % ",".join("?" * N), y, *enrollments)[0][0] == 0)

    # ================================================================ 10. authorization
    tctx, tpage = login(browser, "teacher2@demo.test")
    r = tpage.goto(f"{BASE}/attendance/{SEC}")
    check("authorization: another teacher cannot open this class's attendance (403)", r.status == 403)
    tstatus = tpage.evaluate("""async ([path, id]) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const r = await fetch(path, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}, body: JSON.stringify({date: '2026-09-01', records: [{enrollment_id: id, status: 'present'}]})});
        return r.status;
    }""", [f"/attendance/{SEC}", e0])
    check("authorization: ...nor save to it", tstatus == 403, str(tstatus))
    pctx, ppage = login(browser, "parent@demo.test")
    check("authorization: a parent cannot open teacher attendance (403)", ppage.goto(f"{BASE}/attendance/{SEC}").status == 403 and ppage.goto(f"{BASE}/attendance").status == 403)
    xctx, xpage = login(browser, "principal@demo.test")
    xpage.goto(f"{BASE}/attendance"); xpage.wait_for_selector("[data-testid=class-card]")
    check("authorization: a branch admin can cover any class in the branch (sees more than one card)", xpage.locator("[data-testid=class-card]").count() > 1)
    check("authorization: ...and can open this class", xpage.goto(f"{BASE}/attendance/{SEC}").status == 200)

    # ================================================================ 11. phone
    mctx, mpage = login(browser, "teacher@demo.test", mobile=True)
    q("delete from attendance_records where enrollment_id in (%s)" % ",".join("?" * N), *enrollments)
    mpage.goto(f"{BASE}/attendance/{SEC}"); mpage.wait_for_selector("[data-testid=student-row]")
    check("phone: no sideways scrolling", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mb = mpage.locator("[data-testid=student-row]").first.locator("[role=radio]")
    dims = mb.evaluate_all("els => els.map(e => [e.offsetWidth, e.offsetHeight, e.scrollWidth <= e.clientWidth + 1])")
    check("phone: the five buttons are all >= 56px in both directions and no label is clipped", all(w >= 56 and h >= 56 and ok for w, h, ok in dims), str(dims))
    check("phone: the progress and the 'Mark everyone present' button are visible without scrolling", mpage.get_by_role("button", name="Mark everyone present").is_visible() and mpage.locator("[data-testid=progress-text]").first.is_visible())
    bar = mpage.locator("[data-testid=bar-text]")
    box = save_btn(mpage).bounding_box()
    check("phone: the Save bar stays on screen (sticky) with a >= 56px button", box is not None and box["y"] + box["height"] <= 844 and box["height"] >= 56, str(box))
    mpage.get_by_role("button", name="Mark everyone present").click()
    check("phone: 'Mark everyone present' works and the bar shows the count", f"{N} / {N} marked" in bar.inner_text())
    mpage.screenshot(path=f"{SHOTS}/11_phone.png", full_page=False)
    save_btn(mpage).click(); mpage.wait_for_selector("text=Attendance saved", timeout=30000)
    check("phone: the confirmation screen fits and its actions are large", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and mpage.get_by_role("link", name="Done").evaluate(H) >= 56)

    # ================================================================ 12. Amharic
    q("update users set locale='am' where email='teacher@demo.test'")
    q("delete from attendance_records where enrollment_id in (%s)" % ",".join("?" * N), *enrollments)
    mpage.goto(f"{BASE}/attendance/{SEC}"); mpage.wait_for_selector("[data-testid=student-row]")
    raw = re.compile(r"\battendance\.[a-z_]+\b")
    check("Amharic: no raw translation keys on the list", not raw.search(mpage.inner_text("body")), raw.findall(mpage.inner_text("body"))[:3].__str__())
    dims = mpage.locator("[data-testid=student-row]").first.locator("[role=radio]").evaluate_all("els => els.map(e => e.scrollWidth <= e.clientWidth + 1)")
    check("Amharic: status words still fit their buttons on a phone", all(dims), str(dims))
    mpage.get_by_role("button").filter(has_text=re.compile("[ሀ-፿]")).first.wait_for()
    q("update users set locale=null where email='teacher@demo.test'")

    for name, pg in [("teacher", page), ("other teacher", tpage), ("phone", mpage), ("branch admin", xpage)]:
        check(f"no JavaScript errors ({name})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
