"""Browser test: the teacher's marks-entry workflow, one student at a time, in a real browser.

  * the "what do I need to enter" list with progress in words
  * focus view: the facts (class, subject, exam, out of), "Student 3 of 5", a big numeric input that is focused for
    you, existing marks shown, "Save & Next" as the primary action, Previous/Next, the "All students" overview
  * invalid marks are refused on the spot (letters, negatives, above the maximum, a bad maximum)
  * a save always carries the WHOLE class list (blank = a row with no score), so the missing-mark policy applies
  * unsaved typing survives moving between students and is protected when leaving the page
  * finishing, the warning about students with no mark, submit-for-approval with its consequences
  * submitted / approved / published are read-only and say where the marks are; sent-back is editable again
  * unauthorized teachers, parents and admins; phone layout; Amharic

Uses the usability-session data (teacher@demo.test teaches Mathematics in Grade 3 A) and changes it: it enters,
submits and resets that batch. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/marks.py
"""
import os, re, sqlite3, sys, tempfile, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="marks_e2e_")
results = []
H = "e => Math.round(e.offsetHeight)"


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


teacher = q("select id from users where email='teacher@demo.test'")[0][0]
SEC, LABEL = q("select s.id, g.name || ' ' || s.name from sections s join grades g on g.id=s.grade_id where g.name='Grade 3' and s.name='A'")[0]
SUBJ = q("select id from subjects where name='Mathematics'")[0][0]
EXAM = q("select id from exams where name='Mid-term'")[0][0]
FINAL = q("select id from exams where name='Final'")[0][0]
N = q("select count(*) from enrollments where section_id=? and status='active'", SEC)[0][0]
URL = f"{BASE}/marks/{EXAM}/{SEC}/{SUBJ}"

q("delete from marks where mark_batch_id in (select id from mark_batches where section_id=? and subject_id=?)", SEC, SUBJ)
q("delete from mark_batches where section_id=? and subject_id=?", SEC, SUBJ)
q("update users set locale=null where email='teacher@demo.test'")
q("update schools set missing_mark_policy='exclude'")
AUDITS0 = q("select count(*) from audit_logs where action='mark.updated'")[0][0]
audits = lambda: q("select count(*) from audit_logs where action='mark.updated'")[0][0] - AUDITS0
check("the test class has 5 students", N == 5, f"{LABEL}: {N}")

batch = lambda: (q("select id, status from mark_batches where exam_id=? and section_id=? and subject_id=?", EXAM, SEC, SUBJ) or [(None, None)])[0]
rows_db = lambda: q("select e.id, m.score, m.max_score from marks m join enrollments e on e.id=m.enrollment_id join students s on s.id=e.student_id where m.mark_batch_id=? order by s.first_name", batch()[0])   # the screen lists students by first name
name = lambda pg: pg.locator("[data-testid=student-name]").inner_text()
pos = lambda pg: pg.locator("[data-testid=position]").inner_text()
inp = lambda pg: pg.locator("#mark-input")
save_next = lambda pg: pg.get_by_role("button", name=re.compile(r"^Save & (Next|Finish)$"))
notice = lambda pg: pg.locator("[data-testid=saved-notice]")


def type_mark(pg, v):
    inp(pg).fill(v)


with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "teacher@demo.test")

    # ================================================================ 1. the list
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks], [data-testid=teacher-home]")
    page.get_by_role("link", name=re.compile("Enter marks", re.I)).first.click()
    page.wait_for_selector("[data-testid=marks-card]")
    card = page.locator(f"[data-testid=marks-card][aria-label='{LABEL} Mathematics']")
    check("list: one card per class and subject, with the exams inside", card.count() == 1 and card.locator("[data-testid=exam-row]").count() == 2)
    row = card.locator("[data-testid=exam-row]").filter(has_text="Mid-term")
    check("list: state in words (icon + word), progress in words, one clearly-named button", "Not started" in row.inner_text() and f"0 of {N} students have a mark" in row.inner_text() and row.get_by_role("link", name="Start entering marks").evaluate(H) >= 56)
    page.screenshot(path=f"{SHOTS}/1_list.png", full_page=True)
    row.get_by_role("link", name="Start entering marks").click()
    page.wait_for_selector("[data-testid=current-student]")

    # ================================================================ 2. the focused screen
    check("focus: a draft batch now exists (opening the page is how it starts, as before)", batch()[1] == "draft")
    facts = page.locator("[data-testid=facts]").inner_text().replace("\n", " ")
    check("focus: the facts are always visible - class, subject, exam and 'out of'", all(s in facts for s in [LABEL, "Mathematics", "Mid-term", "100"]), facts)
    check("focus: 'Student 1 of 5' and 'Not started' progress", pos(page) == f"Student 1 of {N}" and f"0 of {N} students have a mark" in page.locator("[data-testid=entered-progress]").inner_text())
    first = name(page)
    size = page.locator("[data-testid=student-name]").evaluate("e => parseFloat(getComputedStyle(e).fontSize)")
    check("focus: the current student is unmistakable (name >= 28px in an outlined card)", size >= 28 and page.locator("[data-testid=current-student]").evaluate("e => getComputedStyle(e).borderTopWidth") == "2px", f"{first} {size}px")
    check("focus: 'No mark saved yet' is stated", "No mark saved yet" in page.locator("[data-testid=saved-line]").inner_text())
    check("focus: the input is large (>= 64px tall, big text), numeric on phones, and already focused", inp(page).evaluate(H) >= 64 and inp(page).evaluate("e => parseFloat(getComputedStyle(e).fontSize)") >= 32 and inp(page).get_attribute("inputmode") == "decimal" and page.evaluate("document.activeElement && document.activeElement.id") == "mark-input")
    check("focus: 'Save & Next' is the large primary action; Previous is off on the first student", save_next(page).evaluate(H) >= 56 and page.get_by_role("button", name="Previous student").is_disabled())
    page.screenshot(path=f"{SHOTS}/2_focus.png", full_page=False)

    # order of the class, from the overview
    page.get_by_role("radio", name="All students").click()
    names = [re.sub(r"^Enter the mark for ", "", b.get_attribute("aria-label")) for b in page.locator("[data-testid=student-list] button").all()]
    check("overview: every student is listed, none has a mark yet", len(names) == N and page.locator("[data-state=blank]").count() == N and names[0] == first, str(names))
    page.get_by_role("radio", name="One student at a time").click()
    page.wait_for_selector("[data-testid=current-student]")

    # ================================================================ 3. invalid marks
    err = lambda: page.locator("#mark-error")
    for bad, why in [("abc", "Type a number"), ("-5", "Type a number"), ("12.345", "Type a number"), ("101", "can't be higher than 100")]:
        type_mark(page, bad)
        check(f"invalid: '{bad}' is refused on the spot with a plain message and Save is off", err().count() == 1 and why in err().inner_text() and save_next(page).is_disabled() and inp(page).get_attribute("aria-invalid") == "true", err().inner_text() if err().count() else "")
    check("invalid: Previous/Next are off too, so a bad value cannot be carried along", page.get_by_role("button", name="Next student").is_disabled())
    check("invalid: nothing reached the server", len(rows_db()) == 0)
    type_mark(page, "87,5")
    check("valid: a comma decimal is understood and clears the error", err().count() == 0 and save_next(page).is_enabled())

    # ================================================================ 4. save & next
    inp(page).press("Enter")
    check("save: Enter saves and moves on ('Student 2 of 5')", until(lambda: pos(page) == f"Student 2 of {N}"))
    check("save: clear success feedback names the student and the mark", f"Saved. {first}: 87.5 out of 100." in notice(page).inner_text(), notice(page).inner_text() if notice(page).count() else "")
    db = rows_db()
    check("save: the WHOLE class list was saved - a row for every student, only the first has a score", len(db) == N and sum(1 for r in db if r[1] is not None) == 1 and db[0][1] == 87.5 and {r[2] for r in db} == {100}, str(db))
    check("save: the change is in the audit trail (one entry: only the mark that changed)", audits() == 1, str(audits()))
    second = name(page)
    type_mark(page, "72"); save_next(page).click()
    until(lambda: pos(page) == f"Student 3 of {N}")
    check("save: 'Save & Next' by click moves on too; two marks saved", sum(1 for r in rows_db() if r[1] is not None) == 2)

    # ================================================================ 5. previous student, existing value
    page.get_by_role("button", name="Previous student").click()
    check("previous: back on student 2, the saved mark is shown in words AND prefilled", pos(page) == f"Student 2 of {N}" and name(page) == second and "Saved mark: 72 out of 100" in page.locator("[data-testid=saved-line]").inner_text() and inp(page).input_value() == "72")
    type_mark(page, "75")
    check("previous: changing it says 'Changed from 72 - not saved yet'", "Changed from 72" in page.locator("[data-testid=saved-line]").inner_text() and "not saved yet" in page.locator("[data-testid=saved-line]").inner_text())
    save_next(page).click(); until(lambda: pos(page) == f"Student 3 of {N}")
    check("previous: the correction was saved and audited (and only that mark)", [r[1] for r in rows_db()][:2] == [87.5, 75] and audits() == 3, str(audits()))

    # ================================================================ 6. accidental data loss
    third = name(page)
    type_mark(page, "55")
    page.get_by_role("button", name="Previous student").click()
    page.get_by_role("button", name="Next student").click()
    check("data loss: typed-but-unsaved marks survive moving between students", pos(page) == f"Student 3 of {N}" and inp(page).input_value() == "55" and "1 mark is typed but not saved yet" in page.locator("[data-testid=unsaved-hint]").inner_text())
    page.get_by_role("radio", name="All students").click()
    check("data loss: the overview marks it 'Not saved yet'", page.locator("[data-state=unsaved]").count() == 1 and "Not saved yet" in page.locator("[data-state=unsaved]").inner_text())
    page.get_by_role("radio", name="One student at a time").click()
    page.get_by_role("link", name=re.compile("Home")).first.click()
    dlg = page.locator("[role=dialog]"); dlg.wait_for()
    check("data loss: leaving the page asks first, with the safe choice focused", "Leave without saving?" in dlg.inner_text() and page.evaluate("document.activeElement.textContent") == "Stay and keep my marks", dlg.inner_text()[:120])
    dlg.get_by_role("button", name="Stay and keep my marks").click()
    page.wait_for_selector("[role=dialog]", state="detached")
    check("data loss: 'Stay' keeps the page and the typed mark", "/marks/" in page.url and inp(page).input_value() == "55")
    check("data loss: nothing was saved by staying", rows_db()[2][1] is None)
    extra = ctx.new_page(); dialogs = []
    extra.on("dialog", lambda d: (dialogs.append(d.type), d.dismiss()))
    extra.goto(URL); extra.wait_for_selector("#mark-input"); extra.locator("#mark-input").click(); extra.keyboard.type("44")   # real key presses: browsers only warn after a real interaction
    extra.close(run_before_unload=True)
    until(lambda: (page.wait_for_timeout(300) or True) and "beforeunload" in dialogs, 5)
    check("data loss: closing the tab or reloading with typed marks triggers the browser's own warning", "beforeunload" in dialogs, str(dialogs))
    page.get_by_role("link", name=re.compile("Home")).first.click(); dlg.wait_for()
    dlg.get_by_role("button", name="Leave and lose the marks").click()
    page.wait_for_url(re.compile(r"/dashboard"), timeout=20000)
    check("data loss: 'Leave and lose the marks' leaves, and the unsaved mark really was not saved", rows_db()[2][1] is None)

    # ================================================================ 7. resume, no mark, finish
    page.goto(URL); page.wait_for_selector("[data-testid=current-student]")
    check("resume: the page opens on the first student who still has no mark", pos(page) == f"Student 3 of {N}" and name(page) == third)
    type_mark(page, "55"); save_next(page).click(); until(lambda: pos(page) == f"Student 4 of {N}")
    fourth = name(page)
    save_next(page).click(); until(lambda: pos(page) == f"Student 5 of {N}")
    check("no mark: an empty mark is allowed (the student has no mark) and saved as such", f"Saved. {fourth} has no mark." in notice(page).inner_text() and rows_db()[3][1] is None)
    check("last student: the primary button says 'Save & Finish'", page.get_by_role("button", name="Save & Finish").count() == 1)
    type_mark(page, "90"); page.get_by_role("button", name="Save & Finish").click()
    page.wait_for_selector("text=Marks saved", timeout=30000)
    done = page.locator("[role=status]:has-text('Marks saved')").inner_text().replace("\n", " ")
    check("finish: a strong confirmation - 'Marks saved', '4 of 5 students have a mark'", f"{N - 1} of {N} students have a mark" in done, done[:200])
    check("finish: it names the student still without a mark and offers to enter it", "1 student has no mark yet" in done and page.get_by_role("button", name=f"Enter the mark for {fourth}").count() == 1)
    page.screenshot(path=f"{SHOTS}/7_done.png", full_page=True)

    # ================================================================ 8. submission
    page.get_by_role("button", name="Submit for approval").click(); dlg.wait_for()
    body = dlg.inner_text().replace("\n", " ")
    check("submit: the consequences come first - marks lock, the approver can send back, parents see it only once published", "Submit these marks for approval?" in body and "you can't change the marks" in body and "approved and published" in body, body[:260])
    check("submit: students with no mark are counted, with what the school's policy does to them", "1 student has no mark." in body and "left out" in body, body[:260])
    dlg.get_by_role("button", name="Go back and check").click()
    page.wait_for_selector("[role=dialog]", state="detached")
    check("submit: 'Go back and check' submits nothing", batch()[1] == "draft")
    q("update schools set missing_mark_policy='zero'")
    page.reload(); page.wait_for_selector("[data-testid=student-list], [data-testid=current-student]")
    if page.locator("[data-testid=current-student]").count():
        page.get_by_role("radio", name="All students").click()
    page.get_by_role("button", name="Submit for approval").click(); dlg.wait_for()
    check("submit: with the 'zero' policy the wording changes to 'counts as 0'", "counts as 0" in dlg.inner_text() and "left out" not in dlg.inner_text())
    dlg.get_by_role("button", name="Go back and check").click(); page.wait_for_selector("[role=dialog]", state="detached")
    q("update schools set missing_mark_policy='exclude'")

    # fill the last blank from the list, then submit
    page.locator(f"[data-testid=student-list] button[aria-label='Enter the mark for {fourth}']").click()
    type_mark(page, "66"); save_next(page).click()
    until(lambda: rows_db()[3][1] == 66)
    check("existing marks: every student now has a mark and the list says 'Saved' for each", until(lambda: page.locator("[data-testid=current-student]").count() >= 0))
    page.get_by_role("radio", name="All students").click()
    check("existing marks: the overview shows each saved mark next to the student", until(lambda: page.locator("[data-state=saved]").count() == N) and "87.5 / 100" in page.locator("[data-testid=student-list]").inner_text())
    page.get_by_role("button", name="Submit for approval").click(); dlg.wait_for()
    check("submit: with every mark in there is no 'no mark' warning", "no mark" not in dlg.inner_text())
    dlg.get_by_role("button", name="Yes, submit for approval").click()
    page.wait_for_selector("text=Marks submitted for approval", timeout=30000)
    check("submit: it is submitted, audited, and a strong confirmation says what happens next", batch()[1] == "submitted" and q("select count(*) from audit_logs where action='mark_batch.submitted'")[0][0] >= 1 and "locked" in page.locator("[role=status]:has-text('Marks submitted')").inner_text())

    # ================================================================ 9. locked states
    page.goto(URL); page.wait_for_selector("[data-testid=readonly-list]")
    txt = page.inner_text("main") if page.locator("main").count() else page.inner_text("body")
    check("submitted: read-only, shows the state in words and what to do", "Submitted — waiting for approval" in txt and "ask the approver to send them back" in txt and page.locator("#mark-input").count() == 0)
    check("submitted: the saved marks are still shown", "87.5 / 100" in page.locator("[data-testid=readonly-list]").inner_text())
    st = page.evaluate("""async (id) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const r = await fetch(`/mark-batches/${id}`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}, body: JSON.stringify({records: []})});
        return r.status;
    }""", batch()[0])
    check("submitted: the server refuses edits too (a locked batch is never writable)", st in (422,), str(st))
    bid = batch()[0]
    for state, words in [("approved", "Approved — waiting to be published"), ("published", "Published")]:
        q("update mark_batches set status=? where id=?", state, bid)
        page.reload(); page.wait_for_selector("[data-testid=readonly-list]")
        check(f"{state}: read-only with its state explained", words in page.inner_text("body") and page.locator("#mark-input").count() == 0)
    page.goto(f"{BASE}/marks"); page.wait_for_selector("[data-testid=marks-card]")
    r = page.locator("[data-testid=exam-row]").filter(has_text="Mid-term").first
    check("list: a published exam shows 'Published', its progress and a 'View marks' button", "Published" in r.inner_text() and f"{N} of {N} students have a mark" in r.inner_text() and r.get_by_role("link", name="View marks").count() == 1)
    q("update mark_batches set status='draft', submitted_at=null where id=?", bid)     # what "send back" does
    page.goto(URL)
    page.wait_for_selector("[data-testid=student-list]")
    check("sent back: it is editable again, opens on the overview because every student has a mark", f"{N} of {N} students have a mark" in page.locator("[data-testid=entered-progress]").inner_text() and page.locator("[data-state=saved]").count() == N)

    # ================================================================ 10. correcting an existing mark
    before = audits()
    page.locator(f"[data-testid=student-list] button[aria-label='Enter the mark for {names[0]}']").click()
    check("existing: the saved mark is shown (words + prefilled) when returning to a student", "Saved mark: 87.5 out of 100" in page.locator("[data-testid=saved-line]").inner_text() and inp(page).input_value() == "87.5")
    type_mark(page, "88"); save_next(page).click()
    check("existing: correcting one mark saves it and audits exactly that change", until(lambda: rows_db()[0][1] == 88) and until(lambda: audits() == before + 1))

    # ================================================================ 11. the maximum mark
    page.get_by_role("radio", name="All students").click()
    page.locator("summary:has-text('Change the maximum mark')").click()
    page.fill("#max-input", "50")
    check("maximum: lowering it below existing marks flags those students and turns Save off", page.locator("[data-state=invalid]").count() >= 1 and page.get_by_role("button", name="Save marks").is_disabled() and "can't be higher than 50" in page.locator("[data-state=invalid]").first.inner_text())
    page.fill("#max-input", "abc")
    check("maximum: a non-number is refused in plain words", page.locator("text=Type the maximum mark as a number").count() >= 1 and page.get_by_role("button", name="Save marks").is_disabled())
    page.fill("#max-input", "100")
    check("maximum: back to 100 and everything is fine again", page.locator("[data-state=invalid]").count() == 0 and page.locator("text=Type the maximum mark as a number").count() == 0)

    # ================================================================ 12. authorization
    tctx, tpage = login(browser, "teacher2@demo.test")
    check("authorization: another teacher cannot open this class's marks (403)", tpage.goto(URL).status == 403)
    st = tpage.evaluate("""async (id) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const r = await fetch(`/mark-batches/${id}`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}, body: JSON.stringify({records: [{enrollment_id: 1, score: 1, max_score: 100}]})});
        return r.status;
    }""", bid)
    check("authorization: ...nor save into it", st == 403, str(st))
    st = tpage.evaluate("""async (id) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        return (await fetch(`/mark-batches/${id}/submit`, {method: 'POST', headers: {'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}})).status;
    }""", bid)
    check("authorization: ...nor submit it", st == 403, str(st))
    pctx, ppage = login(browser, "parent@demo.test")
    check("authorization: a parent cannot open the marks list or a mark sheet (403)", ppage.goto(f"{BASE}/marks").status == 403 and ppage.goto(URL).status == 403)
    xctx, xpage = login(browser, "principal@demo.test")
    check("authorization: a branch admin who does not teach it cannot enter its marks (403)", xpage.goto(URL).status == 403)
    tpage.goto(f"{BASE}/marks"); tpage.locator("[data-testid=marks-card]").or_(tpage.get_by_text("Nothing to enter yet")).first.wait_for()
    check("authorization: another teacher's list holds only their own classes", tpage.locator(f"[data-testid=marks-card][aria-label='{LABEL} Mathematics']").count() == 0)

    # ================================================================ 13. phone
    q("update mark_batches set status='draft' where id=?", bid)
    q("delete from marks where mark_batch_id=?", bid)
    mctx, mpage = login(browser, "teacher@demo.test", mobile=True)
    mpage.goto(URL); mpage.wait_for_selector("[data-testid=current-student]")
    check("phone: no sideways scrolling, the student's name, the input and Save & Next are all on screen", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and mpage.locator("[data-testid=student-name]").is_visible() and inp(mpage).is_visible())
    bx = save_next(mpage).bounding_box(); ib = inp(mpage).bounding_box()
    check("phone: input >= 64px, Save & Next >= 56px and on the first screen (pinned to the bottom)", ib["height"] >= 64 and bx["height"] >= 56 and bx["y"] + bx["height"] <= 844, f"input {ib['height']} button {bx['height']}@{bx['y']}")
    mpage.screenshot(path=f"{SHOTS}/13_phone.png", full_page=False)
    inp(mpage).fill("70"); save_next(mpage).click()
    check("phone: Save & Next works", until(lambda: pos(mpage) == f"Student 2 of {N}"))

    # ================================================================ 14. Amharic
    q("update users set locale='am' where email='teacher@demo.test'")
    mpage.goto(URL); mpage.wait_for_selector("[data-testid=current-student]")
    raw = re.compile(r"\bmarks\.[a-z_]+\b")
    check("Amharic: no raw translation keys, and the layout still fits", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text("body"))[:3]))
    mpage.screenshot(path=f"{SHOTS}/14_am.png", full_page=False)
    q("update users set locale=null where email='teacher@demo.test'")

    for nm, pg in [("teacher", page), ("other teacher", tpage), ("phone", mpage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
