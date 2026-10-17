"""Browser test: transport routes, ASP classes and a student's own price, in a real browser.

  * Registration wizard (principal, who may manage invoices): a new "Extra services" step with Transport as one big
    choice (+ "No transport"), ASP as Yes/No, other services as toggle cards, prices on every option and a summary
    line; choosing a second route replaces the first; the review shows the summary; the child is saved with them.
  * Front-desk registrar (no invoice permission): no such step, and the profile lists services read-only.
  * Profile: taps save at once; a second route replaces the first (never two); "No transport"; ASP Yes/No; a special
    price is validated, saved and cleared.
  * Invoices (accountant): generated lines use the student's own price; a termly fee with no terms is a readable
    message on the page (it used to be a raw 422 error page) and creates nothing.
  * Phone layout.

Changes the usability-session data (adds four services, registers a student). Only run it against that throwaway DB:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/services.py
"""
import os, re, sqlite3, sys, tempfile, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="services_e2e_")
results = []
STAMP = str(int(time.time()))[-6:]
KID = f"Tsion{STAMP}"
H = "e => Math.round(e.offsetHeight)"
nxt = lambda page: page.locator("form").get_by_role("button", name=re.compile(r"^(Next|ቀጣይ|Itti fufi|ቀጽል)$"))


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


def until(fn, timeout=12):
    """Poll a condition (the dev server is single-threaded; never assume a delay)."""
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


def services_of(student_id):
    return q("select os.name, sos.custom_amount from student_optional_service sos join optional_services os on os.id=sos.optional_service_id where sos.student_id=? order by os.name", student_id)


def to_class_step(page, first_name):
    page.goto(f"{BASE}/students/create"); page.wait_for_selector("form h3")
    page.fill("#first_name", first_name); nxt(page).click()
    page.wait_for_selector("text=Step 2 of")
    page.get_by_role("radio", name="Girl").click(); nxt(page).click()
    page.wait_for_selector("text=Step 3 of")
    nxt(page).click()                                                    # the optional photo step: skipped
    page.wait_for_selector("text=Step 4 of")
    page.get_by_role("radio", name="Grade 4", exact=True).click()
    page.get_by_role("radio", name=re.compile("Grade 4 A")).click()
    nxt(page).click()


# ---- the school's services (made the way an administrator would, straight into the throwaway DB)
sec = q("select s.branch_id, s.academic_year_id from sections s join grades g on g.id=s.grade_id where g.name='Grade 4' and s.name='A'")[0]
BRANCH, YEAR = sec
for name, cat, amount, freq in [("Transport - Kela", "transport", 800, "monthly"), ("Transport - Ashewa Meda", "transport", 950, "monthly"), ("ASP Classes", "asp", 600, "termly"), ("Library card", "other", 100, "annual")]:
    if q("select count(*) from optional_services where name=?", name)[0][0]:
        continue
    q("insert into optional_services (branch_id, academic_year_id, name, category, amount, frequency, created_at, updated_at) values (?,?,?,?,?,?,datetime('now'),datetime('now'))", BRANCH, YEAR, name, cat, amount, freq)
SVC = {r[1]: r[0] for r in q("select id, name from optional_services")}

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 1. registration wizard, as the principal
    ctx, page = login(browser, "principal@demo.test")
    to_class_step(page, KID)
    page.wait_for_selector("text=Step 5 of 8")
    check("wizard: an 'Extra services' step appears right after the class", page.locator("form h3").first.inner_text() == "Extra services" and page.locator("text=Step 5 of 8").count() == 1)
    groups = page.locator("form h3").all_inner_texts()
    check("wizard: services are grouped: Transport, ASP, Other", groups[1:] == ["Transport", "ASP Classes", "Other services"], str(groups))
    tr = page.locator("[role=radiogroup][aria-label=Transport] button")
    check("wizard: transport = one big card per route + 'No transport'", [b.split("\n")[0] for b in tr.all_inner_texts()] == ["Ashewa Meda", "Kela", "No transport"], str(tr.all_inner_texts()))
    check("wizard: every option shows its price (routes, ASP, other)", all(s in page.inner_text("form") for s in ["800 ETB a month", "950 ETB a month", "600 ETB a term", "100 ETB a year"]))
    heights = page.locator("form button[role=radio], form button[role=checkbox]").evaluate_all("els => els.map(e => e.offsetHeight)")
    check("wizard: every service button is >= 56px", len(heights) == 6 and min(heights) >= 56, str(heights))
    check("wizard: nothing is charged by default: 'No transport' and ASP 'No' are the marked answers", page.get_by_role("radio", name=re.compile("No transport")).get_attribute("aria-checked") == "true" and page.get_by_role("radio", name="No", exact=True).get_attribute("aria-checked") == "true")
    summary = lambda: page.locator("[data-testid=service-summary]").inner_text().replace("\n", " ")
    check("wizard: the summary says so", "No extra services" in summary(), summary())
    page.screenshot(path=f"{SHOTS}/wizard_services_empty.png", full_page=True)

    page.get_by_role("radio", name=re.compile("Kela")).click()
    check("wizard: choosing a route marks it and updates the summary", page.get_by_role("radio", name=re.compile("Kela")).get_attribute("aria-checked") == "true" and "Transport: Kela (800 ETB/month)" in summary(), summary())
    page.get_by_role("radio", name=re.compile("Ashewa")).click()
    check("wizard: choosing another route REPLACES the first (only one at a time)", page.get_by_role("radio", name=re.compile("Kela")).get_attribute("aria-checked") == "false" and page.locator("[role=radiogroup][aria-label=Transport] button[aria-checked=true]").count() == 1 and "Ashewa Meda (950 ETB/month)" in summary(), summary())
    page.get_by_role("radio", name="Yes", exact=True).click()
    check("wizard: ASP 'Yes' adds it to the summary alongside the route", "Transport: Ashewa Meda (950 ETB/month) + ASP Classes (600 ETB/term)" in summary(), summary())
    page.get_by_role("radio", name=re.compile("Kela")).click()
    page.get_by_role("checkbox", name=re.compile("Library card")).click()
    page.get_by_role("checkbox", name=re.compile("Library card")).click()
    check("wizard: the requested summary format, and an 'other' card toggles off again", "Transport: Kela (800 ETB/month) + ASP Classes (600 ETB/term)" in summary() and "Library" not in summary(), summary())
    check("wizard: nothing was saved by choosing", q("select count(*) from students where first_name=?", KID)[0][0] == 0)
    page.screenshot(path=f"{SHOTS}/wizard_services_chosen.png", full_page=True)

    nxt(page).click()
    page.wait_for_selector("text=Step 6 of 8")
    page.get_by_role("radio", name=re.compile("Add the parent later")).click(); nxt(page).click()
    page.wait_for_selector("text=Step 7 of 7")
    review = page.locator("form dl").inner_text().replace("\n", " ")
    check("review: shows the chosen services in the same words", "Extra services" in review and "Transport: Kela (800 ETB/month) + ASP Classes (600 ETB/term)" in review, review[:220])
    page.get_by_role("button", name=re.compile("^Register " + KID)).click()
    page.wait_for_selector("[role=status]:has-text('is registered')", timeout=25000)
    sid = q("select id from students where first_name=?", KID)[0][0]
    check("saved: the child has exactly Kela and ASP, both at the standard price (custom price empty)", services_of(sid) == [("ASP Classes", None), ("Transport - Kela", None)], str(services_of(sid)))
    check("saved: each opt-in is in the audit trail", q("select count(*) from audit_logs where action='student.service_joined' and auditable_id=?", sid)[0][0] == 2)
    check("no invoice exists yet, so nothing was charged at registration", q("select count(*) from invoices where student_id=?", sid)[0][0] == 0)

    # ================================================================ 2. the profile
    page.goto(f"{BASE}/students/{sid}/edit"); page.wait_for_selector("summary:has-text('Extra services')")
    page.locator("summary:has-text('Extra services')").click()
    check("profile: the folded section already says what is chosen", "Transport: Kela (800 ETB/month) + ASP Classes (600 ETB/term)" in page.locator("summary:has-text('Extra services')").first.inner_text().replace("\n", " "))
    check("profile: same grouped cards, current choices marked", page.get_by_role("radio", name=re.compile("Kela")).get_attribute("aria-checked") == "true" and page.get_by_role("radio", name="Yes", exact=True).get_attribute("aria-checked") == "true")
    page.screenshot(path=f"{SHOTS}/profile_services.png", full_page=True)

    page.get_by_role("radio", name=re.compile("Ashewa")).click()
    check("profile: choosing another route saves at once and replaces the first (DB has ONE transport)", until(lambda: services_of(sid) == [("ASP Classes", None), ("Transport - Ashewa Meda", None)]), str(services_of(sid)))
    check("profile: ...and says so", until(lambda: "Transport changed from Transport - Kela to Transport - Ashewa Meda" in page.inner_text("body")))
    check("profile: the withdrawal of the old route is audited", q("select count(*) from audit_logs where action='student.service_withdrawn' and auditable_id=?", sid)[0][0] == 1)
    page.get_by_role("radio", name=re.compile("No transport")).click()
    check("profile: 'No transport' removes the route", until(lambda: services_of(sid) == [("ASP Classes", None)]), str(services_of(sid)))
    page.get_by_role("radio", name="No", exact=True).click()
    check("profile: ASP 'No' removes ASP", until(lambda: services_of(sid) == []), str(services_of(sid)))
    page.get_by_role("radio", name="Yes", exact=True).click()
    page.get_by_role("radio", name=re.compile("Kela")).click()
    check("profile: ASP 'Yes' and a route again", until(lambda: services_of(sid) == [("ASP Classes", None), ("Transport - Kela", None)]), str(services_of(sid)))
    until(lambda: page.get_by_role("radio", name=re.compile("Kela")).get_attribute("aria-checked") == "true")

    # the student's own price
    page.locator("summary:has-text('A different price for this student')").click()
    aid = SVC["ASP Classes"]
    field = page.locator(f"#custom_{aid}")
    check("special price: one row per chosen service, showing the standard price", page.locator("text=Standard price: 600 ETB a term").count() == 1 and page.locator("text=Standard price: 800 ETB a month").count() == 1)
    field.fill("-5")
    page.locator(f"#custom_{aid}").locator("xpath=ancestor::div[contains(@class,'space-y-3')][1]").get_by_role("button", name="Save price").click()
    check("special price: a negative amount is refused with a message and nothing changes", until(lambda: page.locator("text=/at least 0/").count() > 0) and services_of(sid)[0] == ("ASP Classes", None), str(services_of(sid)))
    field.fill("650")
    page.locator(f"#custom_{aid}").locator("xpath=ancestor::div[contains(@class,'space-y-3')][1]").get_by_role("button", name="Save price").click()
    check("special price: 650 is saved for this student only", until(lambda: services_of(sid)[0] == ("ASP Classes", 650)), str(services_of(sid)))
    check("special price: audited with the amount", q("select count(*) from audit_logs where action='student.service_price_changed' and auditable_id=?", sid)[0][0] == 1)
    check("special price: the page shows it as a special price and in the summary", until(lambda: "ASP Classes (650 ETB/term)" in page.locator("[data-testid=service-summary]").inner_text() and page.locator("text=/special price/").count() > 0))
    check("special price: the standard price of the service itself is untouched", q("select amount from optional_services where id=?", aid)[0][0] == 600)

    # ================================================================ 3. invoices, as the accountant
    actx, apage = login(browser, "accountant@demo.test")
    nxt_ = lambda pg: pg.locator("form").get_by_role("button", name="Next")

    def open_grade(pg, grade):
        pg.goto(f"{BASE}/invoices/new"); pg.wait_for_selector("[data-testid=grade-grid]")
        pg.locator("[data-testid=grade-grid] button").filter(has_text=grade).first.click()
        nxt_(pg).click(); pg.wait_for_selector("[data-testid=fees-card]")

    open_grade(apage, "Grade 4")
    apage.get_by_role("radio", name=re.compile("Bill the whole year now")).click()
    nxt_(apage).click(); apage.wait_for_selector("[data-testid=headline]")
    apage.get_by_role("button", name=re.compile(r"^Create bills for \d+ students?$")).click()
    check("invoices: creating the grade's bills through the guided workflow succeeds", until(lambda: apage.locator("text=Bills created").count() > 0, 90))
    items = q("select os.name, ii.unit_amount, ii.amount from invoice_items ii join invoices i on i.id=ii.invoice_id join optional_services os on os.id=ii.optional_service_id where i.student_id=?", sid)
    asp = [r for r in items if r[0] == "ASP Classes"]; kela = [r for r in items if r[0] == "Transport - Kela"]
    check("invoices: the ASP line uses the student's own price (650), the transport line the route's (800)", len(asp) >= 1 and all(r[1] == 650 for r in asp) and len(kela) >= 1 and all(r[1] == 800 for r in kela), str(items)[:200])
    unenrolled = q("select count(*) from invoice_items ii join invoices i on i.id=ii.invoice_id join optional_services os on os.id=ii.optional_service_id where os.name='ASP Classes' and not exists (select 1 from student_optional_service sos where sos.student_id=i.student_id and sos.optional_service_id=os.id)")[0][0]
    check("invoices: only students who opted in are billed for ASP", unenrolled == 0)
    n_before = q("select count(*) from invoices")[0][0]
    open_grade(apage, "Grade 4")
    check("invoices: running it again is not offered - everything is already billed, and nothing is made twice", apage.get_by_test_id("blocked").count() == 1 and apage.locator("form").get_by_role("button", name="Next").is_disabled() and q("select count(*) from invoices")[0][0] == n_before)

    # termly fee + no terms -> a readable message in the workflow, not an error page
    term_year = q("select academic_year_id from terms limit 1")[0][0]
    q("insert into academic_years (school_id, name, start_date, end_date, is_current, created_at, updated_at) select school_id, 'Parked', '2030-01-01', '2030-12-31', 0, datetime('now'), datetime('now') from academic_years where id=?", YEAR)
    parked = q("select id from academic_years where name='Parked'")[0][0]
    q("update terms set academic_year_id=? where academic_year_id=?", parked, term_year)
    inv_before = q("select count(*) from invoices")[0][0]
    try:
        open_grade(apage, "Grade 9")
        blocked = apage.get_by_test_id("blocked")
        check("no terms: the workflow says in words what to do, and Next stays off", "no terms are set up" in blocked.inner_text() and "Add the terms first" in blocked.inner_text() and apage.locator("form").get_by_role("button", name="Next").is_disabled(), blocked.inner_text()[:160])
        check("no terms: it is still the normal page, not an error screen", "Unprocessable" not in apage.inner_text("body"))
        check("no terms: nothing was generated", q("select count(*) from invoices")[0][0] == inv_before)
    finally:
        q("update terms set academic_year_id=? where academic_year_id=?", term_year, parked)
        q("delete from academic_years where id=?", parked)

    # ================================================================ 4. front desk without invoice permission
    rctx, rpage = login(browser, "registrar@demo.test")
    to_class_step(rpage, "Deskcheck")
    rpage.wait_for_selector("text=Step 5 of 7")
    check("front desk: no services step (they may not manage invoices), the wizard is unchanged", rpage.locator("text=Extra services").count() == 0 and rpage.locator("text=Step 5 of 7").count() == 1)
    rpage.goto(f"{BASE}/students/{sid}/edit"); rpage.wait_for_selector("summary:has-text('Extra services')")
    rpage.locator("summary:has-text('Extra services')").click()
    check("front desk: the profile lists the services but has no controls to change them", rpage.get_by_role("radio", name=re.compile("No transport")).count() == 0 and rpage.locator("text=Transport - Kela").count() >= 1)
    status = rpage.evaluate("""async (id) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const r = await fetch(`/students/${id}/optional-services`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'Accept': 'application/json'}, body: JSON.stringify({optional_service_id: 1})});
        return r.status;
    }""", sid)
    check("front desk: the server refuses too (403)", status == 403, str(status))

    # ================================================================ 5. phone layout
    mctx, mpage = login(browser, "principal@demo.test", mobile=True)
    to_class_step(mpage, "Phonecheck")
    mpage.wait_for_selector("text=Step 5 of 8")
    check("phone: the services step fits the screen (no sideways scrolling) with big buttons", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth") and min(mpage.locator("form button[role=radio], form button[role=checkbox]").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    mpage.screenshot(path=f"{SHOTS}/phone_services.png", full_page=True)

    for name, pg in [("principal", page), ("accountant", apage), ("front desk", rpage), ("phone", mpage)]:
        check(f"no JavaScript errors ({name})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
