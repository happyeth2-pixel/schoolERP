"""Browser test: the accountant's payment workflow, in a real browser, from a real parent upload.

  * a parent submits proofs through the real endpoint (a PNG, a PDF) - the same way the app receives them
  * the queue: oldest first, who / how much / how / which invoice on each card, "cannot be accepted" flagged
  * one payment at a time: who paid, how much, for what, how, and what to do next; the proof large (zoom, turn, open)
  * Reject Payment: a reason is required (quick reasons, server rules), the parent is told, the audit note is kept
  * Payment Received: the consequences first, then a strong confirmation with the receipt number, Print receipt, Verify next
  * partial payments, an over-balance payment, a repeat of an already-decided payment, possible duplicates
  * refund and reversal (only for someone holding refund-payments), with their rules
  * unauthorized users, phone layout, Amharic

Uses the usability-session data and changes it: it DELETES all payments and refunds in that database first.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/payments.py
"""
import os, re, sqlite3, struct, sys, tempfile, time, zlib
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="payments_e2e_")
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


def png(w=600, h=800):
    rows = b"".join(b"\x00" + b"".join(bytes(((x * 255) // w, (y * 255) // h, 170)) for x in range(w)) for y in range(h))

    def chunk(t, d):
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b"")


PDF = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n"


def balance(invoice_id):
    net = q("select coalesce(sum(amount),0) from invoice_items where invoice_id=?", invoice_id)[0][0] - (q("select coalesce(discount_amount,0) from invoices where id=?", invoice_id)[0][0] or 0)
    paid = q("select coalesce(sum(amount),0) from payments where invoice_id=? and status='verified'", invoice_id)[0][0]
    back = q("select coalesce(sum(r.amount),0) from payment_refunds r join payments p on p.id=r.payment_id where p.invoice_id=? and p.status='verified'", invoice_id)[0][0]
    return round(net - (paid - back), 2)


q("delete from payment_refunds"); q("delete from payments")
AUDIT0 = q("select count(*) from audit_logs where action='payment.verified'")[0][0]
NOTIF0 = {k: q("select count(*) from notifications where type like ?", "%" + k)[0][0] for k in ("PaymentVerified", "PaymentRejected")}   # the demo data already holds some
q("update users set locale=null where email in ('accountant@demo.test','owner@demo.test')")
mine = q("select distinct i.id, s.first_name from invoices i join students s on s.id=i.student_id join parent_student ps on ps.student_id=s.id join users u on u.id=ps.parent_id where u.email='parent@demo.test' order by i.id")
INV_A, INV_B = mine[0][0], mine[1][0]
NAME_A, NAME_B = mine[0][1], mine[1][1]
BAL_A, BAL_B = balance(INV_A), balance(INV_B)
PARENT = q("select id, name from users where email='parent@demo.test'")[0]
check("the parent has two unpaid invoices to pay against", BAL_A > 150 and BAL_B > 150, f"{INV_A}:{BAL_A} {INV_B}:{BAL_B}")

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 0. the parent submits real proofs
    pctx, ppage = login(browser, "parent@demo.test")
    ppage.goto(f"{BASE}/dashboard")
    xsrf = unquote(next(c["value"] for c in pctx.cookies() if c["name"] == "XSRF-TOKEN"))

    def submit(invoice_id, amount, method, ref, kind="png"):
        files = {"proof": {"name": f"proof.{kind}", "mimeType": "image/png" if kind == "png" else "application/pdf", "buffer": png() if kind == "png" else PDF}}
        r = pctx.request.post(f"{BASE}/invoices/{invoice_id}/payments", multipart={"amount": str(amount), "method": method, "reference_number": ref, **files}, headers={"X-XSRF-TOKEN": xsrf, "Accept": "application/json"})
        return r.status

    st = [
        submit(INV_B, 100, "bank_transfer", "FT-1001"),               # P1: a partial payment, PNG proof
        submit(INV_A, BAL_A, "mobile_money", "MM-2002", "pdf"),        # P2: pays invoice A in full, PDF proof
        submit(INV_B, 100, "bank_transfer", "FT-1001"),               # P3: the same transfer sent twice
        submit(INV_A, BAL_A, "mobile_money", "MM-3003"),               # P4: also "full" -- becomes too much once P2 is accepted
        submit(INV_B, 50, "cash", "CASH-4004"),                       # P5: for the phone and Amharic checks
        submit(INV_B, 20, "cash", "CASH-5005"),                       # P6: for the two-tabs "already decided" check
    ]
    ids = [r[0] for r in q("select id from payments order by id")]
    check("the real upload endpoint accepted six proofs (PNG and PDF)", len(ids) == 6 and all(s in (200, 302) for s in st), str(st))
    P1, P2, P3, P4, P5, P6 = ids

    # ================================================================ 1. the queue
    ctx, page = login(browser, "accountant@demo.test")
    page.goto(f"{BASE}/dashboard"); page.wait_for_selector("[data-testid=home-tasks]")
    page.get_by_role("link", name=re.compile("Check payments", re.I)).first.click()
    page.wait_for_selector("[data-testid=payment-card]")
    cards = page.locator("[data-testid=payment-card]")
    check("queue: every waiting payment is a card, oldest first", cards.count() == 6 and [int(c.get_attribute("data-payment-id")) for c in cards.all()] == ids)
    c1 = cards.first.inner_text().replace("\n", " ")
    check("queue: each card says who, how much, how (icon + word), the reference and the invoice", NAME_B in c1 and "100" in c1 and "Bank transfer" in c1 and "FT-1001" in c1 and "Invoice" in c1 and PARENT[1] in c1, c1[:200])
    check("queue: one clearly-named large button per card; the oldest is the primary one", cards.first.get_by_role("link", name="Check this payment").evaluate(H) >= 56 and page.get_by_test_id("start-oldest").evaluate(H) >= 56)
    page.screenshot(path=f"{SHOTS}/1_queue.png", full_page=True)
    page.get_by_role("radio", name="Cash", exact=True).click()
    check("queue: filtering by 'Cash' leaves just the cash payments", until(lambda: page.locator("[data-testid=payment-card]").count() == 2))
    page.get_by_role("radio", name="All", exact=True).click()
    check("queue: 'All' brings the rest back", until(lambda: page.locator("[data-testid=payment-card]").count() == 6))
    page.get_by_test_id("start-oldest").click()
    page.wait_for_selector("[data-testid=summary]")

    # ================================================================ 2. one payment: who / how much / for what / how / next
    check("review: it is the oldest payment (a partial payment on invoice B)", page.url.endswith(f"/payments/{P1}"))
    amount_px = page.get_by_test_id("amount").evaluate("e => parseFloat(getComputedStyle(e).fontSize)")
    summ = page.get_by_test_id("summary").inner_text().replace("\n", " ")
    check("review: HOW MUCH is the biggest thing on the page", amount_px >= 40 and "100" in page.get_by_test_id("amount").inner_text(), f"{amount_px}px")
    check("review: WHO PAID names the parent and the child (with class)", PARENT[1] in summ and NAME_B in summ, summ[:200])
    check("review: FOR WHAT names the invoice and what is still owed", "INV" in summ and "Still owed" in summ and page.get_by_test_id("owed-now").inner_text().replace(",", "") == f"{BAL_B:g}", summ[:240])
    check("review: HOW gives the way of paying and the reference", "Bank transfer" in summ and "FT-1001" in summ)
    check("review: a partial payment says what would still be owed", "will still be owed (a partial payment)" in page.get_by_test_id("after").inner_text(), page.get_by_test_id("after").inner_text())
    img = page.locator("[data-testid=proof-image] img")
    img.wait_for()
    check("review: the proof image is large on the page (not a thumbnail)", img.evaluate("e => e.naturalWidth") > 100 and img.evaluate("e => e.getBoundingClientRect().width") >= 300, str(img.evaluate("e => [e.naturalWidth, Math.round(e.getBoundingClientRect().width)]")))
    page.get_by_role("button", name="Zoom in").click()
    check("review: zoom in enlarges it", img.get_attribute("data-zoomed") == "true" and img.evaluate("e => e.getBoundingClientRect().width") > 1000)
    page.get_by_role("button", name="Zoom out").click()
    page.get_by_role("button", name="Turn the picture").click()
    check("review: the picture can be turned, and opened full size in a new tab", "rotate(90deg)" in img.get_attribute("style") and page.get_by_role("link", name="Open full size").get_attribute("target") == "_blank")
    acc, rej = page.get_by_role("button", name="Payment Received"), page.get_by_role("button", name="Reject Payment")
    check("review: the two main actions are large and named: Payment Received / Reject Payment", acc.evaluate(H) >= 64 and rej.evaluate(H) >= 64 and acc.is_enabled())
    dup = page.get_by_test_id("duplicates")
    check("review: a possible repeat is pointed out (same reference, same invoice and amount) with a link", dup.count() == 1 and "Same reference number" in dup.inner_text() and dup.get_by_role("link").count() >= 1, dup.inner_text().replace("\n", " ")[:160] if dup.count() else "")
    page.screenshot(path=f"{SHOTS}/2_review.png", full_page=True)

    # ================================================================ 3. reject: a reason is required
    rej.click()
    panel = page.get_by_test_id("reject-panel")
    panel.wait_for()
    rb = panel.get_by_role("button", name="Reject Payment")
    check("reject: a panel asks WHY; the button is off and says why until a reason exists", rb.is_disabled() and panel.locator("text=Write or tap a reason to continue.").count() == 1)
    check("reject: nothing was rejected by opening the panel", q("select status from payments where id=?", P1)[0][0] == "pending")
    panel.get_by_role("button", name="The proof is not clear enough to read.").click()
    check("reject: tapping a common reason fills it in (and it can be edited)", page.input_value("#rejection_reason") == "The proof is not clear enough to read." and rb.is_enabled())
    page.fill("#rejection_reason", "   ")
    check("reject: a blank reason keeps the button off", rb.is_disabled())
    page.fill("#rejection_reason", "The proof is not clear enough to read.")
    rb.click()
    page.wait_for_selector("text=Payment rejected", timeout=30000)
    row = q("select status, rejection_reason, receipt_number from payments where id=?", P1)[0]
    check("reject: it is rejected with the reason kept, and no receipt number", row == ("rejected", "The proof is not clear enough to read.", None), str(row))
    check("reject: the page shows the reason given to the parent and that they were told", page.get_by_test_id("rejection-reason").inner_text() == "The proof is not clear enough to read." and "The parent has been told" in page.inner_text("body"))
    check("reject: the parent was notified (as before) and it is in the audit trail with the reason", until(lambda: q("select count(*) from notifications where type like '%PaymentRejected' and notifiable_id=?", PARENT[0])[0][0] == NOTIF0["PaymentRejected"] + 1) and q("select note from audit_logs where action='payment.rejected' order by id desc limit 1")[0][0] == "The proof is not clear enough to read.")
    check("reject: a rejected payment is not money (invoice B still owes everything)", balance(INV_B) == BAL_B)
    xs = unquote(next(c["value"] for c in ctx.cookies() if c["name"] == "XSRF-TOKEN"))

    def post_json(path, payload=None):
        return page.evaluate("""async ([path, payload, token]) => {
            const r = await fetch(path, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify(payload || {})});
            return [r.status, await r.text()];
        }""", [path, payload, xs])

    s_a, b_a = post_json(f"/payments/{P3}/reject", {})
    s_b, _ = post_json(f"/payments/{P3}/reject", {"rejection_reason": "x" * 256})
    check("reject: the server insists on a reason too (none, or over 255 characters, is refused)", (s_a, s_b) == (422, 422) and "rejection_reason" in b_a, str((s_a, s_b)))
    check("reject: ...and nothing changed", q("select status from payments where id=?", P3)[0][0] == "pending")

    # ================================================================ 4. verify next -> Payment Received
    check("next: after a decision the next payment is one tap away", page.get_by_role("link", name=re.compile("^Verify next payment")).count() == 1)
    page.get_by_role("link", name=re.compile("^Verify next payment")).first.click()
    page.wait_for_url(re.compile(rf"/payments/{P2}$"))
    page.wait_for_selector("[data-testid=summary]")
    check("next: it is the next oldest payment (P2, a PDF proof paying invoice A in full)", page.url.endswith(f"/payments/{P2}"))
    pdf = page.get_by_test_id("proof-pdf")
    check("proof: a PDF is not squeezed in - a big button opens it in a new tab", pdf.count() == 1 and pdf.get_by_role("link", name="Open the proof (PDF)").get_attribute("href").endswith(f"/payments/{P2}/proof") and pdf.get_by_role("link").get_attribute("target") == "_blank")
    check("proof: the PDF really is served to this accountant", page.request.get(f"{BASE}/payments/{P2}/proof").status == 200)
    check("review: paying the whole balance says the invoice will be fully paid", "invoice is fully paid" in page.get_by_test_id("after").inner_text())
    page.get_by_role("button", name="Payment Received").click()
    dlg = page.locator("[role=dialog]"); dlg.wait_for()
    body = dlg.inner_text().replace("\n", " ")
    check("accept: the consequences come first (what is recorded, the invoice result, receipt + parent told, cannot undo here)", "Payment received?" in body and "This records" in body and "fully paid" in body and "A receipt number is created and the parent is told" in body and "cannot undo this here" in body, body[:300])
    dlg.get_by_role("button", name="Go back and check").click()
    page.wait_for_selector("[role=dialog]", state="detached")
    check("accept: 'Go back and check' changes nothing", q("select status from payments where id=?", P2)[0][0] == "pending")
    page.get_by_role("button", name="Payment Received").click(); dlg.wait_for()
    dlg.get_by_role("button", name="Yes, payment received").click()
    page.wait_for_selector("[data-testid=receipt-number]", timeout=30000)
    vrow = q("select status, receipt_number, verified_by from payments where id=?", P2)[0]
    check("accept: it is verified with a receipt number RCT-yyyy-nnnnnn and the accountant recorded", vrow[0] == "verified" and re.fullmatch(r"RCT-\d{4}-\d{6}", vrow[1] or "") is not None and vrow[2] is not None, str(vrow))
    conf = page.get_by_test_id("received-details").inner_text().replace("\n", " ")
    check("confirmation: a strong screen with the receipt number, the way and reference, the invoice result and who checked", page.get_by_test_id("receipt-number").inner_text() == vrow[1] and "Mobile money" in conf and "MM-2002" in conf and "Fully paid" in conf, conf[:240])
    check("confirmation: it names the payment in words (amount, parent, child)", "Payment received" in page.inner_text("body") and PARENT[1] in page.inner_text("body"))
    rc = page.get_by_test_id("print-receipt")
    check("confirmation: 'Print receipt (PDF)' is a large button to the existing receipt", rc.evaluate(H) >= 56 and rc.get_attribute("href").endswith(f"/payments/{P2}/receipt"))
    r = page.request.get(rc.get_attribute("href"))
    check("confirmation: the receipt is a real PDF", r.status == 200 and "pdf" in r.headers.get("content-type", ""), str(r.status))
    check("confirmation: 'Verify next payment' names who is next", page.get_by_test_id("verify-next").count() == 1 and "Verify next payment" in page.get_by_test_id("verify-next").inner_text())
    notified = until(lambda: q("select count(*) from notifications where type like '%PaymentVerified' and notifiable_id=?", PARENT[0])[0][0] == NOTIF0["PaymentVerified"] + 1)
    check("accept: the parent was notified and the audit trail has it", notified and q("select count(*) from audit_logs where action='payment.verified'")[0][0] == AUDIT0 + 1, str((q("select count(*) from notifications where type like '%PaymentVerified'")[0], q("select action, count(*) from audit_logs where action like 'payment%' group by action"))))
    check("accept: invoice A is now paid in full", balance(INV_A) == 0)
    page.screenshot(path=f"{SHOTS}/4_received.png", full_page=True)

    # ================================================================ 5. a repeat of a decided payment
    page.reload(); page.wait_for_selector("[data-testid=receipt-number]")
    check("repeat: opening a decided payment offers no Payment Received / Reject buttons", page.get_by_role("button", name="Payment Received").count() == 0 and page.get_by_role("button", name="Reject Payment").count() == 0)
    post_json(f"/payments/{P2}/verify"); post_json(f"/payments/{P2}/reject", {"rejection_reason": "Too late"})
    check("repeat: verifying or rejecting it again changes nothing (still verified, same receipt, one audit entry)", q("select status, receipt_number from payments where id=?", P2)[0] == vrow[:2] and q("select count(*) from audit_logs where action='payment.verified' and auditable_id=?", P2)[0][0] == 1 and q("select rejection_reason from payments where id=?", P2)[0][0] is None)
    tab_a, tab_b = ctx.new_page(), ctx.new_page()
    for tab in (tab_a, tab_b):
        tab.goto(f"{BASE}/payments/{P6}"); tab.wait_for_selector("[data-testid=summary]")
    tab_a.get_by_role("button", name="Payment Received").click()
    tab_a.locator("[role=dialog]").get_by_role("button", name="Yes, payment received").click()
    tab_a.wait_for_selector("[data-testid=receipt-number]", timeout=30000)
    tab_b.get_by_role("button", name="Payment Received").click()
    tab_b.locator("[role=dialog]").get_by_role("button", name="Yes, payment received").click()
    tab_b.wait_for_selector("text=already verified by someone else", timeout=30000)
    row6 = q("select status, receipt_number from payments where id=?", P6)[0]
    check("duplicate attempt: a second person clicking Payment Received on the same payment is told it was already done, and nothing changes", "Nothing was changed" in tab_b.inner_text("body") and row6[0] == "verified" and q("select count(*) from audit_logs where action='payment.verified' and auditable_id=?", P6)[0][0] == 1 and q("select count(*) from payments where receipt_number=?", row6[1])[0][0] == 1, str(row6))
    check("duplicate attempt: the stale tab now shows the payment as received (no buttons left to press twice)", until(lambda: tab_b.get_by_role("button", name="Payment Received").count() == 0))
    tab_a.close(); tab_b.close()

    # ================================================================ 6. more than is owed
    page.goto(f"{BASE}/payments/pending"); page.wait_for_selector("[data-testid=payment-card]")
    blocked = page.locator(f"[data-testid=payment-card][data-payment-id='{P4}']")
    check("too much: the queue flags it before you open it", "Cannot be accepted" in blocked.inner_text())
    blocked.get_by_role("link", name="Check this payment").click(); page.wait_for_selector("[data-testid=summary]")
    check("too much: the page explains why it cannot be accepted and what to do", "cannot be accepted" in page.get_by_test_id("blocked").locator("xpath=ancestor::*[@role='alert'][1]").inner_text().lower() and "Reject it and ask the parent" in page.get_by_test_id("blocked").inner_text())
    check("too much: Payment Received is off (Reject stays available)", page.get_by_role("button", name="Payment Received").is_disabled() and page.get_by_role("button", name="Reject Payment").is_enabled())
    post_json(f"/payments/{P4}/verify")
    check("too much: the server refuses it too - it stays pending and no receipt number is used up", q("select status, receipt_number from payments where id=?", P4)[0] == ("pending", None))
    page.get_by_role("button", name="Reject Payment").click()
    page.get_by_role("button", name="This is not the right invoice or student.").click()
    page.get_by_test_id("reject-panel").get_by_role("button", name="Reject Payment").click()
    page.wait_for_selector("text=Payment rejected", timeout=30000)
    check("too much: rejecting it works", q("select status from payments where id=?", P4)[0][0] == "rejected")

    # ================================================================ 7. a partial payment
    page.goto(f"{BASE}/payments/{P3}"); page.wait_for_selector("[data-testid=summary]")
    check("partial: the same-transfer repeat is flagged against the rejected one", "Same reference number" in page.get_by_test_id("duplicates").inner_text())
    page.get_by_role("button", name="Payment Received").click(); dlg.wait_for()
    left = balance(INV_B) - 100
    check("partial: the confirmation says what will STILL be owed", "will still be owed" in dlg.inner_text() and format(left, ",g") in dlg.inner_text(), dlg.inner_text().replace("\n", " ")[:200])
    dlg.get_by_role("button", name="Yes, payment received").click()
    page.wait_for_selector("[data-testid=receipt-number]", timeout=30000)
    check("partial: it is accepted and the invoice is only partly paid", balance(INV_B) == left and "still owed (partly paid)" in page.get_by_test_id("received-details").inner_text(), page.get_by_test_id("received-details").inner_text().replace("\n", " ")[:200])
    check("partial: next up is the remaining payment", page.get_by_test_id("verify-next").count() == 1 and page.get_by_test_id("verify-next").get_attribute("href").endswith(f"/payments/{P5}"))

    # ================================================================ 8. refund and reversal
    check("refund: an accountant without refund-payments sees no refund controls", page.get_by_text("Refund or reverse this payment").count() == 0)
    s5, _ = post_json(f"/payments/{P3}/refund", {"amount": 10, "kind": "refund", "reason": "test"})
    check("refund: ...and the server refuses them (403)", s5 == 403, str(s5))
    octx, opage = login(browser, "owner@demo.test")
    opage.goto(f"{BASE}/payments/{P2}"); opage.wait_for_selector("[data-testid=receipt-number]")
    opage.locator("summary:has-text('Refund or reverse this payment')").click()
    rp = opage.get_by_test_id("refund-panel"); rp.wait_for()
    check("refund: the owner sees it, the two kinds explained in words, the amount prefilled with the most that can be returned", "Refund" in rp.inner_text() and "Reversal" in rp.inner_text() and float(opage.input_value("#refund_amount")) == q("select amount from payments where id=?", P2)[0][0])
    d2 = opage.locator("[role=dialog]")
    opage.fill("#refund_amount", "999999"); opage.fill("#refund_reason", "Paid twice by mistake")
    check("refund: asking for more than the payment is refused on the spot, in words, and the button is off", "You can return at most" in rp.inner_text() and rp.get_by_role("button", name="Record refund").is_disabled())
    otok = unquote(next(c["value"] for c in octx.cookies() if c["name"] == "XSRF-TOKEN"))
    opage.evaluate("""async ([path, token]) => { await fetch(path, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'Accept': 'application/json'}, body: JSON.stringify({amount: 999999, kind: 'refund', reason: 'too much'})}); }""", [f"/payments/{P2}/refund", otok])
    check("refund: the server refuses it too - nothing is recorded", q("select count(*) from payment_refunds")[0][0] == 0)
    opage.fill("#refund_amount", "50"); opage.fill("#refund_reason", "Paid twice by mistake")
    opage.get_by_test_id("refund-panel").get_by_role("button", name="Record refund").click()
    d2.wait_for(); d2.get_by_role("button", name="Go back and check").click()
    opage.wait_for_selector("[role=dialog]", state="detached")
    check("refund: 'Go back and check' records nothing", q("select count(*) from payment_refunds")[0][0] == 0)
    opage.get_by_test_id("refund-panel").get_by_role("button", name="Record refund").click(); d2.wait_for()
    d2.get_by_role("button", name="Yes, record the refund").click()
    check("refund: a refund of 50 is recorded as a separate entry", until(lambda: q("select amount, kind, reason from payment_refunds")[0] == (50, "refund", "Paid twice by mistake")))
    opage.wait_for_selector("[data-testid=refund-list]", timeout=30000)
    check("refund: the page lists the money returned, who and why; the payment itself is still 'verified'", "Refunded 50" in opage.get_by_test_id("refund-list").inner_text() and q("select status from payments where id=?", P2)[0][0] == "verified")
    check("refund: what is owed on the invoice went up by 50 (refunds subtract from what was collected)", balance(INV_A) == 50 and opage.get_by_test_id("owed-now").inner_text().replace(",", "") == "50")
    check("refund: it is audited", q("select count(*) from audit_logs where auditable_type like '%PaymentRefund'")[0][0] >= 1)

    # ================================================================ 9. authorization
    for email, label in [("teacher@demo.test", "a teacher"), ("parent@demo.test", "a parent"), ("registrar@demo.test", "the front desk")]:
        cx, pg = login(browser, email)
        codes = [pg.goto(f"{BASE}/payments/pending").status, pg.goto(f"{BASE}/payments/{P5}").status]
        tok = unquote(next(c["value"] for c in cx.cookies() if c["name"] == "XSRF-TOKEN"))
        vs = pg.evaluate("""async ([path, token]) => (await fetch(path, {method: 'POST', headers: {'X-XSRF-TOKEN': token, 'Accept': 'application/json'}})).status""", [f"/payments/{P5}/verify", tok])
        check(f"authorization: {label} cannot see the queue, open a payment or decide one (403)", codes == [403, 403] and vs == 403, str(codes + [vs]))
        cx.close()
    check("authorization: nothing was decided by them", q("select status from payments where id=?", P5)[0][0] == "pending")

    # ================================================================ 10. phone
    mctx, mpage = login(browser, "accountant@demo.test", mobile=True)
    mpage.goto(f"{BASE}/payments/{P5}"); mpage.wait_for_selector("[data-testid=summary]")
    check("phone: no sideways scrolling", mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"))
    check("phone: the amount is huge and readable", mpage.get_by_test_id("amount").evaluate("e => parseFloat(getComputedStyle(e).fontSize)") >= 40)
    bx = mpage.get_by_role("button", name="Payment Received").bounding_box()
    check("phone: Payment Received / Reject Payment stay on screen (pinned) and are >= 56px", bx is not None and bx["y"] + bx["height"] <= 844 and bx["height"] >= 56 and mpage.get_by_role("button", name="Reject Payment").evaluate(H) >= 56, str(bx))
    order = mpage.evaluate("""() => { const el = (id) => document.querySelector(`[data-testid=${id}]`); const before = (x, y) => Boolean(x.compareDocumentPosition(y) & Node.DOCUMENT_POSITION_FOLLOWING); return [before(el('summary'), el('proof')), before(el('proof'), el('actions'))]; }""")
    check("phone: the order is who/how much/for what, then the proof, then the buttons (so the proof is seen before deciding)", order == [True, True], str(order))
    mpage.screenshot(path=f"{SHOTS}/10_phone.png", full_page=False)

    # ================================================================ 11. Amharic
    q("update users set locale='am' where email='accountant@demo.test'")
    raw = re.compile(r"\bverify\.[a-z_]+\b")
    for path in ["/payments/pending", f"/payments/{P5}"]:
        mpage.goto(f"{BASE}{path}"); mpage.wait_for_selector("h1")
        check(f"Amharic: no raw translation keys on {path.split('/')[-1] if 'pending' in path else 'the payment page'}, and it fits a phone", not raw.search(mpage.inner_text("body")) and mpage.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), str(raw.findall(mpage.inner_text('body'))[:3]))
    mpage.screenshot(path=f"{SHOTS}/11_am.png", full_page=False)
    q("update users set locale=null where email='accountant@demo.test'")

    for nm, pg in [("accountant", page), ("owner", opage), ("phone", mpage), ("parent", ppage)]:
        check(f"no JavaScript errors ({nm})", not pg.js_errors, str(pg.js_errors)[:200])
    browser.close()

passed = sum(results)
print(f"\n{passed}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if passed == len(results) else 1)
