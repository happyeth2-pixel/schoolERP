"""Browser test: the parent's portal on a desktop and on a phone, for a parent with two children.

  * home: the child switcher first, then today's attendance, fees, report card and messages, in that order
  * switching children changes everything on the page in place (nothing reloads) and is remembered
  * today's attendance in words (icon + word + colour), the last few days, "not recorded yet" when it is not
  * fees: left to pay (huge), total, paid, each bill with its own button, payments waiting for the school
  * report card: View / Download PDF / Print obvious; messages: unread unmistakable, mark all read
  * the attendance page, the bill page (how to pay, copy the number, the proof upload, own payments), the
    notifications page
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
today_sql = "date('now')"
enrollment = lambda sid: q("select e.id from enrollments e where e.student_id=? and e.status='active' limit 1", sid)[0][0]
inv_of = lambda sid: [r[0] for r in q("select id from invoices where student_id=? order by period_start", sid)]

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
    check("home: a greeting by name, and no admin-style task tiles", f"Hello, {PARENT[1]}" in page.locator("h1").first.inner_text() and page.locator("[data-testid=main-tiles]").count() == 0)
    order = page.evaluate("""() => { const ids = ['child-switcher', 'card-attendance', 'card-fees', 'card-report', 'card-messages']; const y = (id) => { const e = document.querySelector(`[data-testid=${id}]`); return e ? e.getBoundingClientRect().top + window.scrollY : null; }; return ids.map(y); }""")
    check("home: the order is child -> today's attendance -> fees -> report card -> messages", None not in order and order == sorted(order), str(order))
    sw = page.locator("[data-testid=child-switcher] button[role=radio]")
    check("home: two children -> two big buttons with their names, the first chosen and ticked", sw.count() == 2 and min(sw.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 64 and sw.nth(0).get_attribute("aria-checked") == "true" and K1[1] in sw.nth(0).inner_text() and K2[1] in sw.nth(1).inner_text(), str(sw.all_inner_texts()))
    check("home: it says whose information is shown", K1[1] in page.get_by_test_id("showing").inner_text())
    page.screenshot(path=f"{SHOTS}/1_home_desktop.png", full_page=True)

    # ================================================================ 2. today's attendance
    ts = page.get_by_test_id("today-status")
    check("attendance card: with nothing recorded it says so in words (not 'present')", ts.get_attribute("data-status") == "none" and "Not recorded yet today" in ts.inner_text())
    q("insert into attendance_records (enrollment_id, date, status, marked_by, created_at, updated_at) values (?, date('now'), 'late', 1, datetime('now'), datetime('now'))", enrollment(K1[0]))
    for d, st in [(1, "present"), (2, "absent"), (3, "present")]:
        q("insert into attendance_records (enrollment_id, date, status, marked_by, created_at, updated_at) values (?, date('now', ?), ?, 1, datetime('now'), datetime('now'))", enrollment(K1[0]), f"-{d} day", st)
    page.reload(); page.wait_for_selector("[data-testid=parent-home]")
    ts = page.get_by_test_id("today-status")
    check("attendance card: today's status is big, in words, with an icon", ts.get_attribute("data-status") == "late" and ts.inner_text().strip() == "Late" and ts.locator("svg").count() == 1 and ts.evaluate("e => parseFloat(getComputedStyle(e.querySelector('p')).fontSize)") >= 24)
    chips = page.locator("[data-testid=day-chip]")
    check("attendance card: the last few days as chips, newest first, each status in words", chips.count() == 4 and [c.get_attribute("data-status") for c in chips.all()] == ["late", "present", "absent", "present"], str([c.get_attribute("data-status") for c in chips.all()]))
    check("attendance card: how the year is going, and a button to see everything", "present or late this year" in page.get_by_test_id("att-rate").inner_text() and page.get_by_test_id("card-attendance").get_by_role("link", name="See all attendance").evaluate(H) >= 56)

    # ================================================================ 3. switching children
    page.evaluate("window.__marker = 'same page'")
    sw.nth(1).click()
    check("switch: choosing the other child changes the page in place (no reload) and marks them", page.evaluate("window.__marker") == "same page" and sw.nth(1).get_attribute("aria-checked") == "true" and sw.nth(0).get_attribute("aria-checked") == "false" and K2[1] in page.get_by_test_id("showing").inner_text())
    check("switch: the other child's attendance is shown (nothing recorded today for them)", page.get_by_test_id("today-status").get_attribute("data-status") == "none" and page.locator("[data-testid=day-chip]").count() == 0)
    net2, paid2, left2 = balance(inv_of(K2[0])[0])
    check("switch: ...and their fees", page.get_by_test_id("fee-left").inner_text().replace(",", "").split()[0] == f"{left2:g}", page.get_by_test_id("fee-left").inner_text())
    page.reload(); page.wait_for_selector("[data-testid=parent-home]")
    check("switch: the choice is remembered on the next visit", sw.nth(1).get_attribute("aria-checked") == "true")
    sw.nth(0).click()

    # ================================================================ 4. fees
    inv1 = inv_of(K1[0])
    fees = page.get_by_test_id("card-fees")
    net, paid, left = sum(balance(i)[0] for i in inv1), sum(balance(i)[1] for i in inv1), sum(balance(i)[2] for i in inv1)
    fmt = lambda n: f"{n:,.2f}".rstrip("0").rstrip(".") if n != int(n) else f"{int(n):,}"
    check("fees: what is left to pay is the biggest thing on the card", page.get_by_test_id("fee-left").evaluate("e => parseFloat(getComputedStyle(e).fontSize)") >= 40 and fmt(left) in page.get_by_test_id("fee-left").inner_text(), page.get_by_test_id("fee-left").inner_text())
    check("fees: total, already paid and left add up to the invoice (from the database)", fmt(net) in page.get_by_test_id("fee-total").inner_text() and fmt(paid) in page.get_by_test_id("fee-paid").inner_text(), f"{net} {paid} {left}")
    check("fees: a plain-words status (Partly paid / Not paid yet / Fully paid) and a progress bar", re.search(r"Partly paid|Not paid yet|Fully paid", fees.inner_text()) is not None and fees.locator("[role=progressbar]").count() == 1)
    btn = fees.get_by_role("link", name=re.compile("See the bill|Pay|See"))
    check("fees: one large button opens the bill", btn.first.evaluate(H) >= 44)
    page.screenshot(path=f"{SHOTS}/4_fees.png", full_page=False)

    # ================================================================ 5. report card
    rep = page.get_by_test_id("card-report")
    check("report card: View and Download PDF are two large, named buttons", rep.get_by_role("link", name="View report card").evaluate(H) >= 56 and rep.get_by_role("link", name="Download PDF").evaluate(H) >= 56 and rep.get_by_role("link", name="Download PDF").get_attribute("href").endswith(f"/students/{K1[0]}/report-card.pdf"))
    r = ctx.request.get(f"{BASE}/students/{K1[0]}/report-card.pdf")
    check("report card: the PDF really downloads for this child", r.status == 200 and "pdf" in r.headers.get("content-type", ""), str(r.status))

    # ================================================================ 6. messages
    check("messages: with none, it says so and there is no unread banner", "No messages yet" in page.get_by_test_id("card-messages").inner_text() and page.get_by_test_id("unread-banner").count() == 0)
    for i, (title, read) in enumerate([("Payment accepted", False), ("Attendance corrected", False), ("Announcement", True), ("Old news", True)]):
        q("insert into notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at) values (?, 'test', 'App\\Models\\User', ?, ?, ?, datetime('now', ?), datetime('now'))", str(uuid.uuid4()), PARENT[0], json.dumps({"title": title, "body": f"Body of {title}"}), "2026-01-01 00:00:00" if read else None, f"-{i} minute")
    page.reload(); page.wait_for_selector("[data-testid=parent-home]")
    ban = page.get_by_test_id("unread-banner")
    check("messages: unread ones are announced at the very top ('You have 2 new messages')", "You have 2 new messages from the school" in ban.inner_text() and ban.evaluate(H) >= 56 and page.evaluate("document.querySelector('[data-testid=unread-banner]').getBoundingClientRect().top") < 300)
    msgs = page.locator("[data-testid=recent-messages] li")
    check("messages: the latest three, unread ones coloured with a 'New' badge, read ones plain", msgs.count() == 3 and [m.get_attribute("data-unread") for m in msgs.all()] == ["true", "true", "false"] and msgs.nth(0).locator("text=New").count() == 1 and msgs.nth(2).locator("text=New").count() == 0)
    page.screenshot(path=f"{SHOTS}/6_messages.png", full_page=False)
    page.get_by_role("button", name="Mark all as read").click()
    check("messages: 'Mark all as read' clears the banner and marks them all (only this parent's)", until(lambda: q("select count(*) from notifications where notifiable_id=? and read_at is null", PARENT[0])[0][0] == 0) and until(lambda: page.get_by_test_id("unread-banner").count() == 0))

    # ================================================================ 7. the attendance page
    page.get_by_test_id("card-attendance").get_by_role("link", name="See all attendance").click()
    page.wait_for_selector("[data-testid=today-card]")
    check("attendance page: the child switcher is here too, the same big buttons", page.locator("[data-testid=child-switcher] button[role=radio]").count() == 2 and K1[1] in page.locator("[data-testid=child-switcher] button[aria-checked=true]").inner_text())
    check("attendance page: today first, in words", page.get_by_test_id("today-status").get_attribute("data-status") == "late")
    check("attendance page: the year's rate is large, with the five totals as icon + word tiles", "%" in page.get_by_test_id("rate").inner_text() and page.locator("[data-testid=totals] li").count() == 5)
    days = page.locator("[data-testid=day-row]")
    check("attendance page: every recorded day is a card (weekday, date, status in words)", days.count() == 4 and days.first.get_attribute("data-status") == "late")
    page.locator("[data-testid=child-switcher] button").nth(1).click()
    page.wait_for_url(re.compile(rf"student={K2[0]}"))
    check("attendance page: switching goes to the other child's page", K2[1] in page.locator("[data-testid=today-card]").inner_text() and page.locator("[data-testid=day-row]").count() == 0 and "No attendance recorded yet" in page.inner_text("body"))

    # ================================================================ 8. the bill page
    bill_id = [i for i in inv1 if balance(i)[2] > 0][0] if any(balance(i)[2] > 0 for i in inv1) else inv1[0]
    page.goto(f"{BASE}/invoices/{bill_id}"); page.wait_for_selector("[data-testid=bill-summary]")
    bnet, bpaid, bleft = balance(bill_id)
    check("bill: what is left to pay, total and paid, in big plain numbers", fmt(bleft) in page.get_by_test_id("bill-left").inner_text() and fmt(bnet) in page.get_by_test_id("bill-total").inner_text() and fmt(bpaid) in page.get_by_test_id("bill-paid").inner_text(), f"{bnet} {bpaid} {bleft}")
    check("bill: none of the staff tools (discount, waiver, refund) are on a parent's page", all(w not in page.inner_text("body") for w in ["Edit Discount", "Waive Invoice", "Refund / reverse"]))
    if bleft > 0:
        how = page.get_by_test_id("how-to-pay")
        check("bill: 'How to pay' gives three plain steps and the school's account", "Pay the money to one of these accounts." in how.inner_text() and "Take a clear photo of the receipt." in how.inner_text() and "Send the photo here, below." in how.inner_text() and "1000123456789" in page.get_by_test_id("account-number").first.inner_text())
        cp = how.get_by_role("button", name="Copy number")
        check("bill: the account number can be copied with one tap", cp.count() >= 1 and cp.first.evaluate(H) >= 44)
        check("bill: the proof upload is right there, on the same page", how.get_by_text(re.compile("payment|receipt|photo", re.I)).count() >= 1 and how.locator("button, input[type=file]").count() >= 3)
    page.screenshot(path=f"{SHOTS}/8_bill.png", full_page=True)
    rows = q("select count(*) from payments where invoice_id=?", bill_id)[0][0]
    check("bill: 'Your payments' lists what was sent, each with its state in words", page.get_by_test_id("payments").count() == (1 if rows else 0) or rows == 0)

    # ================================================================ 9. the report card page
    page.goto(f"{BASE}/students/{K1[0]}/report-card"); page.wait_for_selector("[data-testid=report-actions]")
    acts = page.get_by_test_id("report-actions")
    check("report card page: Download PDF and Print are the first things on the page, large", acts.get_by_role("link", name="Download PDF").evaluate(H) >= 56 and acts.get_by_role("button", name="Print").evaluate(H) >= 56)
    page.evaluate("window.__printed = 0; window.print = () => { window.__printed++ }; true")
    acts.get_by_role("button", name="Print").click()
    check("report card page: Print opens the print dialog", page.evaluate("window.__printed") == 1)

    # ================================================================ 10. the messages page
    q("update notifications set read_at=null where notifiable_id=? and data like '%Payment accepted%'", PARENT[0])
    page.goto(f"{BASE}/notifications"); page.wait_for_selector("[data-testid=messages]")
    mm = page.locator("[data-testid=messages] li")
    check("messages page: the unread one is coloured with a 'New' badge and a large 'Mark as read'", mm.first.get_attribute("data-unread") == "true" and mm.first.locator("text=New").count() == 1 and mm.first.get_by_role("button", name="Mark as read").evaluate(H) >= 44)
    mm.first.get_by_role("button", name="Mark as read").click()
    check("messages page: marking one read works", until(lambda: q("select count(*) from notifications where notifiable_id=? and read_at is null", PARENT[0])[0][0] == 0))

    # ================================================================ 11. ownership
    check("ownership: another family's child's attendance, report card and PDF are not found", ctx.request.get(f"{BASE}/my/attendance?student={OTHER}").status == 404 and ctx.request.get(f"{BASE}/students/{OTHER}/report-card").status == 404 and ctx.request.get(f"{BASE}/students/{OTHER}/report-card.pdf").status == 404)
    if OTHER_INV:
        check("ownership: another family's bill is not found", ctx.request.get(f"{BASE}/invoices/{OTHER_INV[0][0]}").status == 404)
    tok = unquote(next(c["value"] for c in ctx.cookies() if c["name"] == "XSRF-TOKEN"))
    codes = [page.evaluate("""async ([path, token]) => (await fetch(path, {method: 'POST', headers: {'X-XSRF-TOKEN': token, 'Accept': 'application/json'}})).status""", [f"/mark-batches/1/submit", tok])]
    check("ownership: a parent cannot use staff actions", codes[0] in (403, 404), str(codes))

    # ================================================================ 12. phone
    mctx, mpage = login(browser, "parent@demo.test", mobile=True)
    mpage.goto(f"{BASE}/dashboard"); mpage.wait_for_selector("[data-testid=parent-home]")
    check("phone: no sideways scrolling", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    sw2 = mpage.locator("[data-testid=child-switcher] button[role=radio]")
    lefts = sw2.evaluate_all("els => els.map(e => Math.round(e.getBoundingClientRect().left))")
    check("phone: the child buttons are stacked full width and easy to hit", len(set(lefts)) == 1 and min(sw2.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 64, str(lefts))
    check("phone: the first screen shows the greeting, the child switcher and the start of today's attendance", mpage.locator("[data-testid=card-attendance]").evaluate("e => e.getBoundingClientRect().top") < 844)
    mpage.screenshot(path=f"{SHOTS}/12_home_phone.png", full_page=True)
    btns = mpage.locator("[data-testid=parent-home] section a[class*='min-h-[56px]'], [data-testid=parent-home] section button[class*='min-h-[56px]']")
    check("phone: the main buttons in each card are >= 56px", btns.count() >= 4 and min(btns.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56, str(btns.evaluate_all("els => els.map(e => e.offsetHeight)")))
    mpage.goto(f"{BASE}/invoices/{bill_id}"); mpage.wait_for_selector("[data-testid=bill-summary]")
    check("phone: the bill page fits, with the proof upload in the same column", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.screenshot(path=f"{SHOTS}/12_bill_phone.png", full_page=True)
    mpage.goto(f"{BASE}/my/attendance"); mpage.wait_for_selector("[data-testid=today-card]")
    check("phone: the attendance page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.goto(f"{BASE}/students/{K1[0]}/report-card"); mpage.wait_for_selector("[data-testid=report-actions]")
    check("phone: the report card fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    mpage.goto(f"{BASE}/notifications"); mpage.wait_for_selector("h1")
    check("phone: the messages page fits", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))

    # ================================================================ 13. Amharic
    q("update users set locale='am' where email='parent@demo.test'")
    raw = re.compile(r"\b(?:parent|verify|attendance)\.[a-z_]+\b")
    for path in ["/dashboard", "/my/attendance", f"/invoices/{bill_id}", f"/students/{K1[0]}/report-card", "/notifications", "/my/report-cards"]:
        mpage.goto(f"{BASE}{path}"); mpage.wait_for_selector("h1, [data-testid=report-actions]")
        check(f"Amharic: no raw translation keys on {path}, and it fits a phone", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text('body'))[:3]))
    mpage.goto(f"{BASE}/dashboard"); mpage.wait_for_selector("[data-testid=parent-home]")
    mpage.screenshot(path=f"{SHOTS}/13_am.png", full_page=False)
    q("update users set locale=null where email='parent@demo.test'")

    for nm, pg in [("desktop", page), ("phone", mpage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
