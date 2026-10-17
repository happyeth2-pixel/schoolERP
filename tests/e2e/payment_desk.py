"""Browser test: taking a payment at the desk, from Find Student to Done.

  * Find Student -> Open Student -> Fees -> pick the bill -> Record payment received
  * the bill is always in view: student, grade/section, invoice, invoice total, previously paid, this payment, remaining;
    a partial payment says what will still be owed, a full one says the bill is settled
  * the check step (Student / Payment / Remaining balance) and "Confirm Payment"; afterwards "Payment recorded
    successfully." with the amount, the remaining balance, the reference, Print Confirmation and Done
  * notes (required for "something else"), a reference used before (stopped, then confirmed as a different payment,
    audited), a settled bill takes nothing more
  * a parent's proof: shown large with [Payment Received] and [Reject Payment]; rejection needs a reason; a refund needs
    refund-payments; who may do what; the audit log; a phone layout

Uses the usability-session data and changes it (adds two bills for the parent's children, payments on them; all removed
at the end). Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/payment_desk.py
"""
import os, re, sqlite3, struct, sys, time, zlib
from urllib.parse import unquote
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
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


def png(w=300, h=400):
    rows = b"".join(b"\x00" + b"".join(bytes(((x * 255) // w, (y * 255) // h, 170)) for x in range(w)) for y in range(h))

    def chunk(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b"")


def balance(invoice_id):
    net = q("select coalesce(sum(amount),0) from invoice_items where invoice_id=?", invoice_id)[0][0] - (q("select coalesce(discount_amount,0) from invoices where id=?", invoice_id)[0][0] or 0)
    paid = q("select coalesce(sum(amount),0) from payments where invoice_id=? and status='verified'", invoice_id)[0][0]
    back = q("select coalesce(sum(r.amount),0) from payment_refunds r join payments p on p.id=r.payment_id where p.invoice_id=? and p.status='verified'", invoice_id)[0][0]
    return round(net - (paid - back), 2)


nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
wait_text = lambda pg, text, timeout=15000: pg.wait_for_selector(f"text={text}", timeout=timeout)
money = lambda n: f"{n:,.2f}"

kids = q("select distinct s.id, s.first_name from students s join parent_student ps on ps.student_id=s.id join users u on u.id=ps.parent_id where u.email='parent@demo.test' order by s.id")
(STU, NAME), (STU2, NAME2) = kids[0], kids[1]
_cols = [r[1] for r in q("pragma table_info(invoices)")]
_tpl = q("select * from invoices order by id limit 1")[0]
_icols = [r[1] for r in q("pragma table_info(invoice_items)")]
_itpl = q("select * from invoice_items order by id limit 1")[0]


def fresh_invoice(student_id, tag, amount):
    v = dict(zip(_cols, _tpl)); v.pop("id")
    v.update(student_id=student_id, invoice_number=f"DESK-{tag}", is_waived=0, discount_amount=0, discount_reason=None, discount_by=None, waiver_reason=None, waived_by=None, waived_at=None, period_label=f"Desk {tag}", period_start=f"2032-01-0{ord(tag) - 64}")
    if "total_amount" in v: v["total_amount"] = amount
    q(f"insert into invoices ({','.join(v)}) values ({','.join('?' * len(v))})", *v.values())
    inv = q("select id from invoices where invoice_number=?", f"DESK-{tag}")[0][0]
    it = dict(zip(_icols, _itpl)); it.pop("id")
    it.update(invoice_id=inv, amount=amount, description="Desk tuition")
    for k, val in (("quantity", 1), ("unit_amount", amount)):
        if k in it: it[k] = val
    q(f"insert into invoice_items ({','.join(it)}) values ({','.join('?' * len(it))})", *it.values())
    return inv


def wipe():
    ids = "select id from invoices where invoice_number like 'DESK-%'"
    q(f"delete from payment_refunds where payment_id in (select id from payments where invoice_id in ({ids}))")
    q(f"delete from payments where invoice_id in ({ids})")
    q(f"delete from invoice_items where invoice_id in ({ids})")
    q("delete from invoices where invoice_number like 'DESK-%'")


wipe()
INV_A, INV_B, INV_C = fresh_invoice(STU, "A", 1000), fresh_invoice(STU, "B", 500), fresh_invoice(STU2, "C", 800)
CLASS = q("select g.name || ' ' || s.name from enrollments e join sections s on s.id=e.section_id join grades g on g.id=s.grade_id where e.student_id=? and e.status='active'", STU)[0][0]
ACC = q("select id, name from users where email='accountant@demo.test'")[0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    actx, ap = login(browser, "accountant@demo.test")

    # ================================================================ 1. Find Student -> Open Student -> Fees -> the bill
    ap.goto(f"{BASE}/dashboard")
    ap.goto(f"{BASE}/students/{STU}/help"); ap.wait_for_selector("[data-testid=desk-fees]")
    bill = ap.locator("[data-testid=bill]").filter(has=ap.locator(f"a[href$='/invoices/{INV_A}']")).first
    check("Fees: the bill is in the list with what is billed, paid and left, and a large 'Record payment received' button", bill.locator("[data-testid=record-payment]").evaluate(H) >= 56 and "1,000.00" in bill.inner_text())
    bill.locator("[data-testid=record-payment]").click(); wait_text(ap, "Step 1 of 3")
    st = ap.locator("[data-testid=bill-summary]")
    check("payment screen: shows the student, grade/section, the invoice and (before typing) total, previously paid and remaining", NAME in st.locator("[data-testid=bill-student]").inner_text() and CLASS in st.locator("[data-testid=bill-class]").inner_text() and "DESK-A" in st.locator("[data-testid=bill-invoice]").inner_text() and st.locator("[data-testid=bd-total]").inner_text() == "1,000.00 ETB" and st.locator("[data-testid=bd-paid]").inner_text() == "0.00 ETB" and st.locator("[data-testid=bd-left]").inner_text() == "1,000.00 ETB" and st.locator("[data-testid=bd-this]").inner_text() == "—", st.inner_text().replace("\n", " | ")[:260])
    ap.get_by_role("radio", name=re.compile("^Cash")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.fill("#rec_amount", "400")
    check("partial payment: the four lines are exact - total 1,000.00, previously paid 0.00, this payment 400.00, remaining after 600.00", [st.locator(f"[data-testid={k}]").inner_text() for k in ("bd-total", "bd-paid", "bd-this", "bd-left")] == ["1,000.00 ETB", "0.00 ETB", "400.00 ETB", "600.00 ETB"] and "Remaining after this payment" in st.inner_text())
    check("partial payment: it says plainly that 600.00 ETB will still be owed", "partial payment" in st.locator("[data-testid=bd-note]").inner_text() and "600.00" in st.locator("[data-testid=bd-note]").inner_text())
    ap.fill("#rec_amount", "1200")
    check("more than is left: the bill shows no 'this payment' and the field explains", st.locator("[data-testid=bd-this]").inner_text() == "—" and "more than what is left" in body(ap) and nxt(ap).is_disabled())
    ap.fill("#rec_amount", "400"); ap.locator("[data-testid=payer-suggestions] button").first.click()
    payer = ap.input_value("#rec_payer")
    ap.fill("#rec_note", "Brought in an envelope")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    check("check step: 'Student: X', 'Payment: 400.00 ETB', 'Remaining balance: 600.00 ETB'", NAME in ap.locator("[data-testid=confirm-student]").inner_text() and "400.00 ETB" in ap.locator("[data-testid=confirm-payment]").inner_text() and "600.00 ETB" in ap.locator("[data-testid=confirm-left]").inner_text())
    check("check step: the bill lines are still in view and the button says 'Confirm Payment'", st.is_visible() and ap.get_by_role("button", name="Confirm Payment").evaluate(H) >= 56)
    check("nothing is saved before Confirm Payment", q("select count(*) from payments where invoice_id=?", INV_A)[0][0] == 0)
    ap.get_by_role("button", name="Confirm Payment").click(); wait_text(ap, "Payment recorded successfully")
    check("after saving: 'Payment recorded successfully.', the amount, the remaining balance, the reference (none for cash) and the receipt number", ap.locator("[data-testid=recorded-amount]").inner_text() == "400.00 ETB" and ap.locator("[data-testid=recorded-left]").inner_text() == "600.00 ETB" and "None (cash)" in ap.locator("[data-testid=recorded-reference]").inner_text() and ap.locator("[data-testid=receipt-number]").inner_text().startswith("RCT-"))
    check("after saving: 'Print Confirmation' and 'Done' are large actions, and the note was kept", ap.locator("[data-testid=print-receipt]").inner_text() == "Print Confirmation" and ap.locator("[data-testid=print-receipt]").evaluate(H) >= 56 and ap.locator("[data-testid=recorded-done]").inner_text() == "Done" and "Brought in an envelope" in ap.locator("[data-testid=recorded-details]").inner_text())
    pdf = ap.request.get(ap.locator("[data-testid=print-receipt]").get_attribute("href"))
    check("Print Confirmation opens the receipt PDF", pdf.status == 200 and pdf.body()[:4] == b"%PDF")
    row = q("select status, channel, note, verified_by from payments where invoice_id=?", INV_A)[0]
    check("saved: verified, taken at the desk, note kept, by this accountant", row == ("verified", "counter", "Brought in an envelope", ACC[0]) and balance(INV_A) == 600)
    ap.locator("[data-testid=recorded-done]").click(); ap.wait_for_selector("[data-testid=desk-fees]")
    check("Done: back on the student's page, which now shows 600.00 left on that bill", f"/students/{STU}/help" in ap.url and "600.00" in ap.locator("[data-testid=bill]").filter(has=ap.locator(f"a[href$='/invoices/{INV_A}']")).first.inner_text())

    # ================================================================ 2. the rest, by bank slip: previously paid, full payment
    ap.goto(f"{BASE}/invoices/{INV_A}/record-payment"); wait_text(ap, "Step 1 of 3")
    check("second payment: 'Previously paid' is now 400.00 and the remaining 600.00", ap.locator("[data-testid=bd-paid]").inner_text() == "400.00 ETB" and ap.locator("[data-testid=bd-left]").inner_text() == "600.00 ETB")
    ap.get_by_role("radio", name=re.compile("^Bank transfer")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.locator("button", has_text="They pay everything that is left").click()
    ap.fill("#rec_payer", "Ato Tesfaye"); ap.fill("#rec_reference", "FT26-DESK-1")
    check("full payment: the bill says it settles the bill and the remaining is 0.00", "settles the bill in full" in ap.locator("[data-testid=bd-note]").inner_text() and ap.locator("[data-testid=bd-left]").inner_text() == "0.00 ETB")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    check("full payment: the check step says Remaining balance 0.00 ETB and shows the reference", "0.00 ETB" in ap.locator("[data-testid=confirm-left]").inner_text() and "FT26-DESK-1" in body(ap))
    ap.get_by_role("button", name="Confirm Payment").click(); wait_text(ap, "Payment recorded successfully")
    check("full payment: recorded, remaining 0.00 ETB, the reference/transaction number is shown", ap.locator("[data-testid=recorded-left]").inner_text() == "0.00 ETB" and ap.locator("[data-testid=recorded-reference]").inner_text() == "FT26-DESK-1" and balance(INV_A) == 0)
    ap.goto(f"{BASE}/invoices/{INV_A}/record-payment")
    check("a settled bill has no record form (nothing more can be taken)", "/record-payment" not in ap.url, ap.url)

    # ================================================================ 3. a reference used before; notes for 'something else'
    ap.goto(f"{BASE}/invoices/{INV_B}/record-payment"); wait_text(ap, "Step 1 of 3")
    ap.get_by_role("radio", name=re.compile("^Bank transfer")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.fill("#rec_amount", "200"); ap.fill("#rec_payer", "Ato Tesfaye"); ap.fill("#rec_reference", "ft26-desk-1")
    nxt(ap).click(); wait_text(ap, "Step 3 of 3")
    ap.get_by_role("button", name="Confirm Payment").click()
    ap.wait_for_selector("[data-testid=duplicate-warning]")
    dup = ap.locator("[data-testid=duplicate-warning]").inner_text()
    check("a reference used before: stopped, with which bill and how much, and nothing recorded", "DESK-A" in dup and "already used" in dup and q("select count(*) from payments where invoice_id=?", INV_B)[0][0] == 0, dup.replace("\n", " | ")[:220])
    check("...and Confirm Payment stays off until it is confirmed as a different payment", ap.get_by_role("button", name="Confirm Payment").is_disabled())
    ap.locator("label[for=confirm_duplicate]").click()
    ap.get_by_role("button", name="Confirm Payment").click(); wait_text(ap, "Payment recorded successfully")
    dlog = q("select after from audit_logs where action='payment.recorded' order by id desc limit 1")[0][0]
    check("confirmed as different: recorded, and the audit entry says the duplicate was confirmed", '"duplicate_reference_confirmed":true' in dlog.replace(" ", "") and balance(INV_B) == 300, dlog[:160])
    ap.goto(f"{BASE}/invoices/{INV_B}/record-payment"); wait_text(ap, "Step 1 of 3")
    ap.get_by_role("radio", name=re.compile("^Other")).click(); nxt(ap).click(); wait_text(ap, "Step 2 of 3")
    ap.fill("#rec_amount", "100"); ap.fill("#rec_payer", "Ato Tesfaye"); ap.fill("#rec_reference", "CHQ-501")
    check("'Something else': the note is required (Next is off and says why), not marked optional", nxt(ap).is_disabled() and "note" in body(ap).lower() and "(Optional)" not in ap.locator("label[for=rec_note]").inner_text())
    ap.fill("#rec_note", "Cheque, to be cleared Monday")
    check("...and with a note Next is on", nxt(ap).is_enabled())
    ap.goto(f"{BASE}/dashboard")

    # ================================================================ 4. a parent's proof: verify, reject (reason required)
    pctx, pp = login(browser, "parent@demo.test")
    pp.goto(f"{BASE}/dashboard")
    xsrf = unquote(next(c["value"] for c in pctx.cookies() if c["name"] == "XSRF-TOKEN"))

    def submit(invoice_id, amount, ref):
        return pctx.request.post(f"{BASE}/invoices/{invoice_id}/payments", multipart={"amount": str(amount), "method": "bank_transfer", "reference_number": ref, "proof": {"name": "proof.png", "mimeType": "image/png", "buffer": png()}}, headers={"X-XSRF-TOKEN": xsrf, "Accept": "application/json"}).status

    check("the parent submits two proofs online (no staff typing)", submit(INV_C, 300, "FT-P-1") in (200, 302) and submit(INV_C, 200, "FT-P-2") in (200, 302))
    pctx.close()
    P1, P2 = [r[0] for r in q("select id from payments where invoice_id=? order by id", INV_C)]
    ap.goto(f"{BASE}/payments/{P1}"); ap.wait_for_selector("[data-testid=summary]")
    check("verification: the proof is shown large beside the details, with 'Payment Received' and 'Reject Payment' as large buttons", ap.locator("img").count() >= 1 and ap.get_by_role("button", name=re.compile("^Payment Received")).evaluate(H) >= 56 and ap.get_by_role("button", name=re.compile("^Reject Payment")).evaluate(H) >= 56)
    ap.get_by_role("button", name=re.compile("^Payment Received")).click()
    dlg = ap.get_by_role("dialog"); dlg.wait_for()
    check("verification: the consequences are explained first (recorded, what is left, receipt)", "300" in dlg.inner_text() and "receipt" in dlg.inner_text().lower())
    dlg.get_by_role("button", name=re.compile("^Yes")).click()
    check("verification: accepted, the balance falls by 300", until(lambda: q("select status from payments where id=?", P1)[0][0] == "verified") and balance(INV_C) == 500)
    ap.goto(f"{BASE}/payments/{P2}"); ap.wait_for_selector("[data-testid=summary]")
    ap.get_by_role("button", name=re.compile("^Reject Payment")).click(); ap.wait_for_selector("[data-testid=reject-panel]")
    rb = ap.locator("[data-testid=reject-panel]").get_by_role("button", name=re.compile("^Reject"))
    check("rejection: a reason is required (the button is off and says so)", rb.first.is_disabled() and "reason" in ap.locator("[data-testid=reject-panel]").inner_text().lower())
    ap.fill("#rejection_reason", "The slip shows a different amount")
    ap.locator("[data-testid=reject-panel]").get_by_role("button", name=re.compile("^Reject Payment")).click()
    check("rejection: saved with the reason, and the balance did not move", until(lambda: q("select status, rejection_reason from payments where id=?", P2)[0] == ("rejected", "The slip shows a different amount")) and balance(INV_C) == 500)
    check("rejection is audited with the reason as the note", q("select note from audit_logs where action='payment.rejected' and auditable_id=?", P2)[0][0] == "The slip shows a different amount")

    # ================================================================ 5. refund, authorization
    ap.goto(f"{BASE}/payments/{P1}"); ap.wait_for_selector("[data-testid=summary]")
    check("refund: an accountant without refund-payments is not offered it", ap.locator("[data-testid=refund-panel]").count() == 0)
    octx, op = login(browser, "owner@demo.test")
    op.goto(f"{BASE}/payments/{P1}"); op.wait_for_selector("[data-testid=summary]")
    op.get_by_text("Refund or reverse this payment").first.click()
    op.wait_for_selector("[data-testid=refund-panel]", state="visible")
    op.fill("#refund_amount", "100"); op.fill("#refund_reason", "Overpaid; parent brought the wrong slip")
    op.locator("[data-testid=refund-panel]").get_by_role("button", name="Record refund").click()
    dlg = op.get_by_role("dialog"); dlg.wait_for(); dlg.get_by_role("button", name="Yes, record the refund").click()
    check("refund: the owner refunds 100.00; it is a separate record, the payment itself is unchanged, and it is audited", until(lambda: q("select count(*) from payment_refunds where payment_id=?", P1)[0][0] == 1) and q("select amount from payments where id=?", P1)[0][0] == 300 and balance(INV_C) == 600 and q("select count(*) from audit_logs where action='payment_refund.created'")[0][0] >= 1)
    octx.close()
    rctx, rp = login(browser, "registrar@demo.test")
    check("a receptionist without verify-payments cannot record or open a payment", status_of(rctx, f"/invoices/{INV_C}/record-payment") == 403 and status_of(rctx, f"/payments/{P1}") == 403)
    rctx.close()

    # ================================================================ 6. the audit log
    octx, op = login(browser, "owner@demo.test")
    op.goto(f"{BASE}/audit-log?action=payment.recorded"); op.wait_for_selector("[data-testid=entry]")
    check("audit log: recorded payments, each with who, how much and the receipt", op.locator("[data-testid=entry]").count() >= 3 and "Money received at the school was recorded" in op.locator("[data-testid=entry]").first.inner_text())
    octx.close()

    # ================================================================ 7. phone
    mctx, mp = login(browser, "accountant@demo.test", mobile=True)
    mp.goto(f"{BASE}/invoices/{INV_B}/record-payment"); wait_text(mp, "Step 1 of 3")
    check("phone: the payment screen with the bill lines fits and the choices are large", overflow(mp) <= 0 and mp.locator("[data-testid=breakdown]").is_visible() and min(mp.locator("[role=radio]").evaluate_all("els => els.map(e => e.offsetHeight)")) >= 56)
    mp.get_by_role("radio", name=re.compile("^Cash")).click(); nxt(mp).click(); wait_text(mp, "Step 2 of 3")
    mp.fill("#rec_amount", "50")
    check("phone: the live 'this payment / remaining' lines fit while typing", overflow(mp) <= 0 and mp.locator("[data-testid=bd-left]").inner_text() == "250.00 ETB")
    mctx.close()
    check("no JavaScript errors", not ap.js_errors, str(ap.js_errors[:2]))
    browser.close()

wipe()
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
