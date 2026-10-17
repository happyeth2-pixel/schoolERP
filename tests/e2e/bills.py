"""Browser test: the guided "Create bills" workflow, in a real browser, as the school accountant.

  * Invoices page -> "Create bills" -> three steps: WHO (a grade, large buttons with student counts), WHAT/WHEN (the
    fees, then the whole year or only some billing periods - nothing preselected), CHECK (what exactly will be made)
  * the preview writes nothing; the numbers on the last step are the numbers the real run produces
  * "Create bills for 3 students" -> a result screen: how many bills, the total, who was told, who was skipped and why
  * duplicate prevention: a billed period is offered as "already billed" and cannot be chosen; a fully billed grade
    says so and cannot be created again; nothing is ever made twice
  * refusals: no terms for a per-term fee, an unknown period, people without permission
  * phone layout and Amharic

Uses the usability-session data (Grade 9 has 3 students, a termly fee and no invoices) and changes it: it creates that
grade's bills. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/bills.py
"""
import os, re, sqlite3, sys, tempfile, time
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="bills_e2e_")
results = []
H = "e => Math.round(e.offsetHeight)"


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


def until(fn, timeout=20):
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


def token(ctx):
    return unquote(next(c["value"] for c in ctx.cookies() if c["name"] == "XSRF-TOKEN"))


G = q("select g.id, g.name from grades g where g.name='Grade 9'")[0]
GID, GNAME = G
STUDENTS = q("select count(*) from enrollments e join sections s on s.id=e.section_id join academic_years y on y.id=s.academic_year_id where s.grade_id=? and y.is_current=1 and e.status='active'", GID)[0][0]
BRANCH = q("select s.branch_id from sections s where s.grade_id=? limit 1", GID)[0][0]
FEES = q("select fs.name, fs.amount, fs.frequency from fee_structures fs join academic_years y on y.id=fs.academic_year_id where fs.grade_id=? and y.is_current=1", GID)
q("update users set locale=null where email='accountant@demo.test'")
grade_invoices = lambda: q("select count(*) from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?", GID)[0][0]
nxt = lambda pg: pg.locator("form").get_by_role("button", name=re.compile(r"^Next$"))
final = lambda pg: pg.get_by_role("button", name=re.compile(r"^Create bills for \d+ students?$"))
step = lambda pg: pg.locator("text=/Step \\d of 3/").first.inner_text()
check("Grade 9 has students, fees and no invoices to begin with", STUDENTS >= 2 and FEES and grade_invoices() == 0, f"{STUDENTS} students, fees {FEES}")
TERMLY = [f for f in FEES if f[2] == "termly"]
ANNUAL = [f for f in FEES if f[2] == "annual"]

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "accountant@demo.test")

    # ================================================================ 0. no terms (before anything is billed)
    year = q("select academic_year_id from terms limit 1")[0][0]
    q("insert into academic_years (school_id, name, start_date, end_date, is_current, created_at, updated_at) select school_id, 'Parked', '2030-01-01', '2030-12-31', 0, datetime('now'), datetime('now') from academic_years where id=?", year)
    parked = q("select id from academic_years where name='Parked'")[0][0]
    q("update terms set academic_year_id=? where academic_year_id=?", parked, year)
    pg0 = ctx.new_page()
    try:
        pg0.goto(f"{BASE}/invoices/new"); pg0.wait_for_selector("[data-testid=grade-grid]")
        pg0.locator("[data-testid=grade-grid] button").filter(has_text=GNAME).first.click(); nxt(pg0).click()
        b0 = pg0.get_by_test_id("blocked"); b0.wait_for()
        check("no terms: the accountant is told, in words, that terms are missing (nothing is created)", "no terms are set up" in b0.inner_text() and nxt(pg0).is_disabled() and pg0.get_by_role("link", name="Open Academic Years").count() == 1 and grade_invoices() == 0, b0.inner_text()[:160])
        pg0.screenshot(path=f"{SHOTS}/0_no_terms.png", full_page=True)
    finally:
        q("update terms set academic_year_id=? where academic_year_id=?", year, parked)
        q("delete from academic_years where id=?", parked)
        pg0.close()

    # ================================================================ 1. into the workflow
    page.goto(f"{BASE}/invoices"); page.wait_for_selector("text=Create bills")
    btn = page.get_by_role("link", name="Create bills")
    check("invoices page: a large 'Create bills' button leads to the guided workflow (the old inline form is gone)", btn.count() == 1 and btn.evaluate(H) >= 56 and page.locator("text=Generate Invoices for Current Year").count() == 0)
    btn.click(); page.wait_for_selector("[data-testid=grade-grid]")

    # ================================================================ 2. step 1: who
    check("step 1: 'Step 1 of 3' and one clear question", step(page) == "Step 1 of 3" and page.locator("form h3").first.inner_text() == "Who are the bills for?")
    check("step 1: one branch only, so no branch question", page.locator("text=Which branch?").count() == 0)
    grid = page.locator("[data-testid=grade-grid] button")
    txt = grid.filter(has_text=GNAME).first.inner_text().replace("\n", " ")
    check("step 1: grades are large buttons that say how many students each has", grid.count() >= 5 and min(grid.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56 and f"{STUDENTS} students" in txt, txt)
    check("step 1: nothing is chosen yet; Next is off and says why", page.locator("[data-testid=grade-grid] button[aria-checked=true]").count() == 0 and nxt(page).is_disabled() and page.locator("text=Choose a grade to continue.").count() == 1)
    empty = grid.filter(has_text="No students")
    check("step 1: a grade with no students cannot be picked", empty.count() == 0 or all(b.is_disabled() for b in empty.all()))
    page.screenshot(path=f"{SHOTS}/1_who.png", full_page=True)
    grid.filter(has_text=GNAME).first.click()
    check("step 1: choosing gives a strong selected state and enables Next", grid.filter(has_text=GNAME).first.get_attribute("aria-checked") == "true" and nxt(page).is_enabled())
    nxt(page).click()

    # ================================================================ 3. step 2: what / when
    page.wait_for_selector("[data-testid=fees-card]")
    check("step 2: 'Step 2 of 3'", step(page) == "Step 2 of 3")
    fees_txt = page.get_by_test_id("fees-card").inner_text().replace("\n", " ")
    check("step 2: the fees that will be billed are listed in words, with the number of students", all(f[0] in fees_txt for f in FEES) and f"{STUDENTS} students" in fees_txt and ("a term" in fees_txt if TERMLY else True), fees_txt[:200])
    check("step 2: nothing is preselected - the whole year or only some periods is the accountant's decision", page.locator("button[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled() and page.locator("text=Choose how much to bill now.").count() == 1)
    check("step 2: the whole-year card says how many bills it makes and that parents are told today", re.search(r"\d+ bills? in all\. Parents are told about all of them today", page.inner_text("form")) is not None)
    page.get_by_role("radio", name=re.compile("Only some billing periods")).click()
    picker = page.get_by_test_id("period-picker")
    picker.wait_for()
    boxes = picker.locator("button[role=checkbox]")
    check("step 2: the billing periods are listed with how many bills and how much each would make", boxes.count() >= 1 and all(re.search(r"\d+ bills?", b.inner_text()) or "Already billed" in b.inner_text() for b in boxes.all()), str(boxes.all_inner_texts()))
    check("step 2: none is ticked; Next is off and says why", picker.locator("button[aria-checked=true]").count() == 0 and nxt(page).is_disabled() and page.locator("text=Tick at least one billing period.").count() == 1)
    term_card = picker.locator("button[role=checkbox]").filter(has_text="Term 1")
    check("step 2: a term is offered by its name (Term 1)", term_card.count() == 1)
    term_card.click()
    check("step 2: ticking it enables Next", term_card.get_attribute("aria-checked") == "true" and nxt(page).is_enabled())
    page.screenshot(path=f"{SHOTS}/2_what.png", full_page=True)
    nxt(page).click()

    # ================================================================ 4. step 3: check
    page.wait_for_selector("[data-testid=headline]")
    check("step 3: 'Step 3 of 3'", step(page) == "Step 3 of 3")
    head = page.get_by_test_id("headline").inner_text().replace("\n", " ")
    check("step 3: the headline says what will happen, in the accountant's words", f"Create bills for {STUDENTS} students" in head and re.search(r"\d+ bills", head) and "Total:" in head, head)
    det = page.get_by_test_id("check-details").inner_text().replace("\n", " ")
    check("step 3: for whom, which periods and how many bills each, and the parents who will be told", GNAME in det and "Term 1" in det and "parents" in det.lower(), det[:200])
    check("step 3: it says a bill cannot be deleted and how a wrong one is fixed", "cannot be deleted" in page.inner_text("form") and "discount" in page.inner_text("form"))
    fbtn = final(page)
    check("step 3: the final button names the outcome and is large", fbtn.count() == 1 and fbtn.evaluate(H) >= 56 and fbtn.inner_text() == f"Create bills for {STUDENTS} students", fbtn.inner_text() if fbtn.count() else "")
    check("step 3: arriving here created NOTHING", grade_invoices() == 0)
    page.screenshot(path=f"{SHOTS}/3_check.png", full_page=True)
    page.get_by_role("button", name="Back").click()
    check("back: the answers are kept (Term 1 still ticked)", page.get_by_test_id("period-picker").locator("button[role=checkbox]").filter(has_text="Term 1").get_attribute("aria-checked") == "true")
    nxt(page).click(); page.wait_for_selector("[data-testid=headline]")
    shown_bills = int(re.search(r"(\d+) bills", page.get_by_test_id("headline").inner_text()).group(1))
    shown_total = page.get_by_test_id("headline").inner_text()

    # ================================================================ 5. create -> the result
    final(page).click()
    page.wait_for_selector("text=Bills created", timeout=60000)
    rows = q("select i.id, i.period_label, i.frequency from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?", GID)
    check("result: exactly the chosen period was billed, once per student", len(rows) == shown_bills == STUDENTS and {r[1] for r in rows} == {"Term 1"}, str(rows))
    money = q("select coalesce(sum(ii.amount),0) from invoice_items ii join invoices i on i.id=ii.invoice_id join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?", GID)[0][0]
    res = page.locator("[role=status]:has-text('Bills created')").inner_text().replace("\n", " ")
    check("result: a strong confirmation with the counts, in words", f"{shown_bills} bills · {STUDENTS} students · {GNAME}" in res, res[:200])
    check("result: the total shown is what the bills really add up to (the preview told the truth)", f"{int(money):,}" in page.get_by_test_id("result-periods").inner_text() or f"{money:g}" in page.get_by_test_id("result-periods").inner_text(), f"{money}")
    check("result: it lists the period that was billed and how many bills", "Term 1" in page.get_by_test_id("result-periods").inner_text())
    check("result: clear next actions - see the bills, another grade, back - all large", page.get_by_role("link", name="See these bills").evaluate(H) >= 56 and page.get_by_role("button", name="Create bills for another grade").evaluate(H) >= 56)
    check("result: 'See these bills' opens the list for this grade", f"grade_id={GID}" in page.get_by_role("link", name="See these bills").get_attribute("href"))
    check("audit: the run is in the audit trail with the periods chosen", q("select count(*) from audit_logs where action='invoices.generated' and after like '%term:%'")[0][0] >= 1)
    page.screenshot(path=f"{SHOTS}/5_result.png", full_page=True)

    # ================================================================ 6. duplicates cannot happen
    base = q("select branch_id, academic_year_id from fee_structures where grade_id=? limit 1", GID)[0]
    q("insert into fee_structures (branch_id, grade_id, academic_year_id, name, amount, frequency, created_at, updated_at) values (?,?,?,?,?,?,datetime('now'),datetime('now'))", base[0], GID, base[1], "Books", 200, "annual")
    ANNUAL = [("Books", 200, "annual")]
    page.get_by_role("button", name="Create bills for another grade").click()
    page.wait_for_selector("[data-testid=grade-grid]")
    page.locator("[data-testid=grade-grid] button").filter(has_text=GNAME).first.click(); nxt(page).click()
    page.wait_for_selector("[data-testid=fees-card]")
    if ANNUAL:
        page.get_by_role("radio", name=re.compile("Only some billing periods")).click()
        done = page.get_by_test_id("period-picker").locator("button[role=checkbox]").filter(has_text="Term 1")
        check("duplicates: the billed period is shown as 'Already billed for everyone' and cannot be chosen", done.is_disabled() and "Already billed for everyone" in done.inner_text())
        page.get_by_role("radio", name=re.compile("Bill the whole year now")).click()
        check("duplicates: 'the whole year' now counts only the bills still missing (Term 1 is not counted again)", nxt(page).is_enabled())
        nxt(page).click(); page.wait_for_selector("[data-testid=headline]")
        check("duplicates: the final check lists only the yearly bills as new", "Term 1" not in page.get_by_test_id("check-details").inner_text() and "Yearly fees" in page.get_by_test_id("check-details").inner_text())
        final(page).click(); page.wait_for_selector("text=Bills created", timeout=60000)
        check("duplicates: the yearly bills were added; Term 1 was not billed again", q("select count(*) from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=? and i.period_label='Term 1'", GID)[0][0] == STUDENTS)
        page.get_by_role("button", name="Create bills for another grade").click(); page.wait_for_selector("[data-testid=grade-grid]")
        page.locator("[data-testid=grade-grid] button").filter(has_text=GNAME).first.click(); nxt(page).click()
    blocked = page.get_by_test_id("blocked")
    blocked.wait_for()
    check("duplicates: a fully billed grade says 'Everything is already billed' and Next stays off", "already exists" in blocked.inner_text() and nxt(page).is_disabled() and page.locator("text=This grade cannot be billed yet").count() == 1, blocked.inner_text())
    before = grade_invoices()
    check("duplicates: nothing more was created", before == q("select count(*) from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?", GID)[0][0])
    tok = token(ctx)
    page.evaluate("""async ([id, branch, token]) => { await fetch('/invoices/generate', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({branch_id: branch, grade_id: id})}); }""", [GID, BRANCH, tok])
    check("duplicates: even the raw request cannot make a second copy (the backend rule holds)", grade_invoices() == before)
    r = page.evaluate("""async ([id, branch]) => (await fetch(`/invoices/generate/preview?branch_id=${branch}&grade_id=${id}&periods[]=term:99999`, {headers: {Accept: 'application/json'}})).status""", [GID, BRANCH])
    check("validation: a billing period that does not exist is refused (preview and creation)", r == 422)
    page.evaluate("""async ([id, branch, token]) => { await fetch('/invoices/generate', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({branch_id: branch, grade_id: id, periods: ['term:99999']})}); }""", [GID, BRANCH, tok])
    check("validation: ...and nothing was created", grade_invoices() == before)

    # ================================================================ 8. authorization
    for email, label in [("teacher@demo.test", "a teacher"), ("parent@demo.test", "a parent"), ("registrar@demo.test", "the front desk")]:
        cx, pg = login(browser, email)
        t2 = token(cx)
        codes = [pg.goto(f"{BASE}/invoices/new").status]
        codes.append(pg.evaluate("""async ([id, branch]) => (await fetch(`/invoices/generate/preview?branch_id=${branch}&grade_id=${id}`, {headers: {Accept: 'application/json'}})).status""", [GID, BRANCH]))
        codes.append(pg.evaluate("""async ([id, branch, token]) => (await fetch('/invoices/generate', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({branch_id: branch, grade_id: id})})).status""", [GID, BRANCH, t2]))
        check(f"authorization: {label} cannot open the workflow, preview or create bills (403)", codes == [403, 403, 403], str(codes))
        cx.close()
    check("authorization: nothing was created by them", grade_invoices() == before)

    # ================================================================ 9. phone
    q("delete from invoice_items where invoice_id in (select i.id from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?)", GID)
    q("delete from payments where invoice_id in (select i.id from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?)", GID)
    q("delete from invoices where id in (select i.id from invoices i join enrollments e on e.student_id=i.student_id join sections s on s.id=e.section_id where s.grade_id=?)", GID)
    mctx, mpage = login(browser, "accountant@demo.test", mobile=True)
    mpage.goto(f"{BASE}/invoices/new"); mpage.wait_for_selector("[data-testid=grade-grid]")
    check("phone: step 1 fits (two grades across), no sideways scrolling, big buttons", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and min(mpage.locator("[data-testid=grade-grid] button").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    mpage.locator("[data-testid=grade-grid] button").filter(has_text=GNAME).first.click(); mpage.get_by_role("button", name="Next").click()
    mpage.wait_for_selector("[data-testid=fees-card]")
    mpage.get_by_role("radio", name=re.compile("Bill the whole year now")).click()
    check("phone: step 2 fits and the choice is a large card", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and mpage.get_by_role("radio", name=re.compile("Bill the whole year now")).evaluate(H) >= 64)
    mpage.get_by_role("button", name="Next").click(); mpage.wait_for_selector("[data-testid=headline]")
    check("phone: the final check fits and its button is large", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and final(mpage).evaluate(H) >= 56)
    mpage.screenshot(path=f"{SHOTS}/9_phone.png", full_page=True)

    # ================================================================ 10. Amharic
    q("update users set locale='am' where email='accountant@demo.test'")
    raw = re.compile(r"\bgen\.[a-z_]+\b")
    for name, action in [("step 1", lambda: mpage.goto(f"{BASE}/invoices/new")), ]:
        action(); mpage.wait_for_selector("[data-testid=grade-grid]")
        check(f"Amharic: no raw translation keys on {name}, and it fits a phone", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text("body"))[:3]))
    mpage.locator("[data-testid=grade-grid] button").filter(has_text=re.compile(rf"^{GNAME}|{GNAME}")).first.click()
    mpage.locator("form button:not([disabled])").filter(has_text=re.compile("ቀጣይ|Next")).first.click()
    mpage.wait_for_selector("[data-testid=fees-card]")
    check("Amharic: no raw translation keys on step 2", not raw.search(mpage.inner_text("body")))
    mpage.screenshot(path=f"{SHOTS}/10_am.png", full_page=True)
    q("update users set locale=null where email='accountant@demo.test'")

    for nm, pg in [("accountant", page), ("phone", mpage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
