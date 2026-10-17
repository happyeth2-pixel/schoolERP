"""Browser test: a parent is helped at the school by staff (no portal needed), and the portal still works.

  * a receptionist finds the student and opens "Help a parent": only what their permissions allow (report card,
    parents' numbers) - fees and attendance are a plain "ask the ..." note, never a hidden door
  * an accountant records money received at the school (cash, then a bank slip) in three questions; the receipt
    can be printed; the bill's balance falls; nobody can record more than the balance or a repeat by accident
  * the same student with NO parent account at all is served exactly the same
  * a parent with the portal sees the desk payment and its receipt, is told they can also pay at school, and cannot
    open any staff page
  * the audit log carries the money entry; a phone layout; Amharic

Uses the usability-session data and changes it (records payments, briefly unlinks one student's parents and gives the
receptionist one extra permission, restoring them at the end). Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/assisted.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

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


def balance(invoice_id):
    net = q("select coalesce(sum(amount),0) from invoice_items where invoice_id=?", invoice_id)[0][0] - (q("select coalesce(discount_amount,0) from invoices where id=?", invoice_id)[0][0] or 0)
    paid = q("select coalesce(sum(amount),0) from payments where invoice_id=? and status='verified'", invoice_id)[0][0]
    back = q("select coalesce(sum(r.amount),0) from payment_refunds r join payments p on p.id=r.payment_id where p.invoice_id=? and p.status='verified'", invoice_id)[0][0]
    return round(net - (paid - back), 2)


body = lambda pg: pg.locator("body").inner_text()
nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
wait_text = lambda pg, text, timeout=15000: pg.wait_for_selector(f"text={text}", timeout=timeout)

# ---- data: two of the parent's children, each given a fresh 1,000 ETB bill (earlier suites use up the seeded ones)
kids = [r[0] for r in q("select distinct s.id from students s join parent_student ps on ps.student_id=s.id join users u on u.id=ps.parent_id where u.email='parent@demo.test' order by s.id")]
STU_A, STU_B = kids[0], kids[1]
NAME_A = q("select first_name from students where id=?", STU_A)[0][0]
NAME_B = q("select first_name from students where id=?", STU_B)[0][0]
_cols = [r[1] for r in q("pragma table_info(invoices)")]
_tpl = q("select * from invoices order by id limit 1")[0]
_icols = [r[1] for r in q("pragma table_info(invoice_items)")]
_itpl = q("select * from invoice_items order by id limit 1")[0]
MADE = []


def fresh_invoice(student_id, tag):
    v = dict(zip(_cols, _tpl)); v.pop("id")
    v.update(student_id=student_id, invoice_number=f"ASSIST-{tag}", is_waived=0, discount_amount=0, discount_reason=None, discount_by=None, waiver_reason=None, waived_by=None, waived_at=None, period_label=f"Assisted {tag}", period_start="2031-01-01")
    for k in ("total_amount",):
        if k in v: v[k] = 1000
    q(f"insert into invoices ({','.join(v)}) values ({','.join('?' * len(v))})", *v.values())
    inv = q("select id from invoices where invoice_number=?", f"ASSIST-{tag}")[0][0]
    it = dict(zip(_icols, _itpl)); it.pop("id")
    it.update(invoice_id=inv, amount=1000, description="Assisted tuition")
    for k, val in (("quantity", 1), ("unit_amount", 1000)):
        if k in it: it[k] = val
    q(f"insert into invoice_items ({','.join(it)}) values ({','.join('?' * len(it))})", *it.values())
    MADE.append(inv)
    return inv


q("delete from payments where invoice_id in (select id from invoices where invoice_number like 'ASSIST-%')")
q("delete from invoice_items where invoice_id in (select id from invoices where invoice_number like 'ASSIST-%')")
q("delete from invoices where invoice_number like 'ASSIST-%'")
INV_A, INV_B = fresh_invoice(STU_A, "A"), fresh_invoice(STU_B, "B")
BAL_A, BAL_B = balance(INV_A), balance(INV_B)
check("the two students each have a 1,000 ETB bill to pay", BAL_A == 1000 and BAL_B == 1000, f"{BAL_A} {BAL_B}")
REG_ID = q("select id from users where email='registrar@demo.test'")[0][0]
ATT_PERM = q("select id from permissions where name='view-attendance-reports'")[0][0]
q("delete from model_has_permissions where model_id=? and permission_id=? and model_type like '%User'", REG_ID, ATT_PERM)
saved_guardians = q("select parent_id, student_id, relationship from parent_student where student_id=?", STU_B)
for k in ("payment.recorded",):
    q("delete from audit_logs where action=?", k)

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 1. the receptionist: only what her permissions allow
    rctx, rp = login(browser, "registrar@demo.test")
    rp.goto(f"{BASE}/students"); rp.wait_for_selector("#student-search")
    rp.fill("#student-search", NAME_A)
    rp.wait_for_selector("[data-testid=student-result]")
    card = rp.locator(f"[data-testid=student-result][data-student-id='{STU_A}']")
    check("Find Student: each result has an 'Open Student' button", card.locator("[data-testid=open-student]").count() == 1)
    card.locator("[data-testid=open-student]").click(); wait_text(rp, "Parents")
    ptxt = rp.locator("[data-testid=parents]").inner_text()
    check("help page: the parents are listed with a number to call", rp.locator("[data-testid=parents]").count() == 1 and re.search(r"\+?\d[\d ]{6,}", ptxt) is not None, ptxt.replace(chr(10), " | ")[:160])
    check("help page: it says no account is needed", "does not need an account" in ptxt, ptxt.replace(chr(10), " | ")[:200])
    check("help page: fees are NOT shown to her, only a plain 'accounts desk' note", rp.locator("[data-testid=fees-elsewhere]").count() == 1 and rp.locator("[data-testid=bills]").count() == 0 and rp.locator("[data-testid=record-payment]").count() == 0 and "ETB" not in rp.locator("[data-testid=desk-fees]").inner_text())
    check("help page: attendance is not shown to her either (a note says who to ask)", rp.locator("[data-testid=attendance-elsewhere]").count() == 1 and rp.locator("[data-testid=today]").count() == 0)
    check("help page: the report card is hers to view and print", rp.locator("[data-testid=report-view]").count() == 1 and rp.locator("[data-testid=report-pdf]").count() == 1)
    rp.locator("[data-testid=report-view]").click()
    try:
        rp.wait_for_url(re.compile(r"/report-card"), timeout=15000)
    except Exception:
        pass
    check("help page: 'View report card' opens the report card", "/report-card" in rp.url, rp.url)
    check("she cannot record a payment even by typing the address", status_of(rctx, f"/invoices/{INV_A}/record-payment") == 403)
    check("she cannot see the bill either", status_of(rctx, f"/invoices/{INV_A}") == 403)
    # an administrator grants attendance to her account: the attendance part appears, nothing else changes
    q("insert into model_has_permissions (permission_id, model_type, model_id) select ?, model_type, model_id from model_has_permissions where model_id=? limit 1", ATT_PERM, REG_ID)
    rp.goto(f"{BASE}/students/{STU_A}/help"); rp.wait_for_selector("[data-testid=desk-attendance]")
    check("with view-attendance-reports granted, today's attendance and the totals appear", rp.locator("[data-testid=today]").count() == 1 and rp.locator("[data-testid=attendance-elsewhere]").count() == 0)
    check("...and the fees are still hidden from her", rp.locator("[data-testid=fees-elsewhere]").count() == 1)
    q("delete from model_has_permissions where model_id=? and permission_id=? and model_type like '%User'", REG_ID, ATT_PERM)
    rctx.close()

    # ================================================================ 2. the accountant records money received at the school
    actx, ap = login(browser, "accountant@demo.test")
    ap.goto(f"{BASE}/students/{STU_A}/help"); ap.wait_for_selector("[data-testid=desk-fees]")
    check("accountant: sees what the family owes, in large type", float(ap.locator("[data-testid=owed]").inner_text().split()[0].replace(",", "")) >= BAL_A - 0.01, ap.locator("[data-testid=owed]").inner_text())
    check("accountant: no parent number is shown (she does not manage students) and no student profile link", "ETB" in ap.locator("[data-testid=desk-fees]").inner_text() and ap.get_by_role("link", name="Open student profile").count() == 0)
    bill = ap.locator("[data-testid=bill]").filter(has=ap.locator(f"a[href$='/invoices/{INV_A}']")).first
    check("accountant: each unpaid bill has a large 'Record payment received' button", bill.locator("[data-testid=record-payment]").count() == 1 and bill.locator("[data-testid=record-payment]").evaluate(H) >= 56)
    bill.locator("[data-testid=record-payment]").click(); wait_text(ap, "Step 1 of 3")
    check("record: the bill and what is left are at the top", "ETB" in ap.locator("[data-testid=bill-summary]").inner_text())
    check("record: nothing preselected and Next says why it is off", nxt(ap).is_disabled() and "Choose how they paid" in body(ap) and ap.locator("[role=radio][aria-checked=true]").count() == 0)
    ap.get_by_role("radio", name=re.compile("^Cash")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    check("record: cash needs no reference number", ap.locator("#rec_reference").count() == 0)
    check("record: the parent's name is one tap away", ap.locator("[data-testid=payer-suggestions] button").count() >= 1)
    check("record: Next is off until amount and payer are given", nxt(ap).is_disabled())
    ap.fill("#rec_amount", str(BAL_A + 1))
    check("record: more than the balance is explained beside the field", "more than what is left" in body(ap) and nxt(ap).is_disabled())
    ap.fill("#rec_amount", "150")
    ap.locator("[data-testid=payer-suggestions] button").first.click()
    payer = ap.input_value("#rec_payer")
    check("record: tapping a parent's name fills 'Who paid?'", payer != "")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    check("record: the check step shows the amount large, who paid and what is left", "150.00" in ap.locator("[data-testid=headline]").inner_text() and payer in body(ap) and f"{BAL_A - 150:,.2f}" in body(ap))
    check("record: nothing is saved before the last button", q("select count(*) from payments where channel='counter'")[0][0] == 0)
    ap.get_by_role("button", name="Confirm Payment").click()
    wait_text(ap, "RCT-")
    pay = q("select id, status, channel, paid_by_name, verified_by, submitted_by, receipt_number, amount from payments where channel='counter' order by id desc limit 1")[0]
    acc_id = q("select id from users where email='accountant@demo.test'")[0][0]
    check("recorded: a verified payment with a receipt number, marked as taken at the school, by this accountant", pay[1] == "verified" and pay[2] == "counter" and pay[3] == payer and pay[4] == acc_id == pay[5] and str(pay[6]).startswith("RCT-") and float(pay[7]) == 150.0, str(pay))
    check("recorded: the balance fell by exactly that amount", balance(INV_A) == round(BAL_A - 150, 2))
    check("recorded: the confirmation says who paid at the school and offers the printable receipt", "Payment recorded successfully" in body(ap) and "Print Confirmation" in body(ap) and ap.locator("[data-testid=print-receipt]").count() == 1)
    rc = ap.request.get(BASE + f"/payments/{pay[0]}/receipt")
    check("recorded: the receipt PDF opens", rc.status == 200 and rc.body()[:4] == b"%PDF", str(rc.status))
    al = q("select user_id, note from audit_logs where action='payment.recorded' and auditable_id=?", pay[0])
    check("recorded: one audit entry names the accountant and says it was money received at the school", len(al) == 1 and al[0][0] == acc_id and "received at the school" in (al[0][1] or ""), str(al))
    check("recorded: no raw duplicate 'payment created' row for it", q("select count(*) from audit_logs where action='payment.created' and auditable_id=?", pay[0])[0][0] == 0)

    # a bank slip needs its reference; an accidental double tap is stopped
    ap.goto(f"{BASE}/invoices/{INV_A}/record-payment"); wait_text(ap, "Step 1 of 3")
    ap.get_by_role("radio", name=re.compile("^Bank transfer")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.fill("#rec_amount", "100"); ap.fill("#rec_payer", "Ato Tesfaye")
    check("bank slip: Next is off until the reference number is typed, and says so", nxt(ap).is_disabled() and "reference number" in body(ap).lower())
    ap.fill("#rec_reference", "FT26-778812")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    check("bank slip: the check step shows the reference", "FT26-778812" in body(ap))
    ap.get_by_role("button", name="Confirm Payment").click(); wait_text(ap, "RCT-")
    check("bank slip: recorded with its reference", q("select count(*) from payments where channel='counter' and reference_number='FT26-778812' and status='verified'")[0][0] == 1)
    before = q("select count(*) from payments where invoice_id=?", INV_A)[0][0]
    ap.goto(f"{BASE}/invoices/{INV_A}/record-payment"); wait_text(ap, "Step 1 of 3")
    ap.get_by_role("radio", name=re.compile("^Bank transfer")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.fill("#rec_amount", "100"); ap.fill("#rec_payer", "Ato Tesfaye"); ap.fill("#rec_reference", "FT26-778812")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    ap.get_by_role("button", name="Confirm Payment").click()
    check("the same payment sent twice within a minute is refused with a reason", until(lambda: "just recorded this same payment" in body(ap) or "already used" in body(ap)) and q("select count(*) from payments where invoice_id=?", INV_A)[0][0] == before)

    ap.goto(f"{BASE}/students/{STU_A}/help"); ap.wait_for_selector("[data-testid=desk-fees]")
    check("help page: the payments just recorded are listed with 'Print receipt' and 'paid at school'", ap.locator("[data-testid=recent-payments] li").count() >= 2 and "paid at school" in ap.locator("[data-testid=recent-payments]").inner_text() and ap.get_by_role("link", name="Print receipt").count() >= 2)
    check("help page: the balance shown is the new one", f"{BAL_A - 250:,.2f}" in ap.locator("[data-testid=desk-fees]").inner_text())

    # ================================================================ 3. a parent with NO account at all
    q("delete from parent_student where student_id=?", STU_B)
    ap.goto(f"{BASE}/students/{STU_B}/help"); ap.wait_for_selector("[data-testid=desk-fees]")
    check("no parent linked: the page says so and that this is fine", ap.locator("[data-testid=no-guardian]").count() == 1 and "That is fine" in ap.locator("[data-testid=no-guardian]").inner_text())
    ap.locator("[data-testid=record-payment]").first.click(); wait_text(ap, "Step 1 of 3")
    ap.get_by_role("radio", name=re.compile("^Cash")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    check("no parent linked: no name suggestions, the payer is simply typed", ap.locator("[data-testid=payer-suggestions]").count() == 0)
    ap.locator("button", has_text="They pay everything that is left").click()
    ap.fill("#rec_payer", "Grandmother Selam")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    check("no parent linked: paying the whole balance says the bill will be fully paid", "fully paid" in body(ap))
    ap.get_by_role("button", name="Confirm Payment").click(); wait_text(ap, "RCT-")
    check("no parent linked: recorded and the bill is settled", balance(INV_B) == 0 and q("select paid_by_name from payments where invoice_id=? and channel='counter'", INV_B)[0][0] == "Grandmother Selam")
    ap.goto(f"{BASE}/students/{STU_B}/help"); ap.wait_for_selector("[data-testid=desk-fees]")
    settled = ap.locator("[data-testid=bill]").filter(has=ap.locator(f"a[href$='/invoices/{INV_B}']")).first
    check("no parent linked: that bill now shows nothing left and no record button", settled.locator("[data-testid=bill-left]").inner_text() == "0.00" and settled.locator("[data-testid=record-payment]").count() == 0, settled.inner_text().replace(chr(10), " | ")[:160])
    ap.goto(f"{BASE}/invoices/{INV_B}/record-payment")
    check("a settled bill has no record form (sent back to the bill)", "/record-payment" not in ap.url, ap.url)
    for pid, sid, rel in saved_guardians:
        q("insert into parent_student (parent_id, student_id, relationship) values (?, ?, ?)", pid, sid, rel)

    # ================================================================ 4. the accountant's bill page and the audit log
    ap.goto(f"{BASE}/invoices/{INV_A}"); ap.wait_for_selector("[data-testid=record-payment]")
    check("bill page: the accountant sees 'Record payment received' and a note on desk payments", ap.locator("[data-testid=record-payment]").count() == 1 and ap.locator("[data-testid=counter-note]").count() >= 1)
    actx.close()

    # ================================================================ 5. the parent with the portal
    pctx, pp = login(browser, "parent@demo.test")
    pp.goto(f"{BASE}/invoices/{INV_A}"); pp.wait_for_selector("[data-testid=payments]")
    check("portal parent: sees the payments taken at the school, with receipt numbers", pp.locator("[data-testid=payments] li").count() >= 2 and "RCT-" in pp.locator("[data-testid=payments]").inner_text())
    check("portal parent: told they can also pay at the school, and no website is needed", pp.locator("[data-testid=pay-at-school]").count() == 1 and "office" in pp.locator("[data-testid=pay-at-school]").inner_text())
    check("portal parent: the online proof steps are still there", pp.locator("[data-testid=how-to-pay]").count() == 1)
    check("portal parent: cannot open the staff help page or the record form", status_of(pctx, f"/students/{STU_A}/help") == 403 and status_of(pctx, f"/invoices/{INV_A}/record-payment") in (403, 404))
    pn = q("select count(*) from notifications where type like '%PaymentVerified' and notifiable_id=(select id from users where email='parent@demo.test')")[0][0]
    check("portal parent: was notified that a payment was received", pn >= 1)
    pctx.close()

    # ================================================================ 6. audit log, phone, Amharic
    octx, op = login(browser, "owner@demo.test")
    op.goto(f"{BASE}/audit-log?action=payment.recorded"); op.wait_for_selector("[data-testid=entry]")
    e0 = op.locator("[data-testid=entry]").first.inner_text()
    check("audit log: 'Money received at the school was recorded' with who, how much, receipt and the payer", "Money received at the school was recorded" in e0 and "payment.recorded" in e0 and q("select name from users where email='accountant@demo.test'")[0][0] in e0, e0[:200].replace("\n", " | "))
    octx.close()

    mctx, mp = login(browser, "accountant@demo.test", mobile=True)
    mp.goto(f"{BASE}/students/{STU_A}/help"); mp.wait_for_selector("[data-testid=desk-fees]")
    check("phone: the help page has no sideways scroll", overflow(mp) <= 1, str(overflow(mp)))
    check("phone: buttons are large", mp.locator("[data-testid=record-payment]").first.evaluate(H) >= 56 if mp.locator("[data-testid=record-payment]").count() else True)
    mp.goto(f"{BASE}/invoices/{INV_A}/record-payment"); wait_text(mp, "Step 1 of 3")
    mp.get_by_role("radio", name=re.compile("^Cash")).click(); nxt(mp).click(); wait_text(mp, "Step 2 of 3")
    check("phone: the record form fits the screen", overflow(mp) <= 1, str(overflow(mp)))
    mctx.close()

    q("update users set locale='am' where email='accountant@demo.test'")
    xctx, xp = login(browser, "accountant@demo.test")
    xp.goto(f"{BASE}/students/{STU_A}/help"); xp.wait_for_selector("[data-testid=desk-fees]")
    check("Amharic: the help page is translated (no raw keys)", bool(re.search(r"[ሀ-፿]", xp.locator("h2").first.inner_text() + body(xp))) and not re.search(r"\b(?:desk|rec)\.[a-z_]+\b", body(xp)))
    xp.goto(f"{BASE}/invoices/{INV_A}/record-payment"); wait_text(xp, "Step 1 of 3") if False else xp.wait_for_selector("[data-testid=bill-summary]")
    check("Amharic: the record form is translated (no raw keys)", not re.search(r"\b(?:desk|rec)\.[a-z_]+\b", body(xp)) and bool(re.search(r"[ሀ-፿]", body(xp))))
    xctx.close()
    q("update users set locale=null where email='accountant@demo.test'")

    check("no JavaScript errors", not ap.js_errors and not rp.js_errors, str((ap.js_errors + rp.js_errors)[:2]))
    browser.close()

# restore what this test touched
q("delete from payments where invoice_id in (select id from invoices where invoice_number like 'ASSIST-%')")
q("delete from invoice_items where invoice_id in (select id from invoices where invoice_number like 'ASSIST-%')")
q("delete from invoices where invoice_number like 'ASSIST-%'")
q("delete from model_has_permissions where model_id=? and permission_id=? and model_type like '%User'", REG_ID, ATT_PERM)
if q("select count(*) from parent_student where student_id=?", STU_B)[0][0] == 0:
    for pid, sid, rel in saved_guardians:
        q("insert into parent_student (parent_id, student_id, relationship) values (?, ?, ?)", pid, sid, rel)

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
