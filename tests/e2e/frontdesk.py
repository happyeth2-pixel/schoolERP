"""Browser test: the receptionist's day - a home built around her real job, and a Find Student that needs no exact name.

  * home: "Good morning, <name>" + "What do you want to do?", large tiles for only what she is allowed (Register Student,
    Find Student, Print Student Card, Find Parent, More); Record Payment appears only when she holds verify-payments
  * Find Student: a very large search box; matches the student number, any words of the name in any order, a parent's
    name, and a parent's phone in any form; simple result cards (name, grade - section, parent/guardian, phone, status,
    Open Student); a clear empty state and no-results message; the phone numbers are fetched only for results on screen
  * keyboard: "/" focuses the box, Enter opens the first result, arrow keys walk the results, Escape clears
  * the student page: clearly labelled actions, each shown only if that person may do it
  * Find Parent, a phone layout, and the three other languages

Uses the usability-session data; it grants the demo receptionist a few permissions for a moment and takes them back.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/frontdesk.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"
RAW = re.compile(r"\b(?:home|find|desk|parents|enroll|status|common|ui|menu)\.[a-z_]+\b")


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
    page.get_by_role("button", name=re.compile("log in|ግባ|seeni|እቶ", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks], [data-testid=parent-home]", timeout=20000)
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(path if path.startswith("http") else BASE + path, max_redirects=0).status


tiles = lambda pg: pg.locator("[data-testid=main-tiles] a").evaluate_all("els => els.map(e => e.querySelector('span span').innerText.trim())")
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
body = lambda pg: pg.locator("body").inner_text()

REG = q("select id, name from users where email='registrar@demo.test'")[0]
perm = lambda name: q("select id from permissions where name=?", name)[0][0]
mtype = q("select model_type from model_has_permissions where model_id=? limit 1", REG[0])[0][0]


def grant(*names):
    for n in names:
        if not q("select 1 from model_has_permissions where model_id=? and permission_id=?", REG[0], perm(n)):
            q("insert into model_has_permissions (permission_id, model_type, model_id) values (?, ?, ?)", perm(n), mtype, REG[0])


def revoke(*names):
    for n in names:
        q("delete from model_has_permissions where model_id=? and permission_id=?", REG[0], perm(n))


for n in ("verify-payments", "view-attendance-reports", "view-financial-reports", "manage-invoices"):
    revoke(n)
q("update users set locale=null where email in ('registrar@demo.test','auditor@demo.test')")
kid = q("select s.id, s.first_name, s.student_id_number from students s join parent_student ps on ps.student_id=s.id join users u on u.id=ps.parent_id where u.email='parent@demo.test' order by s.id")[0]
KID_ID, KID, KID_NO = kid
PARENT_PHONE_DIGITS = "911000005"

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 1. home
    ctx, page = login(browser, "registrar@demo.test")
    check("home: greets the receptionist by name", re.fullmatch(rf"Good (morning|afternoon|evening), {re.escape(REG[1])}", page.locator("[data-testid=greeting]").inner_text()) is not None, page.locator("[data-testid=greeting]").inner_text())
    check("home: asks 'What do you want to do?'", "What do you want to do?" in page.locator("[data-testid=home-tasks]").inner_text() and "today" not in page.locator("[data-testid=home-tasks]").inner_text().split("\n")[1])
    check("home: the large actions are only what she is allowed (no Record Payment without verify-payments)", tiles(page) == ["Register Student", "Find Student", "Print Student Card", "Find Parent", "More"], str(tiles(page)))
    check("home: every tile is large", min(page.locator("[data-testid=main-tiles] a").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 96)
    hrefs = page.locator("[data-testid=main-tiles] a").evaluate_all("els => els.map(e => e.getAttribute('href'))")
    check("home: every tile opens for her (nothing leads to a 403)", all(h.startswith("#") or status_of(ctx, h) == 200 for h in hrefs), str(hrefs))
    check("home: 'More' is closed at first, and one tap opens everything else she may do", page.evaluate("document.getElementById('more-tasks').open") is False)
    page.locator("[data-testid=more-tile]").click()
    check("home: tapping More opens the list", until(lambda: page.evaluate("document.getElementById('more-tasks').open") is True) and page.locator("[data-testid=more-tasks] a").count() >= 1)
    more_titles = page.locator("[data-testid=more-tasks] a span span").all_inner_texts()
    check("home: nothing she may do was dropped (Classes is under More) and money/staff tools are not there", any("Classes" in m for m in more_titles) and not any(w in " ".join(more_titles) for w in ("Staff", "Check payments", "School fees")), str(more_titles))
    check("home: she still cannot open staff, fees or payments by address", all(status_of(ctx, u) == 403 for u in ("/staff", "/fee-structures", "/payments/pending")))

    grant("verify-payments")
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=main-tiles]")
    check("home: with verify-payments granted, 'Record Payment' appears, before Print Student Card", tiles(page) == ["Register Student", "Find Student", "Record Payment", "Print Student Card", "Find Parent", "More"], str(tiles(page)))
    page.get_by_role("link", name=re.compile("Record Payment")).click(); page.wait_for_selector("#student-search")
    check("home: Record Payment leads to Find Student with a banner saying what to do next", page.locator("[data-testid=task-banner]").count() == 1 and "open Fees" in page.locator("[data-testid=task-banner]").inner_text())
    revoke("verify-payments")

    # ================================================================ 2. Find Student
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=main-tiles]")
    page.get_by_role("link", name=re.compile("Find Student")).first.click(); page.wait_for_selector("#student-search")
    check("Find Student: titled 'Find Student', the box is focused and very large", page.locator("h1").inner_text() == "Find Student" and page.evaluate("document.activeElement.id") == "student-search" and page.locator("#student-search").evaluate(H) >= 64)
    check("Find Student: it says what can be typed (no exact name needed) and lists the keyboard shortcuts", "exact name" in body(page) and "Enter" in page.locator("[data-testid=keyboard-hint]").inner_text())
    check("Find Student: with nothing typed it lists the newest students, without any phone number", page.locator("[data-testid=student-result]").count() == 8 and PARENT_PHONE_DIGITS not in page.content().replace(" ", "") and page.locator("[data-testid=result-phone]").count() == 0)
    search = page.locator("#student-search")

    def found(text, name=KID):
        search.fill(text)
        return until(lambda: page.locator(f"[data-testid=student-result]:has-text('{name}')").count() >= 1)

    check("find by the student number (the whole number)", found(KID_NO))
    check("find by the end of the student number", found(KID_NO[-3:]))
    check("find by part of the name, in lower case", found(KID.lower()[:3]))
    check("find by full name words in any order", found("worku " + KID.lower(), KID) or found(KID.lower() + " worku"))
    check("find by the parent's name", found("kebede", KID))
    check("find by the parent's phone in another form (+251 ...)", found("+251 911 00 00 05", KID))
    check("find by part of the parent's phone", found("911 00 00", KID))
    search.fill(KID_NO)
    until(lambda: page.locator("[data-testid=result-phone]").count() >= 1)
    r = page.locator(f"[data-testid=student-result][data-student-id='{KID_ID}']")
    txt = r.inner_text()
    check("a result is simple: name, grade - section, parent/guardian, phone, status and one Open Student button", KID in r.locator("[data-testid=result-name]").inner_text() and re.search(r"Grade \d+ — Section \w+", r.locator("[data-testid=result-class]").inner_text()) is not None and ("Parent/Guardian:" in r.locator("[data-testid=result-parent]").inner_text() and "Kebede Worku" in r.locator("[data-testid=result-parent]").inner_text()) and re.search(r"Phone: .*0911 00 00 05", r.locator("[data-testid=result-phone]").inner_text()) is not None and "Active" in txt and r.get_by_role("link", name="Open Student").count() == 1 and r.get_by_role("link", name="Open Student").evaluate(H) >= 56, txt.replace("\n", " | ")[:220])
    others = page.locator("[data-testid=student-result]").count()
    shown_phones = page.locator("[data-testid=result-phone]").count()
    check("phones are fetched only for the results on screen (never for everyone)", shown_phones <= others and PARENT_PHONE_DIGITS in page.content().replace(" ", "") and q("select count(*) from students")[0][0] > others)

    search.fill("zzzqqq")
    until(lambda: page.locator("[data-testid=no-results]").count() == 1)
    nr = page.locator("[data-testid=no-results]").inner_text()
    check("no results: a clear message with what to try and where to go next", "No student found for" in nr and "student number" in nr and page.get_by_role("link", name="Register a new student").count() >= 1 and page.get_by_role("link", name="Look for a parent instead").count() == 1, nr.replace("\n", " | ")[:200])

    # keyboard
    search.fill("")
    page.keyboard.press("Escape")
    page.evaluate("document.activeElement.blur()")
    page.keyboard.press("/")
    check("keyboard: '/' jumps to the search box", page.evaluate("document.activeElement.id") == "student-search")
    search.fill(KID.lower())
    until(lambda: page.locator("[data-testid=student-result]").count() >= 1)
    page.keyboard.press("Escape")
    check("keyboard: Escape clears the search", search.input_value() == "")
    search.fill(KID_NO)
    until(lambda: page.locator("[data-testid=student-result]").count() == 1 or page.locator("[data-testid=student-result]").count() >= 1)
    page.keyboard.press("ArrowDown")
    check("keyboard: the down arrow moves to the first Open Student button", page.evaluate("document.activeElement.getAttribute('data-testid')") == "open-student")
    page.keyboard.press("ArrowUp")
    check("keyboard: the up arrow from the first result returns to the search box", page.evaluate("document.activeElement.id") == "student-search")
    page.keyboard.press("Enter")
    page.wait_for_selector("[data-testid=student-actions]")
    check("keyboard: Enter opens the first result", f"/students/{KID_ID}/help" in page.url, page.url)

    # ================================================================ 3. the student page: labelled, permitted actions
    acts = page.locator("[data-testid=student-actions]").inner_text()
    check("student page: 'View and edit profile', 'Report card' and 'Print student card' for her", all(x in acts for x in ("View and edit profile", "Report card", "Print student card")) and "Fees and payment status" not in acts and "Attendance" not in acts, acts.replace("\n", " | "))
    check("student page: nothing restricted is offered (no fees figures, no attendance section content)", page.locator("[data-testid=fees-elsewhere]").count() == 1 and page.locator("[data-testid=attendance-elsewhere]").count() == 1)
    check("student page: each action is a large button", min(page.locator("[data-testid=student-actions] a").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    page.get_by_role("link", name="Print student card").click(); page.wait_for_selector("text=Print ID card")
    check("student page: 'Print student card' opens the card", "/id-card" in page.url, page.url)
    grant("view-attendance-reports", "view-financial-reports", "manage-invoices")
    page.goto(f"{BASE}/students/{KID_ID}/help"); page.wait_for_selector("[data-testid=student-actions]")
    acts = page.locator("[data-testid=student-actions]").inner_text()
    check("student page: granting fees and attendance permissions adds exactly those actions", "Fees and payment status" in acts and "Attendance" in acts and page.locator("[data-testid=fees-elsewhere]").count() == 0)
    page.locator("[data-testid=act-fees]").click()
    check("student page: 'Fees and payment status' jumps to the fees", "#desk-fees" in page.url and page.locator("[data-testid=owed]").count() <= 1)
    check("student page: without verify-payments there is still no Record payment button", page.locator("[data-testid=record-payment]").count() == 0)
    revoke("view-attendance-reports", "view-financial-reports", "manage-invoices")

    # the auditor: read only
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/students"); ap.wait_for_selector("#student-search")
    ap.locator("#student-search").fill(KID_NO)
    until(lambda: ap.locator("[data-testid=student-result]").count() >= 1)
    check("auditor: results show names but never a phone number (she does not manage students)", ap.locator("[data-testid=result-phone]").count() == 0 and PARENT_PHONE_DIGITS not in ap.content().replace(" ", ""))
    ap.locator("[data-testid=open-student]").first.click(); ap.wait_for_selector("[data-testid=student-actions]")
    aacts = ap.locator("[data-testid=student-actions]").inner_text()
    check("auditor: 'View profile' (not editing) plus fees, attendance, report card and card", "View profile" in aacts and "View and edit" not in aacts and "Fees and payment status" in aacts and "Attendance" in aacts)
    check("auditor: no Record payment button and no phone numbers on the page", ap.locator("[data-testid=record-payment]").count() == 0 and PARENT_PHONE_DIGITS not in ap.content().replace(" ", ""))
    actx.close()

    # ================================================================ 4. task banners
    page.goto(f"{BASE}/students?task=card"); page.wait_for_selector("#student-search")
    page.locator("#student-search").fill(KID_NO); until(lambda: page.locator("[data-testid=student-result]").count() >= 1)
    check("Print Student Card task: a banner and a 'Print ID card' button on each result", "print their card" in page.locator("[data-testid=task-banner]").inner_text() and page.locator("[data-testid=task-card]").count() >= 1)

    # ================================================================ 5. Find Parent
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=main-tiles]")
    page.get_by_role("link", name=re.compile("Find Parent")).click(); page.wait_for_selector("#parent-search")
    check("Find Parent: titled, the box is focused and very large", page.locator("h1").inner_text() == "Find Parent" and page.evaluate("document.activeElement.id") == "parent-search" and page.locator("#parent-search").evaluate(H) >= 64)
    ps = page.locator("#parent-search")
    ps.fill("0911 00 00 05")
    until(lambda: page.locator("[data-testid=parent-card]").count() >= 1)
    pc = page.locator("[data-testid=parent-card]").filter(has_text="Kebede Worku").first
    check("Find Parent: found by phone in any form, with their children and an Open Student button for each", pc.count() == 1 and KID in pc.inner_text() and pc.locator("[data-testid=open-child]").count() >= 1)
    ps.fill(KID.lower())
    check("Find Parent: found by a child's name too", until(lambda: page.locator("[data-testid=parent-card]:has-text('Kebede Worku')").count() >= 1))
    ps.fill("zzzqqq")
    check("Find Parent: a clear no-results message", until(lambda: page.locator("[data-testid=no-parents]").count() == 1) and "No parent found" in page.locator("[data-testid=no-parents]").inner_text())
    ps.fill("kebede"); until(lambda: page.locator("[data-testid=open-child]").count() >= 1)
    page.locator("[data-testid=open-child]").first.click(); page.wait_for_selector("[data-testid=student-actions]")
    check("Find Parent: Open Student goes to that student's page", "/help" in page.url)

    # ================================================================ 6. phone layout
    mctx, mp = login(browser, "registrar@demo.test", mobile=True)
    check("phone: home tiles fit, one column, large", overflow(mp) <= 0 and mp.locator("[data-testid=main-tiles] a").first.evaluate("e => e.getBoundingClientRect().width") > 300 and mp.locator("[data-testid=greeting]").is_visible())
    mp.goto(f"{BASE}/students"); mp.wait_for_selector("#student-search")
    mp.locator("#student-search").fill(KID_NO); until(lambda: mp.locator("[data-testid=student-result]").count() >= 1)
    check("phone: Find Student fits the screen, the box is large and the keyboard shortcuts line is hidden", overflow(mp) <= 0 and mp.locator("#student-search").evaluate(H) >= 64 and not mp.locator("[data-testid=keyboard-hint]").is_visible())
    check("phone: a result's Open Student is a large full-width button", mp.locator("[data-testid=open-student]").first.evaluate(H) >= 56 and mp.locator("[data-testid=open-student]").first.evaluate("e => e.getBoundingClientRect().width") > 250)
    mp.locator("[data-testid=open-student]").first.click(); mp.wait_for_selector("[data-testid=student-actions]")
    check("phone: the student page and its action buttons fit the screen", overflow(mp) <= 0 and min(mp.locator("[data-testid=student-actions] a").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    mctx.close()

    # ================================================================ 7. other languages
    for loc in ("am", "om", "ti"):
        q("update users set locale=? where email='registrar@demo.test'", loc)
        lctx, lp = login(browser, "registrar@demo.test")
        seen = RAW.findall(lp.locator("main").inner_text())
        greet = lp.locator("[data-testid=greeting]").inner_text()
        lp.goto(f"{BASE}/students"); lp.wait_for_selector("#student-search")
        lp.locator("#student-search").fill(KID_NO); until(lambda: lp.locator("[data-testid=student-result]").count() >= 1)
        seen += RAW.findall(lp.locator("main").inner_text())
        lp.locator("[data-testid=open-student]").first.click(); lp.wait_for_selector("[data-testid=student-actions]")
        seen += RAW.findall(lp.locator("main").inner_text())
        lp.goto(f"{BASE}/parents"); lp.wait_for_selector("#parent-search")
        seen += RAW.findall(lp.locator("main").inner_text())
        check(f"{loc}: home, Find Student, the student page and Find Parent are translated (no raw keys, no English greeting)", not seen and "Good " not in greet and overflow(lp) <= 0, f"{seen[:4]} {greet}")
        lctx.close()
    q("update users set locale=null where email='registrar@demo.test'")

    check("no JavaScript errors", not page.js_errors, str(page.js_errors[:2]))
    browser.close()

for n in ("verify-payments", "view-attendance-reports", "view-financial-reports", "manage-invoices"):
    revoke(n)
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
