"""Browser test: the complete receptionist workflow, start to finish, as registrar@demo.test.

Home tile -> register a student (name, about, class cards, parent, parent details, review) -> the "registered"
confirmation -> print the ID card -> find the student again (name, words in any order, ID number, parent's name,
parent's phone, partial phone, nothing found) -> open the profile -> link/unlink a parent -> move class -> permissions
for a read-only user -> phone layout and Amharic/Oromo/Tigrinya screens.

Uses the usability-session data (see tests/e2e/role_home.py for how to serve it) and changes it: it registers a
student, links parents and moves them, and temporarily makes Grade 9 A "full". Only run it against that throwaway
database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/receptionist.py
"""
import os, re, sqlite3, sys, tempfile, time
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="receptionist_e2e_")
results = []
STAMP = str(int(time.time()))[-6:]
EMAIL = f"aster.{STAMP}@family.test"
PHONE = "0911 55 66 77"


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


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
    return ctx, page


def eventually(sql, expected, *args, timeout=12):
    """Poll the database until a save has landed (the dev server is single-threaded; never assume a delay)."""
    end = time.time() + timeout
    while time.time() < end:
        rows = q(sql, *args)
        if rows and rows[0][0] == expected:
            return True
        time.sleep(0.4)
    return False


H = "e => Math.round(e.offsetHeight)"
nxt = lambda page: page.locator("form").get_by_role("button", name=re.compile(r"^(Next|ቀጣይ|Itti fufi|ቀጽል)$"))
step_heading = lambda page: page.locator("form h3").first.inner_text()
RAW = re.compile(r"\b(?:enroll|find|student|wizard|common|ui|status|confirm|home|menu)\.[a-z_]+\b")

# make Grade 9 A "full" for the registrar (who may not override capacity): capacity = its current student count
g9 = q("select s.id, s.capacity, (select count(*) from enrollments e where e.section_id=s.id and e.status='active') from sections s join grades g on g.id=s.grade_id where g.name='Grade 9'")[0]
q("update sections set capacity=? where id=?", g9[2], g9[0])
before_students = q("select count(*) from students")[0][0]
q("update users set locale=null where email='registrar@demo.test'")

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "registrar@demo.test")

    # ---------------------------------------------------------------- 1. from the home tile
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks]")
    page.get_by_role("link", name=re.compile("Register Student")).first.click()
    page.wait_for_selector("form h3")
    check("home tile opens the registration wizard", "Register a new student" in page.inner_text("h1"))

    # ---------------------------------------------------------------- 2. name
    check("step 1: 'Step 1 of 7' and one clear question", page.locator("text=Step 1 of 7").count() == 1 and step_heading(page) == "What is the child's name?")
    fields = page.locator("form input:not([type=hidden])")
    check("step 1: three fields, all large, only the first name required", fields.count() == 3 and min(fields.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56 and page.locator("form label:has-text('(Optional)')").count() == 2)
    check("step 1: Next is off and says why", nxt(page).is_disabled() and page.locator("text=Type the child's first name to continue").count() == 1)
    page.fill("#first_name", "Meklit"); page.fill("#father_name", "Girma"); page.fill("#grandfather_name", "Tesfaye")
    nxt(page).click()

    # ---------------------------------------------------------------- 3. about
    page.wait_for_selector("text=Step 2 of 7")
    check("step 2: gender is a choice nobody made for you (nothing preselected) and Next is off", page.locator("button[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled() and page.locator("text=Choose the gender to continue").count() == 1)
    girl = page.get_by_role("radio", name="Girl"); boy = page.get_by_role("radio", name="Boy")
    check("step 2: gender buttons are >= 56px", girl.evaluate(H) >= 56 and boy.evaluate(H) >= 56)
    girl.click()
    selects = page.locator("form select")
    check("step 2: date-of-birth selects are large (>= 56px)", selects.count() == 3 and min(selects.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56, str(selects.evaluate_all("els => els.map(e => e.offsetHeight)")))
    page.get_by_label("Day").select_option("5"); page.get_by_label("Month").select_option("3"); page.get_by_label("Year").select_option("2010")
    det = page.locator("details:has-text('More details (optional)')")
    check("step 2: rarely-used fields are folded away (progressive disclosure)", det.evaluate("e => e.open") is False)
    det.locator("summary").click()
    check("step 2: ...and open with one tap (4 optional fields)", det.locator("input").count() == 4)
    page.fill("#address", "Bole, Addis Ababa")
    nxt(page).click()

    # ---------------------------------------------------------------- 4. class cards
    page.wait_for_selector("text=Step 3 of 7")
    check("step 3: the photo step is optional, says so, and Next is already on", step_heading(page) == "Student photo" and "skip this" in page.locator("form").inner_text() and nxt(page).is_enabled())
    check("step 3: 'Take Photo' and 'Choose Photo' are two large buttons and there is a placeholder", page.get_by_role("button", name="Take Photo").evaluate(H) >= 56 and page.get_by_role("button", name="Choose Photo").evaluate(H) >= 56 and page.locator("[data-testid=photo-placeholder]").count() == 1)
    nxt(page).click()

    page.wait_for_selector("text=Step 4 of 7")
    check("step 3: the question names the child", "Which class will Meklit join?" in step_heading(page))
    check("step 3: many classes -> pick the grade first (large buttons), no long list", page.get_by_role("radio", name=re.compile("^Grade \\d+$")).count() >= 8 and page.locator("text=Pick a grade to see its classes").count() == 1)
    page.get_by_role("radio", name="Grade 9", exact=True).click()
    full_card = page.get_by_role("radio", name=re.compile("Grade 9 A"))
    check("step 3: a FULL class is disabled for someone who may not override, and says why", full_card.is_disabled() and "FULL" in full_card.inner_text() and "ask an administrator" in full_card.inner_text(), full_card.inner_text().replace("\n", " | "))
    page.get_by_role("radio", name="Grade 4", exact=True).click()
    card = page.get_by_role("radio", name=re.compile("Grade 4 A"))
    txt = card.inner_text().replace("\n", " | ")
    check("step 3: the class card shows teacher and free places", "Teacher:" in txt and re.search(r"\d+ of \d+ places left", txt) is not None, txt)
    check("step 3: class cards are >= 64px", card.evaluate(H) >= 64)
    check("step 3: nothing is selected until you choose; Next is off", card.get_attribute("aria-checked") == "false" and nxt(page).is_disabled())
    card.click()
    check("step 3: choosing gives a strong selected state and enables Next", card.get_attribute("aria-checked") == "true" and nxt(page).is_enabled())
    check("step 3: the start date is folded ('Starts today') but one tap away", page.locator("details:has-text('Starts today')").count() == 1)
    nxt(page).click()

    # ---------------------------------------------------------------- 5. parent
    page.wait_for_selector("text=Step 5 of 7")
    modes = page.locator("button[role=radio]")
    check("step 4: three clear choices, none preselected, Next off", modes.count() == 3 and page.locator("button[role=radio][aria-checked=true]").count() == 0 and nxt(page).is_disabled())
    page.get_by_role("radio", name=re.compile("A new parent account")).click()
    rel = page.locator("#guardian_relationship")
    check("step 4: relationship must be chosen (no silent 'father' default) before Next", rel.input_value() == "" and nxt(page).is_disabled() and page.locator("text=how the parent is related").count() == 1)
    rel.select_option("mother")
    nxt(page).click()

    # ---------------------------------------------------------------- 6. parent details
    page.wait_for_selector("text=Step 6 of 7")
    check("step 5: parent name, phone (optional), email; Next off until name + email", page.locator("form input:not([type=hidden])").count() == 3 and nxt(page).is_disabled())
    page.fill("#guardian_name", "Aster Girma"); page.fill("#guardian_phone", PHONE); page.fill("#guardian_email", EMAIL)
    nxt(page).click()

    # ---------------------------------------------------------------- 7. review
    page.wait_for_selector("text=Step 7 of 7")
    body = page.locator("form dl").inner_text()
    check("review: shows child, gender, birth date, class, parent and contact", all(s in body for s in ["Meklit Girma Tesfaye", "Girl", "Grade 4 A", "Aster Girma (Mother)", PHONE, EMAIL]), body.replace("\n", " | ")[:200])
    check("review: arriving here registered NOTHING", q("select count(*) from students")[0][0] == before_students)
    final = page.get_by_role("button", name="Register Meklit")
    check("review: the final button names the outcome and is >= 56px", final.count() == 1 and final.evaluate(H) >= 56)
    page.get_by_role("button", name="Change").first.click()
    page.wait_for_selector("text=Step 1 of 7")
    check("review: 'Change' goes back with everything kept", page.input_value("#first_name") == "Meklit")
    for _ in range(6):
        nxt(page).click(); page.wait_for_timeout(150)
    page.screenshot(path=f"{SHOTS}/wizard_review.png", full_page=True)
    page.get_by_role("button", name="Register Meklit").click()

    # ---------------------------------------------------------------- 8. confirmation
    page.wait_for_selector("[role=status]:has-text('is registered')", timeout=20000)
    row = q("select id, student_id_number, first_name, father_name, grandfather_name, gender, date_of_birth, address from students where first_name='Meklit' order by id desc limit 1")[0]
    sid, idno = row[0], row[1]
    check("saved: one student with the right names, gender, address and a birth date", row[2:6] == ("Meklit", "Girma", "Tesfaye", "female") and row[7] == "Bole, Addis Ababa" and row[6] is not None, str(row))
    check("saved: placed in Grade 4 A", q("select g.name || ' ' || s.name from enrollments e join sections s on s.id=e.section_id join grades g on g.id=s.grade_id where e.student_id=?", sid)[0][0] == "Grade 4 A")
    check("saved: parent account created and linked as mother, phone kept", q("select u.name, ps.relationship, u.phone from parent_student ps join users u on u.id=ps.parent_id where ps.student_id=?", sid)[0][:2] == ("Aster Girma", "mother"))
    panel = page.locator("[role=status]:has-text('is registered')")
    check("confirmation names the student, ID number, class and parent", all(s in panel.inner_text() for s in ["Meklit Girma Tesfaye", idno, "Grade 4 A", "Aster Girma"]))
    acts = panel.locator("a")
    check("confirmation offers Print ID card / Open profile / Register another (all >= 56px)", [a.strip() for a in acts.all_inner_texts()] == ["Print ID card", "Open Student", "Register another student"] and min(acts.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    check("the one-time link for the new parent is shown to hand over", page.locator("text=/link/i").count() > 0)
    check("the confirmation is not duplicated by a second 'enrolled as' banner", page.locator("text=enrolled as").count() == 0)
    page.screenshot(path=f"{SHOTS}/registered.png", full_page=True)

    # ---------------------------------------------------------------- 9. print the ID card
    panel.get_by_role("link", name="Print ID card").click()
    page.wait_for_url(re.compile(r"/students/\d+/id-card"))
    pb = page.get_by_role("button", name="Print ID card")
    pb.wait_for()
    check("ID card page: 'Print ID card' is a large primary button", pb.count() == 1 and pb.evaluate(H) >= 56)
    page.evaluate("window.__printed = 0; window.print = () => { window.__printed++ }; true")
    pb.click()
    check("ID card page: the button prints once", page.evaluate("window.__printed") == 1)
    check("ID card page: explains the print dialog and Save as PDF", "Save as PDF" in page.inner_text("body"))
    page.screenshot(path=f"{SHOTS}/idcard.png", full_page=True)

    # ---------------------------------------------------------------- 10. find again
    page.get_by_role("link", name="Students", exact=True).first.click()
    page.wait_for_selector("#student-search")
    check("Find a student: the search box is focused, large, and says what to type", page.evaluate("document.activeElement && document.activeElement.id") == "student-search" and page.locator("#student-search").evaluate(H) >= 56 and "parent's phone" in page.locator("#student-search").get_attribute("placeholder"))
    check("Find a student: with nothing typed, the newest students are listed first", "Meklit" in page.locator("[data-testid=student-result]").first.inner_text())
    search = page.locator("#student-search")

    def find(text, expect_name="Meklit"):
        # wait for the answer (a phone search asks the server after a short pause) instead of sleeping a fixed time
        search.fill(text)
        sel = "[data-testid=student-result]:has-text('" + expect_name + "')"
        try:
            page.wait_for_selector(sel, timeout=8000)
        except Exception:
            return 0
        page.wait_for_timeout(300)
        return page.locator(sel).count()

    check("find by first name", find("Meklit") == 1)
    check("find by words in any order", find("girma meklit") == 1)
    check("find by ID number", find(idno) == 1, idno)
    check("find by the parent's name", find("aster girma") == 1)
    check("find by the parent's phone, however it is typed", find("0911556677") == 1 and find("+251 911 55 66 77") == 1 and find("55 66") == 1)
    r = page.locator("[data-testid=student-result]:has-text('Meklit')").first
    check("a result shows name, class, ID number and parent", all(s in r.inner_text() for s in ["Meklit Girma Tesfaye", "Grade 4 — Section A", idno, "Aster Girma"]))
    check("a result offers one large 'Open Student' button", r.locator("a").count() == 1 and r.get_by_role("link", name="Open Student").evaluate(H) >= 56)
    search.fill("zzzqqq"); page.wait_for_timeout(500)
    empty = page.locator("text=No student found for")
    check("nothing found: a clear message with what to try and what to do next", empty.count() == 1 and page.get_by_role("link", name="Register a new student").count() >= 1 and page.get_by_role("button", name="Clear the search").count() == 2)
    page.locator("main button:has-text('Clear the search')").click()
    check("clearing returns to the newest students and refocuses the box", search.input_value() == "" and page.evaluate("document.activeElement.id") == "student-search")
    check("the list never contains a parent's phone number in the page itself", PHONE.replace(" ", "")[-7:] not in page.content().replace(" ", ""))
    search.fill("Meklit"); page.wait_for_timeout(500)
    page.locator("[data-testid=student-result]:has-text('Meklit')").get_by_role("link", name="Open Student").first.click()   # (a mother called Meklit also matches, so pick the child by name)
    page.wait_for_selector("[data-testid=student-actions]")
    page.get_by_role("link", name="View and edit profile").click()
    page.wait_for_url(re.compile(r"/students/\d+/edit"), timeout=20000)

    # ---------------------------------------------------------------- 11. profile
    page.wait_for_selector("text=Parents and guardians")
    prof = page.inner_text("main")
    check("profile: class, ID number and status come first", "Grade 4 A" in prof.split("Parents and guardians")[0] and idno in prof.split("Parents and guardians")[0] and "Active" in prof.split("Parents and guardians")[0], page.url + " | " + prof[:200].replace(chr(10), " / "))
    check("profile: 'Print ID card' and 'Report card' are large buttons at the top", page.get_by_role("link", name="Print ID card").evaluate(H) >= 56 and page.get_by_role("link", name="Report card").count() == 1)
    call = page.locator("a[href^='tel:']")
    check("profile: the parent's number is there to call", call.count() == 1 and "0911 55 66 77" in call.inner_text(), call.inner_text() if call.count() else "")
    folded = page.locator("details:not([open]) summary").all_inner_texts()
    check("profile: less-used sections are folded, each with a worded summary line", any("Main information" in s for s in folded) and any("More details" in s for s in folded) and any("Move to another class" in s for s in folded) and any("Withdraw this student" in s for s in folded), str([f.split(chr(10))[0] for f in folded]))
    page.get_by_role("button", name=re.compile("Link a parent or guardian")).click()
    page.get_by_label("The parent's email").fill("parent@demo.test")
    page.locator("#ng_rel").select_option("father")
    page.get_by_role("button", name=re.compile("Link this parent to Meklit")).click()
    page.wait_for_selector("text=Kebede Worku")
    check("profile: a second (existing) parent is linked", eventually("select count(*) from parent_student where student_id=?", 2, sid))
    page.get_by_role("button", name="Unlink").last.click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("unlink asks properly (dialog, outcome-named button, Cancel focused), not a browser box", "Unlink this parent?" in dlg.inner_text() and dlg.get_by_role("button", name="Unlink Parent").count() == 1 and page.evaluate("document.activeElement.innerText.trim()") == "Cancel")
    page.keyboard.press("Escape"); dlg.wait_for(state="detached")
    check("...Escape keeps the parent", q("select count(*) from parent_student where student_id=?", sid)[0][0] == 2)
    page.get_by_role("button", name="Unlink").last.click(); page.get_by_role("dialog").get_by_role("button", name="Unlink Parent").click()
    check("...confirming unlinks", eventually("select count(*) from parent_student where student_id=?", 1, sid))
    page.locator("[data-testid=group-more] summary").click()
    page.fill("#address", "Bole, Addis Ababa (Sub-city 03)")
    page.get_by_role("button", name=re.compile("Save Meklit's details")).click()
    check("profile: details can still be edited and saved", eventually("select address from students where id=?", "Bole, Addis Ababa (Sub-city 03)", sid))
    page.locator("summary:has-text('Move to another class')").click()
    check("profile: 'move class' uses class cards, not a dropdown", page.locator("details[open] button[role=radio]").count() >= 1 and page.get_by_role("button", name="Pick a class to move to").is_disabled())
    page.get_by_role("radio", name=re.compile("Grade 6 A")).click()
    page.get_by_role("button", name=re.compile("Move Meklit to Grade 6 A")).click()
    d2 = page.get_by_role("dialog"); d2.wait_for()
    check("moving asks in a dialog and names the outcome", d2.get_by_role("button", name="Move student").count() == 1)
    d2.get_by_role("button", name="Move student").click()
    check("...and the student is now in Grade 6 A", eventually("select g.name || ' ' || s.name from enrollments e join sections s on s.id=e.section_id join grades g on g.id=s.grade_id where e.student_id=? and e.status='active'", "Grade 6 A", sid))
    page.locator("summary:has-text('Withdraw this student')").click()
    wbtn = page.get_by_role("button", name="Withdraw Meklit")
    check("withdraw is in a red, folded corner and stays off until a reason is typed", wbtn.is_disabled())
    page.screenshot(path=f"{SHOTS}/profile.png", full_page=True)
    check("no JavaScript errors in the whole flow", not page.js_errors, "; ".join(page.js_errors)[:200])
    ctx.close()

    # ---------------------------------------------------------------- 12. permissions
    ctx, aud = login(browser, "auditor@demo.test")
    aud.goto(f"{BASE}/students/{sid}/edit"); aud.wait_for_selector("text=Parents and guardians")
    check("auditor: sees the profile read-only, no parent phone, no controls", "look at this record but not change it" in aud.inner_text("main") and aud.locator("a[href^='tel:']").count() == 0 and aud.get_by_role("button", name=re.compile("Link a parent|Unlink|Withdraw")).count() == 0)
    check("auditor: cannot open the registration wizard", aud.goto(f"{BASE}/students/create").status == 403)
    ctx.close()
    ctx, tch = login(browser, "teacher@demo.test")
    check("teacher: still refused the student list", tch.goto(f"{BASE}/students").status == 403)
    ctx.close()

    # ---------------------------------------------------------------- 13. phone layout
    ctx, ph = login(browser, "registrar@demo.test", mobile=True)
    ph.goto(f"{BASE}/students/create"); ph.wait_for_selector("form h3")
    check("phone: wizard has no horizontal overflow and Next stacks above Back", ph.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
    check("phone: the top bar keeps the title on one line", ph.locator("header h2").evaluate("e => e.offsetHeight") <= 32, str(ph.locator("header h2").evaluate("e => e.offsetHeight")))
    ph.fill("#first_name", "Test"); nxt(ph).click(); ph.wait_for_selector("text=Step 2 of 7"); ph.get_by_role("radio", name="Boy").click(); nxt(ph).click(); ph.wait_for_selector("text=Step 3 of 7"); nxt(ph).click(); ph.wait_for_selector("text=Step 4 of 7")
    ph.get_by_role("radio", name="Grade 4", exact=True).click()
    check("phone: class cards fit and are one column", ph.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0 and len(set(ph.get_by_role("radio", name=re.compile("Grade 4 A")).evaluate_all("els => els.map(e => Math.round(e.getBoundingClientRect().left))"))) == 1)
    ph.screenshot(path=f"{SHOTS}/wizard_class_phone.png", full_page=True)
    ph.goto(f"{BASE}/students"); ph.wait_for_selector("#student-search")
    check("phone: Find a student fits, cards stack, buttons full width", ph.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
    ph.screenshot(path=f"{SHOTS}/find_phone.png", full_page=True)
    ctx.close()

    # ---------------------------------------------------------------- 14. languages
    for loc in ("am", "om", "ti"):
        q("update users set locale=? where email='registrar@demo.test'", loc)
        ctx, lp = login(browser, "registrar@demo.test", mobile=True)
        lp.goto(f"{BASE}/students/create"); lp.wait_for_selector("form h3")
        seen = []
        lp.fill("#first_name", "Test")
        for step in range(6):
            body = lp.inner_text("main")
            seen += RAW.findall(body)
            over = lp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
            if over > 0: seen.append(f"overflow {over}px on step {step + 1}")
            btns = lp.locator("form button:not([role=radio])")
            if step == 1: lp.locator("button[role=radio]").nth(1).click()
            if step == 3:
                lp.locator("button[role=radio]", has_text=re.compile("^(?!.*Grade)")).count()
                lp.locator("form button[role=radio]").first.click()      # first grade button
                lp.locator("form button[role=radio]:not([disabled])").nth(9).click() if lp.locator("form button[role=radio]:not([disabled])").count() > 9 else None
            if step == 4:
                lp.locator("button[role=radio]").first.click(); lp.locator("select").select_option("mother")
            if step == 5:
                lp.fill("#guardian_name", "Test Parent"); lp.fill("#guardian_email", f"t{STAMP}{loc}@x.test")
            nx = btns.last
            if nx.is_enabled(): nx.click(); lp.wait_for_timeout(250)
            else: seen.append(f"stuck on step {step + 1}")
        seen += RAW.findall(lp.inner_text("main"))
        check(f"{loc}: wizard is fully translated (no raw keys), no overflow, reaches the review", not seen and lp.locator("form dl").count() == 1, str(seen[:5]))
        lp.goto(f"{BASE}/students"); lp.wait_for_selector("#student-search")
        raw2 = RAW.findall(lp.inner_text("main"))
        check(f"{loc}: Find a student is translated", not raw2 and lp.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0, str(raw2[:5]))
        lp.goto(f"{BASE}/students/{sid}/edit"); lp.wait_for_selector("details")
        raw3 = RAW.findall(lp.inner_text("main"))
        check(f"{loc}: the profile is translated", not raw3 and lp.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0, str(raw3[:5]))
        if loc == "am":
            lp.screenshot(path=f"{SHOTS}/profile_am_phone.png", full_page=True)
        ctx.close()
    q("update users set locale=null where email='registrar@demo.test'")
    browser.close()

q("update sections set capacity=? where id=?", g9[1], g9[0])
print(f"\n{sum(results)}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if all(results) else 1)
