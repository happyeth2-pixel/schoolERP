"""Browser test: the guided fee workflow, in a real browser, as the school accountant.

  * School fees list: grouped by grade for one school year, fees in words, Add / Change / Remove
  * Add a fee in three steps - WHERE (school year + grade, showing what is already set), WHAT (name, amount, how
    often), CHECK (read back, "Save fee structure") - nothing preselected, nothing saved before the last button
  * Change a fee: the same page without the first question (grade, year and branch cannot change), showing "was ..."
  * duplicate fee names refused (on the page and by the server), invalid amounts refused, a free fee warned about,
    a per-term fee in a year with no terms warned about
  * Remove asks first and says existing bills are not touched
  * people without permission; phone layout; Amharic

Uses the usability-session data and changes it: it adds, changes and removes fees for Grade 9. Only run it against
that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/fees.py
"""
import os, re, sqlite3, sys, tempfile, time
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="fees_e2e_")
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


GID, GNAME = q("select id, name from grades where name='Grade 9'")[0]
YEAR = q("select id, name from academic_years where is_current=1")[0]
BRANCH = q("select branch_id from sections where grade_id=? limit 1", GID)[0][0]
live = lambda name: q("select id, amount, frequency, grade_id, academic_year_id, branch_id from fee_structures where grade_id=? and academic_year_id=? and name=? and deleted_at is null", GID, YEAR[0], name)
count_fees = lambda: q("select count(*) from fee_structures where deleted_at is null")[0][0]
nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
step = lambda pg: pg.locator("text=/Step \\d of \\d/").first.inner_text()
q("update users set locale=null where email='accountant@demo.test'")
q("delete from fee_structures where name in ('Books','Trip')")
existing = q("select name from fee_structures where grade_id=? and academic_year_id=? and deleted_at is null", GID, YEAR[0])
check("Grade 9 already has a fee (Tuition) to test duplicates against", any(r[0] == "Tuition" for r in existing), str(existing))

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "accountant@demo.test")

    # ================================================================ 1. the list
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks]")
    page.get_by_role("link", name=re.compile("School fees", re.I)).first.click()
    page.wait_for_selector("[data-testid=grade-card]")
    add = page.get_by_role("link", name="Add a fee")
    check("list: a large 'Add a fee' button, and the school year shown as buttons (current year selected)", add.first.evaluate(H) >= 56 and page.get_by_role("radio", name=YEAR[1]).get_attribute("aria-checked") == "true")
    card = page.locator("[data-testid=grade-card]").filter(has_text=GNAME)
    row = card.locator("[data-testid=fee-row]").filter(has_text="Tuition")
    check("list: a grade card shows each fee in words - amount and how often", "1,500 ETB" in row.inner_text() and "Every term" in row.inner_text(), row.inner_text().replace("\n", " "))
    check("list: Change and Remove are large named buttons on every fee", row.get_by_role("link", name="Change").evaluate(H) >= 44 and row.get_by_role("button", name="Remove").evaluate(H) >= 44)
    page.screenshot(path=f"{SHOTS}/1_list.png", full_page=True)

    # ================================================================ 2. add: step 1
    add.first.click(); page.wait_for_selector("[data-testid=grade-grid]")
    check("step 1: 'Step 1 of 3' and one clear question", step(page) == "Step 1 of 3" and page.locator("form h3").first.inner_text() == "Which grade and school year?")
    yr = page.get_by_role("radio", name=YEAR[1])
    check("step 1: the current school year is labelled, and nothing is chosen for you", "Current school year" in yr.inner_text() and page.locator("button[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled() and page.locator("text=Choose a grade and a school year to continue.").count() == 1)
    yr.click()
    grid = page.locator("[data-testid=grade-grid] button")
    g9 = grid.filter(has_text=GNAME).first
    check("step 1: grades are large buttons saying how many fees each already has", min(grid.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56 and "1 fee set" in g9.inner_text(), g9.inner_text().replace("\n", " "))
    g9.click()
    ex = page.get_by_test_id("existing").inner_text().replace("\n", " ")
    check("step 1: what is already set for that grade and year is listed, so a repeat is visible", "Tuition" in ex and "1,500 ETB" in ex and "Add a new fee only if it is not in this list" in ex, ex[:200])
    page.screenshot(path=f"{SHOTS}/2_step1.png", full_page=True)
    nxt(page).click()

    # ================================================================ 3. step 2
    page.wait_for_selector("#fee_name")
    check("step 2: 'Step 2 of 3'", step(page) == "Step 2 of 3")
    check("step 2: nothing is preselected - Next is off until name, amount and frequency are given", nxt(page).is_disabled() and page.locator("button[role=radio][aria-checked=true]").count() == 0)
    chips = page.locator("form button").filter(has_text=re.compile("^(Tuition|Registration|Books)$"))
    check("step 2: common names can be tapped instead of typed", chips.count() >= 2)
    page.fill("#fee_name", "tuition")
    check("step 2: a name this grade already has (any case) is refused on the spot, in words", "already has a fee called" in page.inner_text("form") and nxt(page).is_disabled())
    page.fill("#fee_name", "Books")
    check("step 2: a new name clears it", "already has a fee called" not in page.inner_text("form"))
    for bad in ["abc", "-5", "12.345"]:
        page.fill("#fee_amount", bad)
        check(f"step 2: amount '{bad}' is refused in plain words and Next stays off", "Type the amount as a number" in page.inner_text("form") and nxt(page).is_disabled())
    page.fill("#fee_amount", "2500")
    check("step 2: a good amount clears the message, but Next still waits for how often", "Type the amount as a number" not in page.inner_text("form") and nxt(page).is_disabled())
    freq = page.get_by_role("radio", name=re.compile("^Every month"))
    check("step 2: how often is three big cards in words, each explained", page.locator("button[role=radio]").count() == 3 and "Billed every month of the school year." in freq.inner_text() and freq.evaluate(H) >= 64)
    freq.click()
    check("step 2: choosing enables Next", nxt(page).is_enabled())
    page.screenshot(path=f"{SHOTS}/3_step2.png", full_page=True)
    nxt(page).click()

    # ================================================================ 4. review + save
    page.wait_for_selector("[data-testid=headline]")
    check("step 3: 'Step 3 of 3'", step(page) == "Step 3 of 3")
    head = page.get_by_test_id("headline").inner_text().replace("\n", " ")
    check("step 3: the whole fee read back in one line", "Books — 2,500 ETB" in head and "Every month" in head and GNAME in head and YEAR[1] in head, head)
    rows = page.locator("form dl").inner_text().replace("\n", " ")
    check("step 3: Grade / School year / Fee / Amount / Billed, each with a Change button", all(w in rows for w in ["Grade", "School year", "Fee", "Amount", "Billed", "Books", "2,500 ETB"]) and page.get_by_role("button", name="Change").count() == 5)
    check("step 3: it says existing bills are not changed", "Bills that already exist are not changed" in page.inner_text("form"))
    save = page.get_by_role("button", name="Save fee structure")
    check("step 3: the final button is 'Save fee structure' and large", save.count() == 1 and save.evaluate(H) >= 56)
    check("step 3: arriving here saved NOTHING", not live("Books"))
    page.get_by_role("button", name="Change").nth(3).click()
    check("change: 'Change' on Amount goes back to step 2 with everything kept", step(page) == "Step 2 of 3" and page.input_value("#fee_amount") == "2500" and page.input_value("#fee_name") == "Books")
    nxt(page).click(); page.wait_for_selector("[data-testid=headline]")
    page.screenshot(path=f"{SHOTS}/4_review.png", full_page=True)
    before = count_fees()
    save.click()
    page.wait_for_selector("text=Fee structure saved", timeout=30000)
    saved = live("Books")
    check("saved: one fee with the right grade, year, branch, amount and frequency", len(saved) == 1 and saved[0][1:] == (2500, "monthly", GID, YEAR[0], BRANCH) and count_fees() == before + 1, str(saved))
    panel = page.locator("[role=status]:has-text('Fee structure saved')").inner_text().replace("\n", " ")
    check("saved: a strong confirmation with the summary line and the bills note", f"{GNAME} · Books · 2,500 ETB · Every month · {YEAR[1]}" in panel and "not changed" in panel, panel[:200])
    check("saved: the change is in the audit trail", q("select count(*) from audit_logs where auditable_type like '%FeeStructure' and auditable_id=?", saved[0][0])[0][0] >= 1)
    check("saved: clear next actions - another fee for this grade, another grade, see all fees", page.get_by_role("button", name=f"Add another fee for {GNAME}").evaluate(H) >= 56 and page.get_by_role("button", name="Add a fee for another grade").count() == 1 and page.get_by_role("link", name="See all school fees").count() == 1)
    page.screenshot(path=f"{SHOTS}/5_saved.png", full_page=True)

    # ================================================================ 5. another fee: free, and the server-side duplicate rule
    page.get_by_role("button", name=f"Add another fee for {GNAME}").click()
    page.wait_for_selector("#fee_name")
    check("another: it goes straight to step 2 with the grade and year kept", step(page) == "Step 2 of 3" and page.input_value("#fee_name") == "")
    page.fill("#fee_name", "Trip"); page.fill("#fee_amount", "0"); page.get_by_role("radio", name=re.compile("^Once a year")).click(); nxt(page).click()
    page.wait_for_selector("[data-testid=headline]")
    check("free fee: a 0 ETB fee is allowed but the last step warns about it", "This fee is 0 ETB" in page.inner_text("form"))
    page.get_by_role("button", name="Save fee structure").click(); page.wait_for_selector("text=Fee structure saved", timeout=30000)
    check("free fee: saved", len(live("Trip")) == 1 and live("Trip")[0][1] == 0)
    tok = token(ctx)
    n = count_fees()
    page.evaluate("""async ([g, y, b, token]) => { await fetch('/fee-structures', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({branch_id: b, grade_id: g, academic_year_id: y, name: ' BOOKS ', amount: 10, frequency: 'annual'})}); }""", [GID, YEAR[0], BRANCH, tok])
    check("duplicate: the server refuses a second 'Books' for the same grade and year even when sent directly", count_fees() == n and live("Books")[0][1] == 2500)
    page.evaluate("""async ([g, y, b, token]) => { await fetch('/fee-structures', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({branch_id: b, grade_id: g, academic_year_id: y, name: 'Huge', amount: 999999999, frequency: 'annual'})}); }""", [GID, YEAR[0], BRANCH, tok])
    check("validation: an amount the system cannot hold is refused by the server too", not live("Huge") and count_fees() == n)

    # ================================================================ 6. per-term fee in a year with no terms
    term_year = q("select academic_year_id from terms limit 1")[0][0]
    q("insert into academic_years (school_id, name, start_date, end_date, is_current, created_at, updated_at) select school_id, 'Parked', '2030-01-01', '2030-12-31', 0, datetime('now'), datetime('now') from academic_years where id=?", term_year)
    parked = q("select id from academic_years where name='Parked'")[0][0]
    q("update terms set academic_year_id=? where academic_year_id=?", parked, term_year)
    try:
        page.goto(f"{BASE}/fee-structures/new"); page.wait_for_selector("[data-testid=grade-grid]")
        page.get_by_role("radio", name=YEAR[1]).click(); page.locator("[data-testid=grade-grid] button").filter(has_text="Grade 8").first.click(); nxt(page).click()
        page.fill("#fee_name", "Term extra"); page.fill("#fee_amount", "100"); page.get_by_role("radio", name=re.compile("^Every term")).click()
        check("no terms: choosing 'Every term' in a year with no terms warns straight away and again on the last step", "This school year has no terms yet" in page.inner_text("form"))
        nxt(page).click(); page.wait_for_selector("[data-testid=headline]")
        check("no terms: ...and the last step repeats it (the fee can still be saved; billing it needs the terms)", "This school year has no terms yet" in page.inner_text("form"))
    finally:
        q("update terms set academic_year_id=? where academic_year_id=?", term_year, parked)
        q("delete from academic_years where id=?", parked)

    # ================================================================ 7. edit
    page.goto(f"{BASE}/fee-structures"); page.wait_for_selector("[data-testid=grade-card]")
    page.locator("[data-testid=grade-card]").filter(has_text=GNAME).locator("[data-testid=fee-row]").filter(has_text="Books").get_by_role("link", name="Change").click()
    page.wait_for_selector("[data-testid=locked]")
    check("edit: 'Change a fee' - two steps, the grade/year/branch are shown and cannot be changed", step(page) == "Step 1 of 2" and GNAME in page.get_by_test_id("locked").inner_text() and "cannot be changed" in page.get_by_test_id("locked").inner_text() and page.locator("[data-testid=grade-grid]").count() == 0)
    check("edit: the fee is prefilled (name, amount, how often)", page.input_value("#fee_name") == "Books" and page.input_value("#fee_amount") == "2500" and page.get_by_role("radio", name=re.compile("^Every month")).get_attribute("aria-checked") == "true" and nxt(page).is_enabled())
    page.fill("#fee_name", "Tuition")
    check("edit: renaming it to another existing fee is refused on the spot", "already has a fee called" in page.inner_text("form") and nxt(page).is_disabled())
    page.fill("#fee_name", "Books"); page.fill("#fee_amount", "2600"); nxt(page).click()
    page.wait_for_selector("[data-testid=headline]")
    rv = page.locator("form dl").inner_text().replace("\n", " ")
    check("edit: the check step shows what it was, next to the new amount", "2,600 ETB" in rv and "was 2,500 ETB" in rv, rv[:200])
    check("edit: nothing is saved before the button", live("Books")[0][1] == 2500)
    page.get_by_role("button", name="Save changes").click(); page.wait_for_selector("text=Fee changed", timeout=30000)
    row = live("Books")[0]
    check("edit: the amount changed, everything else (grade, year, branch, frequency) stayed", row[1:] == (2600, "monthly", GID, YEAR[0], BRANCH), str(row))
    check("edit: it is audited (the creation and the change are both in the trail)", q("select count(*) from audit_logs where auditable_type like '%FeeStructure' and auditable_id=?", row[0])[0][0] >= 2)
    page.screenshot(path=f"{SHOTS}/7_changed.png", full_page=True)

    # ================================================================ 8. remove asks first
    page.get_by_role("link", name="See all school fees").click(); page.wait_for_selector("[data-testid=grade-card]")
    trip = page.locator("[data-testid=grade-card]").filter(has_text=GNAME).locator("[data-testid=fee-row]").filter(has_text="Trip")
    trip.get_by_role("button", name="Remove").click()
    dlg = page.locator("[role=dialog]"); dlg.wait_for()
    check("remove: it asks first, and says existing bills are not changed", "Remove Trip?" in dlg.inner_text() and "Bills already made are not changed" in dlg.inner_text(), dlg.inner_text().replace("\n", " ")[:200])
    dlg.get_by_role("button", name="Keep the fee").click(); page.wait_for_selector("[role=dialog]", state="detached")
    check("remove: 'Keep the fee' removes nothing", len(live("Trip")) == 1)
    trip.get_by_role("button", name="Remove").click(); dlg.wait_for()
    dlg.get_by_role("button", name="Remove fee").click()
    check("remove: confirming archives it (it disappears from the list)", until(lambda: not live("Trip")) and until(lambda: page.locator("[data-testid=fee-row]").filter(has_text="Trip").count() == 0))

    # ================================================================ 9. authorization
    fid = live("Books")[0][0]
    for email, label in [("teacher@demo.test", "a teacher"), ("parent@demo.test", "a parent"), ("registrar@demo.test", "the front desk")]:
        cx, pg = login(browser, email)
        t2 = token(cx)
        codes = [pg.goto(f"{BASE}/fee-structures").status, pg.goto(f"{BASE}/fee-structures/new").status, pg.goto(f"{BASE}/fee-structures/{fid}/edit").status]
        def call(method, path, body=None):
            return pg.evaluate("""async ([method, path, body, token]) => (await fetch(path, {method, headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: body ? JSON.stringify(body) : undefined})).status""", [method, path, body, t2])
        codes += [call("POST", "/fee-structures", {"branch_id": BRANCH, "grade_id": GID, "academic_year_id": YEAR[0], "name": "Sneaky", "amount": 1, "frequency": "annual"}), call("PATCH", f"/fee-structures/{fid}", {"name": "Sneaky", "amount": 1, "frequency": "annual"}), call("DELETE", f"/fee-structures/{fid}")]
        check(f"authorization: {label} cannot see, add, change or remove fees (403 everywhere)", codes == [403] * 6, str(codes))
        cx.close()
    check("authorization: nothing changed", live("Books")[0][1] == 2600 and not live("Sneaky"))

    # ================================================================ 10. phone
    mctx, mpage = login(browser, "accountant@demo.test", mobile=True)
    mpage.goto(f"{BASE}/fee-structures/new"); mpage.wait_for_selector("[data-testid=grade-grid]")
    mpage.get_by_role("radio", name=YEAR[1]).click(); mpage.locator("[data-testid=grade-grid] button").filter(has_text="Grade 8").first.click()
    check("phone: step 1 fits, big year cards and grade buttons", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and min(mpage.locator("[data-testid=grade-grid] button").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    nxt(mpage).click(); mpage.wait_for_selector("#fee_name")
    mpage.fill("#fee_name", "Lab"); mpage.fill("#fee_amount", "50"); mpage.get_by_role("radio", name=re.compile("^Every month")).click()
    check("phone: step 2 fits and the fields are large", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and mpage.locator("#fee_amount").evaluate(H) >= 56)
    nxt(mpage).click(); mpage.wait_for_selector("[data-testid=headline]")
    check("phone: the check step fits and the save button is large", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and mpage.get_by_role("button", name="Save fee structure").evaluate(H) >= 56)
    mpage.screenshot(path=f"{SHOTS}/10_phone.png", full_page=True)

    # ================================================================ 11. Amharic
    q("update users set locale='am' where email='accountant@demo.test'")
    raw = re.compile(r"\bfee\.[a-z_]+\b")
    for path in ["/fee-structures", "/fee-structures/new"]:
        mpage.goto(f"{BASE}{path}"); mpage.wait_for_selector("h1")
        check(f"Amharic: no raw translation keys on {path}, and it fits a phone", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text('body'))[:3]))
    mpage.screenshot(path=f"{SHOTS}/11_am.png", full_page=False)
    q("update users set locale=null where email='accountant@demo.test'")

    for nm, pg in [("accountant", page), ("phone", mpage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
