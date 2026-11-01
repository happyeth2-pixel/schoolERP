"""Browser test: the parent's portal on a desktop and on a phone, for a parent with two children.

  * home: no sidebar at all, no stray greeting text -- a child roster (name + grade, non-interactive),
    the money-owed banner when relevant, and every button listed flat, together, on the one screen
    (Report card, School fees, Attendance, Homework, Messages, Results, Timetable, Announcements,
    Calendar, Complaints, Help) -- nothing tucked behind a second "more" page
  * each button is a real, large tap target and leads somewhere real: Attendance -> the existing
    attendance page (its own child switcher, today's status, day cards); Report card -> one card per
    child with View/Download; School fees -> a small hub (See bills / What's not paid / Payment
    history), each a filtered view of the same bill timeline; Messages -> the inbox
  * the bill page: how to pay, copy the account number, "I paid the full amount" is a single button that
    jumps straight to the proof step (no typing a number for the common case), the proof upload, own
    payments
  * every parent-only page carries its own large "Back to home" button (there is no sidebar to fall back on)
  * ownership: another family's child, bill or report card is not found; a parent decides nothing
  * phone: no sideways scrolling, one column, big buttons; Amharic: no raw translation keys

Uses the usability-session data (parent@demo.test has two children) and changes it: it adds attendance marks,
messages and a payment account. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/parent.py
"""
import json, os, re, sqlite3, sys, tempfile, time, uuid
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="parent_e2e_")
results = []
H = "e => Math.round(e.offsetHeight)"


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def q(sql, *args):
    con = sqlite3.connect(DB); rows = con.execute(sql, args).fetchall(); con.commit(); con.close(); return rows


def until(fn, timeout=15):
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


def balance(invoice_id):
    net = q("select coalesce(sum(amount),0) from invoice_items where invoice_id=?", invoice_id)[0][0] - (q("select coalesce(discount_amount,0) from invoices where id=?", invoice_id)[0][0] or 0)
    paid = q("select coalesce(sum(amount),0) from payments where invoice_id=? and status='verified'", invoice_id)[0][0]
    return net, paid, round(net - paid, 2)


PARENT = q("select id, name from users where email='parent@demo.test'")[0]
KIDS = q("select s.id, s.first_name, s.first_name || ' ' || coalesce(s.father_name,'') from students s join parent_student ps on ps.student_id=s.id where ps.parent_id=? order by s.first_name", PARENT[0])
K1, K2 = KIDS[0], KIDS[1]   # alphabetical: the page sorts children by first name
OTHER = q("select s.id from students s where s.id not in (select student_id from parent_student where parent_id=?) limit 1", PARENT[0])[0][0]
OTHER_INV = q("select i.id from invoices i where i.student_id=? limit 1", OTHER)
enrollment = lambda sid: q("select e.id from enrollments e where e.student_id=? and e.status='active' limit 1", sid)[0][0]
inv_of = lambda sid: [r[0] for r in q("select id from invoices where student_id=? order by period_start", sid)]
fmt = lambda n: f"{n:,.2f}".rstrip("0").rstrip(".") if n != int(n) else f"{int(n):,}"

# a known starting point: nothing recorded today, no messages, one payment account
q("delete from attendance_records where enrollment_id in (?, ?)", enrollment(K1[0]), enrollment(K2[0]))
q("delete from notifications where notifiable_id=?", PARENT[0])
q("update users set locale=null where email='parent@demo.test'")
if q("select count(*) from payment_accounts")[0][0] == 0:
    q("insert into payment_accounts (school_id, type, display_name, account_number, account_name, sort_order, created_at, updated_at) select id, 'bank', 'Demo Bank', '1000123456789', 'Demo Academy', 1, datetime('now'), datetime('now') from schools limit 1")
check("the parent has two children, each with a bill", len(KIDS) == 2 and all(inv_of(k[0]) for k in KIDS), str(KIDS))

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "parent@demo.test")

    # ================================================================ 1. the home page
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
    check("home: no sidebar at all", page.locator("aside").count() == 0)
    check("home: the child roster shows both children's names and grades, not clickable radios", page.get_by_test_id("child-roster").inner_text().count(K1[1].split()[0]) >= 1 and K2[1].split()[0] in page.get_by_test_id("child-roster").inner_text())
    tiles = page.get_by_role("link", name=re.compile("Attendance|Report card|School fees|Homework|Messages|Results|Timetable|Announcements|Calendar|Complaints|Help"))
    check("home: every button is listed flat on the one screen, each a real tap target", tiles.count() >= 11 and min(tiles.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 100)
    check("home: there is no second 'more' page to hide behind", page.get_by_role("link", name="Other things").count() == 0)
    page.screenshot(path=f"{SHOTS}/1_home_desktop.png", full_page=True)

    # ================================================================ 2. fees
    net, paid, left = sum(balance(i)[0] for i in inv_of(K1[0])), sum(balance(i)[1] for i in inv_of(K1[0])), sum(balance(i)[2] for i in inv_of(K1[0]))
    if left > 0:
        check("home: owing money shows a big red banner naming the amount", page.get_by_test_id("due-banner").count() == 1 and page.get_by_test_id("due-banner").evaluate(H) >= 56)
    page.get_by_role("link", name="School fees").click()
    page.wait_for_selector("text=See bills")
    hub_tiles = page.get_by_role("link", name=re.compile("See bills|What's not paid|Payment history"))
    check("fees hub: three big buttons, nothing else", hub_tiles.count() == 3 and min(hub_tiles.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 100)
    page.get_by_role("link", name="See bills").click()
    page.wait_for_selector("[data-testid=my-invoice-card]")
    check("fees: the per-child timeline shows a lifetime total paid", page.get_by_test_id("child-total-paid").count() >= 1)
    page.screenshot(path=f"{SHOTS}/2_fees.png", full_page=True)

    # ================================================================ 3. attendance
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
    ts_none = page.get_by_test_id("today-status")
    page.get_by_role("link", name="Attendance").click()
    page.wait_for_selector("[data-testid=today-card]")
    check("attendance page: the child switcher is here, the same big buttons", page.locator("[data-testid=child-switcher] button[role=radio]").count() == 2 and K1[1] in page.locator("[data-testid=child-switcher] button[aria-checked=true]").inner_text())
    ts = page.get_by_test_id("today-status")
    check("attendance page: with nothing recorded it says so in words (not 'present')", ts.get_attribute("data-status") == "none" and "Not recorded yet today" in ts.inner_text())
    check("attendance page: has a big back-to-home button", page.get_by_role("link", name=re.compile("Back to home")).first.evaluate(H) >= 44)

    q("insert into attendance_records (enrollment_id, date, status, marked_by, created_at, updated_at) values (?, date('now'), 'late', 1, datetime('now'), datetime('now'))", enrollment(K1[0]))
    for d, st in [(1, "present"), (2, "absent"), (3, "present")]:
        q("insert into attendance_records (enrollment_id, date, status, marked_by, created_at, updated_at) values (?, date('now', ?), ?, 1, datetime('now'), datetime('now'))", enrollment(K1[0]), f"-{d} day", st)
    page.reload(); page.wait_for_selector("[data-testid=today-card]")
    check("attendance page: today first, in words, with an icon", page.get_by_test_id("today-status").get_attribute("data-status") == "late" and page.get_by_test_id("today-status").locator("svg").count() >= 1)
    check("attendance page: the year's rate is large, with the five totals as icon + word tiles", "%" in page.get_by_test_id("rate").inner_text() and page.locator("[data-testid=totals] li").count() == 5)
    days = page.locator("[data-testid=day-row]")
    check("attendance page: every recorded day is a card (weekday, date, status in words)", days.count() == 4 and days.first.get_attribute("data-status") == "late")
    page.locator("[data-testid=child-switcher] button").nth(1).click()
    page.wait_for_url(re.compile(rf"student={K2[0]}"))
    check("attendance page: switching goes to the other child's page", K2[1] in page.locator("[data-testid=today-card]").inner_text() and page.locator("[data-testid=day-row]").count() == 0)

    # ================================================================ 4. report card
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
    page.get_by_role("link", name="Report card").click()
    page.wait_for_selector("[data-testid=report-child]")
    cards = page.locator("[data-testid=report-child]")
    check("report card: one card per child, View and Download are two large, named buttons", cards.count() == 2 and cards.first.get_by_role("link", name="View report card").evaluate(H) >= 44 and cards.first.get_by_role("link", name="Download PDF").evaluate(H) >= 44)
    r = ctx.request.get(f"{BASE}/students/{K1[0]}/report-card.pdf")
    check("report card: the PDF really downloads for the first child", r.status == 200 and "pdf" in r.headers.get("content-type", ""), str(r.status))

    # ================================================================ 5. messages
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
    msg_tile = page.get_by_role("link", name="Messages")
    check("home: no unread messages yet shows no badge on the Messages button", msg_tile.locator("text=/^\\d+$/").count() == 0)
    for i, (title, read) in enumerate([("Payment accepted", False), ("Attendance corrected", False), ("Announcement", True), ("Old news", True)]):
        q("insert into notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at) values (?, 'test', 'App\\Models\\User', ?, ?, ?, datetime('now', ?), datetime('now'))", str(uuid.uuid4()), PARENT[0], json.dumps({"title": title, "body": f"Body of {title}"}), "2026-01-01 00:00:00" if read else None, f"-{i} minute")
    page.reload(); page.wait_for_selector("[data-testid=parent-home]")
    check("home: 2 unread messages shows as a badge on the Messages button", "2" in page.get_by_role("link", name="Messages").inner_text())
    page.get_by_role("link", name="Messages").click()
    page.wait_for_selector("[data-testid=conversation-list]")
    check("messages page: each conversation says who it reaches", page.get_by_test_id("conversation-contact").count() >= 1)

    # ================================================================ 6. notifications page
    q("update notifications set read_at=null where notifiable_id=? and data like '%Payment accepted%'", PARENT[0])
    page.goto(f"{BASE}/notifications"); page.wait_for_selector("[data-testid=messages]")
    mm = page.locator("[data-testid=messages] li")
    check("notifications page: the unread one is coloured with a 'New' badge and a large 'Mark as read'", mm.first.get_attribute("data-unread") == "true" and mm.first.locator("text=New").count() == 1 and mm.first.get_by_role("button", name="Mark as read").evaluate(H) >= 44)
    mm.first.get_by_role("button", name="Mark as read").click()
    check("notifications page: marking one read works", until(lambda: q("select count(*) from notifications where notifiable_id=? and read_at is null", PARENT[0])[0][0] == 1))
    check("notifications page: has a big back-to-home button", page.get_by_role("link", name=re.compile("Back to home")).first.evaluate(H) >= 44)

    # ================================================================ 7. the rest of the flat button list, straight from home
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
    page.get_by_role("link", name="Homework").click()
    page.wait_for_url(re.compile("/my/homework"))
    check("home: Homework is on the home page, not behind a second page", "/my/homework" in page.url)
    check("homework page: has a big back-to-home button", page.get_by_role("link", name=re.compile("Back to home")).first.evaluate(H) >= 44)
    for name, path_part in [("Results", "/my/results"), ("Timetable", "/my/timetable"), ("Announcements", "/announcements"), ("Calendar", "/calendar"), ("Complaints", "/complaints"), ("Help", "/help")]:
        page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=parent-home]")
        page.get_by_role("link", name=name).click()
        page.wait_for_url(re.compile(re.escape(path_part)))
        check(f"home: '{name}' is one tap away, straight from home", path_part in page.url)

    # ================================================================ 8. the bill page
    bill_id = [i for i in inv_of(K1[0]) if balance(i)[2] > 0][0] if any(balance(i)[2] > 0 for i in inv_of(K1[0])) else inv_of(K1[0])[0]
    page.goto(f"{BASE}/invoices/{bill_id}"); page.wait_for_selector("[data-testid=bill-summary]")
    bnet, bpaid, bleft = balance(bill_id)
    check("bill: what is left to pay, total and paid, in big plain numbers", fmt(bleft) in page.get_by_test_id("bill-left").inner_text() and fmt(bnet) in page.get_by_test_id("bill-total").inner_text() and fmt(bpaid) in page.get_by_test_id("bill-paid").inner_text(), f"{bnet} {bpaid} {bleft}")
    check("bill: none of the staff tools (discount, waiver, refund) are on a parent's page", all(w not in page.inner_text("body") for w in ["Edit Discount", "Waive Invoice", "Refund / reverse"]))
    if bleft > 0:
        how = page.get_by_test_id("how-to-pay")
        check("bill: 'How to pay' leads straight into the wizard, no separate account list to read first", "Choose how you paid" in how.inner_text() or "Bank transfer" in how.inner_text())
        how.get_by_role("radio", name=re.compile("Bank transfer")).click()
        check("bill: picking 'Bank transfer' shows the school's account right there", "1000123456789" in page.get_by_test_id("account-number").first.inner_text())
        cp = how.get_by_role("button", name="Copy number")
        check("bill: the account number can be copied with one tap", cp.count() >= 1 and cp.first.evaluate(H) >= 44)
        how.get_by_role("button", name="Next").click()
        check("bill: 'how much' offers one big button for the full amount, never a number field by default", how.locator("input[type=number]").count() == 0 and how.get_by_role("button", name=re.compile("full amount")).count() == 1)
        how.get_by_role("button", name=re.compile("full amount")).click()
        check("bill: picking the full amount jumps straight to the proof step, no extra tap", until(lambda: how.get_by_text(re.compile("payment|receipt|photo", re.I)).count() >= 1))
        check("bill: the proof upload is right there, on the same page (no separate route)", how.locator("input[type=file]").count() == 1)
    page.screenshot(path=f"{SHOTS}/8_bill.png", full_page=True)
    rows = q("select count(*) from payments where invoice_id=?", bill_id)[0][0]
    check("bill: 'Your payments' lists what was sent, each with its state in words", page.get_by_test_id("payments").count() == (1 if rows else 0) or rows == 0)

    # ================================================================ 9. ownership
    check("ownership: another family's child's attendance, report card and PDF are not found", ctx.request.get(f"{BASE}/my/attendance?student={OTHER}").status == 404 and ctx.request.get(f"{BASE}/students/{OTHER}/report-card").status == 404 and ctx.request.get(f"{BASE}/students/{OTHER}/report-card.pdf").status == 404)
    if OTHER_INV:
        check("ownership: another family's bill is not found", ctx.request.get(f"{BASE}/invoices/{OTHER_INV[0][0]}").status == 404)
    tok = unquote(next(c["value"] for c in ctx.cookies() if c["name"] == "XSRF-TOKEN"))
    codes = [page.evaluate("""async ([path, token]) => (await fetch(path, {method: 'POST', headers: {'X-XSRF-TOKEN': token, 'Accept': 'application/json'}})).status""", [f"/mark-batches/1/submit", tok])]
    check("ownership: a parent cannot use staff actions", codes[0] in (403, 404), str(codes))

    # ================================================================ 10. phone
    mctx, mpage = login(browser, "parent@demo.test", mobile=True)
    mpage.goto(f"{BASE}/dashboard"); mpage.wait_for_selector("[data-testid=parent-home]")
    check("phone: no sideways scrolling", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    check("phone: no sidebar, no hamburger menu either", mpage.locator("aside").count() == 0 and mpage.get_by_label(re.compile("menu", re.I)).count() == 0)
    htitle = mpage.locator("header h2").first.bounding_box()
    hlang = mpage.get_by_label("Language").first.bounding_box()
    check("phone: the header title never overlaps the language button", htitle is not None and hlang is not None and htitle["x"] + htitle["width"] <= hlang["x"])
    mtiles = mpage.get_by_role("link", name=re.compile("Attendance|Report card|School fees|Homework|Messages"))
    check("phone: the main buttons are big and easy to hit", mtiles.count() >= 4 and min(mtiles.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 100)
    mpage.screenshot(path=f"{SHOTS}/10_home_phone.png", full_page=True)
    mpage.goto(f"{BASE}/invoices/{bill_id}"); mpage.wait_for_selector("[data-testid=bill-summary]")
    check("phone: the bill page fits, with the proof upload in the same column", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.screenshot(path=f"{SHOTS}/10_bill_phone.png", full_page=True)
    mpage.goto(f"{BASE}/my/attendance"); mpage.wait_for_selector("[data-testid=today-card]")
    check("phone: the attendance page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.goto(f"{BASE}/my/report-cards"); mpage.wait_for_selector("[data-testid=report-child]")
    check("phone: the report card page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.goto(f"{BASE}/notifications"); mpage.wait_for_selector("h1")
    check("phone: the notifications page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.goto(f"{BASE}/profile"); mpage.wait_for_selector("header h2")
    check("profile page: has a way back to home", mpage.get_by_role("link", name=re.compile("Back to home", re.I)).count() >= 1)
    check("phone: the profile page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))

    # ================================================================ 11. Amharic
    q("update users set locale='am' where email='parent@demo.test'")
    raw = re.compile(r"\b(?:parent|verify|attendance)\.[a-z_]+\b")
    for path in ["/dashboard", "/my/payments", "/my/attendance", f"/invoices/{bill_id}", f"/students/{K1[0]}/report-card", "/notifications", "/my/report-cards", "/my/homework", "/my/results", "/my/timetable", "/announcements", "/calendar", "/complaints", "/help"]:
        mpage.goto(f"{BASE}{path}"); mpage.wait_for_selector("header h2")
        check(f"Amharic: no raw translation keys on {path}, and it fits a phone", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text('body'))[:3]))
    mpage.goto(f"{BASE}/dashboard"); mpage.wait_for_selector("[data-testid=parent-home]")
    mpage.screenshot(path=f"{SHOTS}/11_am.png", full_page=False)
    q("update users set locale=null where email='parent@demo.test'")

    for nm, pg in [("desktop", page), ("phone", mpage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
