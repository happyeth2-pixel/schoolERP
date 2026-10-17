"""Browser test: Procurement Phase 1-4 -- suppliers, purchase requests, approval, quotations, purchase
orders, and goods receipt -- plus Accounting Phase C's supplier bills and payments on top of them.

  * adding a supplier gets it an auto-generated code
  * building a purchase request in the wizard (where/why -> items -> review), as a draft, then submitting it
  * a free-text line (not from the inventory catalog) is accepted
  * approving and rejecting (with a required reason) -- by someone OTHER than the requester (a requester
    can never approve their own request, even if they hold the permission)
  * a request above the school's configured large-purchase threshold needs the large-purchase permission,
    not just the ordinary one
  * someone without any procurement permission is refused
  * recording two suppliers' quotations against an approved request, comparing totals, selecting one
    (deselecting the other), and removing the one not selected -- a selected quotation offers no Remove
  * creating a purchase order prefilled from the selected quotation, sending it, cancelling it (which frees
    its quantity back up), and creating a second one for the same line -- plus permission checks
  * recording a partial goods receipt against a real catalog item (Partially received, stock balance moves
    by exactly that much), then the rest (Received, no more Record button) -- the three-way request/order/
    receipt check
  * recording a supplier bill against a fully-received order (prefilled quantity, over-billing past what was
    received refused), a second free-form bill with no order behind it, and paying one in full

Uses the usability-session data; grants two demo accounts a temporary permission for the run (restored at
the end), and creates one throwaway supplier/purchase request, removed at the end too.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/procurement.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
SUPPLIER_NAME = "E2E Stationery Supplier"
SUPPLIER_B_NAME = "E2E Quotation Supplier B"
PR_PURPOSE = "E2E test purchase request"
GR_PR_PURPOSE = "E2E goods receipt test request"
GR_ITEM_NAME = "E2E GR Catalog Item"
GR_STORE_NAME = "E2E GR Store"


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


def login(browser, email):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.goto(f"{BASE}/login")
    page.fill("#phone", email); page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    return ctx, page


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


def nxt(pg):
    return pg.locator("form").get_by_role("button", name="Next")


body = lambda pg: pg.locator("body").inner_text()

TEACHER = q("select id from users where email='teacher@demo.test'")[0][0]
ACCOUNTANT = q("select id from users where email='accountant@demo.test'")[0][0]
perm = lambda name: q("select id from permissions where name=?", name)[0][0]
mtype = q("select model_type from model_has_permissions limit 1")[0][0]


def grant(user_id, name):
    if not q("select 1 from model_has_permissions where model_id=? and permission_id=?", user_id, perm(name)):
        q("insert into model_has_permissions (permission_id, model_type, model_id) values (?, ?, ?)", perm(name), mtype, user_id)


def revoke(user_id, name):
    q("delete from model_has_permissions where model_id=? and permission_id=?", user_id, perm(name))


def cleanup():
    for purpose in (PR_PURPOSE, GR_PR_PURPOSE):
        q("delete from goods_receipt_items where goods_receipt_id in (select id from goods_receipts where purchase_order_id in (select id from purchase_orders where purchase_request_id in (select id from purchase_requests where purpose=?)))", purpose)
        q("delete from goods_receipts where purchase_order_id in (select id from purchase_orders where purchase_request_id in (select id from purchase_requests where purpose=?))", purpose)
        q("delete from purchase_order_items where purchase_order_id in (select id from purchase_orders where purchase_request_id in (select id from purchase_requests where purpose=?))", purpose)
        q("delete from purchase_orders where purchase_request_id in (select id from purchase_requests where purpose=?)", purpose)
        q("delete from quotation_items where quotation_id in (select id from quotations where purchase_request_id in (select id from purchase_requests where purpose=?))", purpose)
        q("delete from quotations where purchase_request_id in (select id from purchase_requests where purpose=?)", purpose)
        q("delete from purchase_request_items where purchase_request_id in (select id from purchase_requests where purpose=?)", purpose)
        q("delete from purchase_requests where purpose=?", purpose)
    q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", GR_ITEM_NAME)
    q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", GR_ITEM_NAME)
    q("delete from inventory_items where name=?", GR_ITEM_NAME)
    q("delete from inventory_stores where name=?", GR_STORE_NAME)
    q("delete from supplier_payments where supplier_bill_id in (select id from supplier_bills where supplier_id in (select id from suppliers where name in (?, ?)))", SUPPLIER_NAME, SUPPLIER_B_NAME)
    q("delete from supplier_bill_items where supplier_bill_id in (select id from supplier_bills where supplier_id in (select id from suppliers where name in (?, ?)))", SUPPLIER_NAME, SUPPLIER_B_NAME)
    q("delete from supplier_bills where supplier_id in (select id from suppliers where name in (?, ?))", SUPPLIER_NAME, SUPPLIER_B_NAME)
    q("delete from suppliers where name in (?, ?)", SUPPLIER_NAME, SUPPLIER_B_NAME)
    q("update schools set purchase_approval_threshold=null")
    revoke(TEACHER, 'purchase-request-create')
    revoke(ACCOUNTANT, 'purchase-approve')


cleanup()
grant(TEACHER, 'purchase-request-create')

SCHOOL_ID = q("select school_id from users where email='owner@demo.test'")[0][0]
q("insert into inventory_items (school_id, name, unit, min_stock_level, reorder_level, created_at, updated_at) values (?, ?, 'pcs', 0, 0, datetime('now'), datetime('now'))", SCHOOL_ID, GR_ITEM_NAME)
q("insert into inventory_stores (school_id, name, created_at, updated_at) values (?, ?, datetime('now'), datetime('now'))", SCHOOL_ID, GR_STORE_NAME)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. add a supplier (as the owner)
    page.goto(f"{BASE}/suppliers/new")
    page.fill("#supplier_name", SUPPLIER_NAME)
    page.fill("#supplier_phone", "0911223344")
    page.get_by_role("button", name="Save Supplier").click()
    page.wait_for_url(re.compile(r"/suppliers/\d+$"))
    check("supplier: opens its own page with an auto-generated SUP- code", "SUP-" in body(page) and SUPPLIER_NAME in body(page))

    page.goto(f"{BASE}/suppliers/new")
    page.fill("#supplier_name", SUPPLIER_B_NAME)
    page.fill("#supplier_phone", "0911223355")
    page.get_by_role("button", name="Save Supplier").click()
    page.wait_for_url(re.compile(r"/suppliers/\d+$"))

    # ================================================================ 2. build and submit a purchase request (as the teacher)
    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/purchase-requests/new")
    wait = lambda text: tp.wait_for_selector(f"text={text}")
    wait("Where and why")
    check("wizard step 1: Next off until purpose and priority are set", nxt(tp).is_disabled())
    tp.fill("#pr_purpose", PR_PURPOSE)
    tp.get_by_role("radio", name="Normal", exact=True).click()
    check("wizard step 1: Next is on once purpose and priority are set", nxt(tp).is_enabled())
    nxt(tp).click(); wait("What do you need")

    # a free-text line, not from the catalog
    tp.fill("#pr_desc_0", "Whiteboard markers, box of 12")
    tp.fill("#pr_qty_0", "5")
    tp.fill("#pr_price_0", "150")
    check("wizard step 2: a line total is shown live", until(lambda: "Estimated: 750.00 ETB" in body(tp)))
    nxt(tp).click(); wait("Check and send")
    check("wizard review: the line and its estimated total are shown", "Whiteboard markers" in body(tp) and "750.00 ETB" in body(tp))
    tp.get_by_role("button", name="Submit for Approval").click()
    tp.wait_for_url(re.compile(r"/purchase-requests/\d+$"))
    check("submitting opens the request's own page, awaiting review", "Awaiting review" in body(tp) and "750.00" in body(tp))
    pr_url = tp.url
    check("the requester themselves has no Approve button (never their own)", tp.get_by_role("button", name="Approve Purchase Request").count() == 0)

    # ================================================================ 3. the owner (a different person) approves it
    page.goto(pr_url)
    page.get_by_role("button", name="Approve Purchase Request").click()
    check("approve: status becomes Approved", until(lambda: "Approved" in body(page)))
    row = q("select status from purchase_requests where purpose=?", PR_PURPOSE)
    check("db: status is approved", row[0][0] == "approved", str(row))

    # ================================================================ 4. a second one, rejected with a reason
    tp.goto(f"{BASE}/purchase-requests/new")
    tp.fill("#pr_purpose", PR_PURPOSE)
    tp.get_by_role("radio", name="Low", exact=True).click()
    nxt(tp).click(); wait("What do you need")
    tp.fill("#pr_desc_0", "Extra chairs")
    tp.fill("#pr_qty_0", "2")
    nxt(tp).click(); wait("Check and send")
    tp.get_by_role("button", name="Submit for Approval").click()
    tp.wait_for_url(re.compile(r"/purchase-requests/\d+$"))
    pr2_url = tp.url

    page.goto(pr2_url)
    page.get_by_role("button", name="Reject Purchase Request").first.click()
    page.wait_for_selector("[data-testid=reject-form]")
    page.fill("#reject_reason", "Not needed this term")
    page.locator("[data-testid=reject-form]").get_by_role("button", name="Reject Purchase Request").click()
    check("reject: shows the reason on the request's own page", until(lambda: "Not needed this term" in body(page)))

    # ================================================================ 5. the large-purchase threshold
    page.goto(f"{BASE}/settings/school"); page.wait_for_selector("[data-testid=settings-form]")
    page.fill("#purchase_approval_threshold", "1000")
    page.get_by_role("button", name="Save school settings").click()
    check("threshold: setting saved", until(lambda: q("select purchase_approval_threshold from schools")[0][0] == 1000))

    tp.goto(f"{BASE}/purchase-requests/new")
    tp.fill("#pr_purpose", PR_PURPOSE)
    tp.get_by_role("radio", name="Urgent", exact=True).click()
    nxt(tp).click(); wait("What do you need")
    tp.fill("#pr_desc_0", "New projector")
    tp.fill("#pr_qty_0", "1")
    tp.fill("#pr_price_0", "5000")
    nxt(tp).click(); wait("Check and send")
    tp.get_by_role("button", name="Submit for Approval").click()
    tp.wait_for_url(re.compile(r"/purchase-requests/\d+$"))
    pr3_url = tp.url
    check("above the threshold: a note says large-purchase approval is needed", until(lambda: "large-purchase approval" in body(tp)))

    grant(ACCOUNTANT, 'purchase-approve')  # the ordinary tier only, NOT -large
    actx, ap = login(browser, "accountant@demo.test")
    ap.goto(pr3_url)
    check("above the threshold: an ordinary approver (not large-purchase) has no Approve button", ap.get_by_role("button", name="Approve Purchase Request").count() == 0)
    actx.close()

    page.goto(pr3_url)  # the owner holds purchase-approve-large too
    check("above the threshold: someone holding the large-purchase permission does see it", page.get_by_role("button", name="Approve Purchase Request").count() == 1)
    page.get_by_role("button", name="Approve Purchase Request").click()
    check("above the threshold: approving with the large permission succeeds", until(lambda: "Approved" in body(page)))

    # ================================================================ 6. someone without any procurement permission is refused
    tctx.close()
    revoke(TEACHER, 'purchase-request-create')
    tctx2, tp2 = login(browser, "teacher@demo.test")
    check("teacher (permission revoked again): no procurement access at all", all(status_of(tctx2, u) == 403 for u in ("/suppliers", "/purchase-requests", "/purchase-requests/new")))
    tctx2.close()

    # ================================================================ 7. quotations -- comparing two suppliers and selecting one (pr_url, approved in step 3)
    page.goto(pr_url)
    page.get_by_role("button", name="Add quotation").click()
    page.wait_for_selector("[data-testid=quotation-form]")
    page.select_option("#quote_supplier", label=SUPPLIER_NAME)
    page.fill("#quote_date", "2026-01-15")
    page.locator("[data-testid=quotation-form] input[id^='quote_price_']").first.fill("100")
    check("quotation A: a line total appears live", until(lambda: "Line total: 500.00 ETB" in body(page)))
    page.locator("[data-testid=quotation-form]").get_by_role("button", name="Save quotation").click()
    page.wait_for_selector("[data-testid=quotation-form]", state="hidden")
    rows = page.locator("[data-testid=quotation-row]")
    rows_text = lambda: "\n".join(rows.all_inner_texts())
    check("quotation A: recorded and listed with its total", until(lambda: rows.count() == 1 and "500.00 ETB" in rows_text()))

    page.get_by_role("button", name="Add quotation").click()
    page.wait_for_selector("[data-testid=quotation-form]")
    page.select_option("#quote_supplier", label=SUPPLIER_B_NAME)
    page.fill("#quote_date", "2026-01-16")
    page.locator("[data-testid=quotation-form] input[id^='quote_price_']").first.fill("80")
    page.locator("[data-testid=quotation-form]").get_by_role("button", name="Save quotation").click()
    page.wait_for_selector("[data-testid=quotation-form]", state="hidden")
    check("quotation B: recorded too -- both now listed for comparison", until(lambda: rows.count() == 2 and "400.00 ETB" in rows_text() and "500.00 ETB" in rows_text()))

    check("quotations: two rows, neither selected yet", rows.count() == 2 and "Selected" not in rows_text())

    row_b = rows.filter(has_text=SUPPLIER_B_NAME)
    row_a = rows.filter(has_text=SUPPLIER_NAME)
    row_b.get_by_role("button", name="Select this quotation").click()
    check("quotation B: selecting it marks it Selected", until(lambda: row_b.get_by_text("Selected").count() == 1))
    check("quotation A: selecting B clears A's own selection", "Selected" not in row_a.inner_text())
    check("quotation B (selected): offers no Remove button", row_b.get_by_role("button", name="Remove quotation").count() == 0)

    row_a.get_by_role("button", name="Remove quotation").click()
    page.wait_for_selector("text=Remove quotation?")
    page.get_by_role("button", name="Remove quotation", exact=True).last.click()
    check("quotation A (not selected): removed successfully, one quotation remains", until(lambda: page.locator("[data-testid=quotation-row]").count() == 1))
    check("db: exactly one quotation remains and it is the selected one", len(q("select selected from quotations where purchase_request_id in (select id from purchase_requests where purpose=?)", PR_PURPOSE)) == 1)

    # ================================================================ 8. purchase orders -- prefilled from the selected quotation, sent, then cancelled
    page.reload()
    page.get_by_role("button", name="Create purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]")
    check("PO form: prefilled from the selected quotation (supplier B)", SUPPLIER_B_NAME in body(page))
    po_qty = page.locator("[data-testid=purchase-order-form] input[id^='po_qty_']").first
    po_price = page.locator("[data-testid=purchase-order-form] input[id^='po_price_']").first
    check("PO form: quantity and price came from the selected quotation", po_qty.input_value() == "5" and float(po_price.input_value()) == 80)
    page.fill("#po_order_date", "2026-01-20")
    page.locator("[data-testid=purchase-order-form]").get_by_role("button", name="Save purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]", state="hidden")
    check("PO: created as a draft, listed on the request's own page", until(lambda: "400.00 ETB" in body(page) and "Draft" in body(page)))

    po_row = page.locator("[data-testid=po-summary-row]").first
    po_row.get_by_role("link").click()
    page.wait_for_url(re.compile(r"/purchase-orders/\d+$"))
    po_url = page.url
    check("PO page: shows the ordered line and its total", until(lambda: "Whiteboard markers" in body(page) and "400.00 ETB" in body(page)))

    page.get_by_role("button", name="Mark as Sent to Supplier").click()
    check("PO: sending marks it Sent", until(lambda: "Sent" in body(page)))

    page.get_by_role("button", name="Cancel Purchase Order").click()
    page.wait_for_selector("[data-testid=cancel-po-form]")
    page.fill("#cancel_po_reason", "Wrong delivery date")
    page.locator("[data-testid=cancel-po-form]").get_by_role("button", name="Cancel Purchase Order").click()
    check("PO: cancelling shows Cancelled and the reason", until(lambda: "Cancelled" in body(page) and "Wrong delivery date" in body(page)))
    check("db: the order is cancelled", q("select status from purchase_orders order by id desc limit 1")[0][0] == "cancelled")

    # cancelling freed the line's quantity -- a second, full-quantity order can be created for it
    page.goto(pr_url)
    check("PO: cancelling freed the line back up for a new order", page.get_by_role("button", name="Create purchase order").count() == 1)
    page.get_by_role("button", name="Create purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]")
    page.fill("#po_order_date", "2026-01-21")
    page.locator("[data-testid=purchase-order-form]").get_by_role("button", name="Save purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]", state="hidden")
    check("PO: a second order for the same freed line succeeds", until(lambda: page.locator("[data-testid=po-summary-row]").count() == 2))

    # ================================================================ 9. purchase orders: index page and permission check
    page.goto(f"{BASE}/purchase-orders")
    check("PO index: lists the orders just created", until(lambda: page.locator("[data-testid=po-row]").count() >= 2))

    tctx3, tp3 = login(browser, "teacher@demo.test")
    check("teacher: no purchase-order-manage permission, refused", status_of(tctx3, "/purchase-orders") == 403)
    tctx3.close()

    # ================================================================ 10. goods receipt -- three-way check, partial then full, actually moving stock
    grant(TEACHER, 'purchase-request-create')
    tctx4, tp4 = login(browser, "teacher@demo.test")
    wait4 = lambda text: tp4.wait_for_selector(f"text={text}")
    tp4.goto(f"{BASE}/purchase-requests/new")
    wait4("Where and why")
    tp4.fill("#pr_purpose", GR_PR_PURPOSE)
    tp4.get_by_role("radio", name="Normal", exact=True).click()
    nxt(tp4).click(); wait4("What do you need")
    tp4.select_option("#pr_catalog_0", label=GR_ITEM_NAME)
    tp4.fill("#pr_qty_0", "5")
    tp4.fill("#pr_price_0", "50")
    nxt(tp4).click(); wait4("Check and send")
    tp4.get_by_role("button", name="Submit for Approval").click()
    tp4.wait_for_url(re.compile(r"/purchase-requests/\d+$"))
    gr_pr_url = tp4.url
    tctx4.close()

    page.goto(gr_pr_url)
    page.get_by_role("button", name="Approve Purchase Request").click()
    check("goods receipt setup: request approved", until(lambda: "Approved" in body(page)))

    page.get_by_role("button", name="Create purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]")
    page.select_option("#po_supplier", label=SUPPLIER_NAME)
    page.fill("#po_order_date", "2026-02-01")
    page.locator("[data-testid=purchase-order-form] input[id^='po_price_']").first.fill("50")
    page.locator("[data-testid=purchase-order-form]").get_by_role("button", name="Save purchase order").click()
    page.wait_for_selector("[data-testid=purchase-order-form]", state="hidden")
    page.locator("[data-testid=po-summary-row]").first.get_by_role("link").click()
    page.wait_for_url(re.compile(r"/purchase-orders/\d+$"))
    gr_po_url = page.url

    page.get_by_role("button", name="Mark as Sent to Supplier").click()
    check("goods receipt setup: order sent, ready to receive", until(lambda: "Sent" in body(page)))

    page.get_by_role("button", name="Record goods receipt").click()
    page.wait_for_selector("[data-testid=goods-receipt-form]")
    page.select_option("#gr_store", label=GR_STORE_NAME)
    page.fill("#gr_date", "2026-02-05")
    page.locator("[data-testid=goods-receipt-form] input[id^='gr_qty_']").first.fill("3")
    page.locator("[data-testid=goods-receipt-form]").get_by_role("button", name="Save goods receipt").click()
    page.wait_for_selector("[data-testid=goods-receipt-form]", state="hidden")
    check("goods receipt: a partial receipt marks the order Partially received", until(lambda: "Partially received" in body(page)))
    check("goods receipt: shows 3 of 5 received", "3 of 5 received" in body(page))
    check("db: stock balance reflects the partial receipt", until(lambda: q("select quantity from stock_balances where item_id=(select id from inventory_items where name=?) and store_id=(select id from inventory_stores where name=?)", GR_ITEM_NAME, GR_STORE_NAME)[0][0] == 3))

    page.get_by_role("button", name="Record goods receipt").click()
    page.wait_for_selector("[data-testid=goods-receipt-form]")
    page.select_option("#gr_store", label=GR_STORE_NAME)
    page.fill("#gr_date", "2026-02-06")
    page.locator("[data-testid=goods-receipt-form] input[id^='gr_qty_']").first.fill("2")
    page.locator("[data-testid=goods-receipt-form]").get_by_role("button", name="Save goods receipt").click()
    page.wait_for_selector("[data-testid=goods-receipt-form]", state="hidden")
    check("goods receipt: the remaining amount completes it -- Received", until(lambda: "Received" in body(page)))
    check("db: stock balance reflects the full receipt", until(lambda: q("select quantity from stock_balances where item_id=(select id from inventory_items where name=?) and store_id=(select id from inventory_stores where name=?)", GR_ITEM_NAME, GR_STORE_NAME)[0][0] == 5))
    check("goods receipt: both receipts are listed on the order's own page", page.locator("[data-testid=gr-row]").count() == 2)
    check("goods receipt: a fully received order offers no more Record button", page.get_by_role("button", name="Record goods receipt").count() == 0)

    # ================================================================ 11. supplier bills -- the three-way match, a free-form bill, and paying it
    po_id = re.search(r"/purchase-orders/(\d+)$", gr_po_url).group(1)
    page.goto(f"{BASE}/supplier-bills/new?purchase_order_id={po_id}")
    check("supplier bill: prefilled from the fully-received order", until(lambda: SUPPLIER_NAME in body(page) and GR_ITEM_NAME in body(page)))
    bill_qty = page.locator("[data-testid=bill-line] input[id^='bill_qty_']").first
    check("supplier bill: quantity defaults to what was received", bill_qty.input_value() == "5")

    bill_qty.fill("6")
    page.get_by_role("button", name="Save bill").click()
    check("supplier bill: over-billing past what was received is refused", until(lambda: "received and not yet billed" in body(page)))

    bill_qty.fill("5")
    page.get_by_role("button", name="Save bill").click()
    page.wait_for_url(re.compile(r"/supplier-bills/\d+$"))
    check("supplier bill: saved and opens its own page", until(lambda: "250.00 ETB" in body(page)))
    bill_url = page.url

    page.goto(f"{BASE}/supplier-bills/new")
    page.select_option("#bill_supplier", label=SUPPLIER_NAME)
    page.fill("#bill_desc_0", "Ad hoc delivery fee")
    page.fill("#bill_qty_0", "1")
    page.fill("#bill_price_0", "150")
    page.get_by_role("button", name="Save bill").click()
    page.wait_for_url(re.compile(r"/supplier-bills/\d+$"))
    check("supplier bill: a free-form bill with no purchase order is accepted", until(lambda: "150.00 ETB" in body(page)))

    page.goto(f"{BASE}/supplier-bills")
    check("supplier bills: both bills are listed", until(lambda: page.locator("[data-testid=bill-row]").count() >= 2))

    page.goto(bill_url)
    page.get_by_role("button", name="Record payment").click()
    page.wait_for_selector("[data-testid=supplier-payment-form]")
    page.fill("#sp_date", "2026-02-10")
    page.locator("[data-testid=supplier-payment-form]").get_by_role("button", name="Save payment").click()
    page.wait_for_selector("[data-testid=supplier-payment-form]", state="hidden")
    check("supplier bill: paying in full shows Fully paid", until(lambda: "Fully paid" in body(page)))
    check("supplier bill: the payment is listed", page.locator("[data-testid=sp-row]").count() == 1)

    tctx5, tp5 = login(browser, "teacher@demo.test")
    check("teacher: no supplier-bill access", status_of(tctx5, "/supplier-bills") == 403)
    tctx5.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/purchase-orders")
    mpage.wait_for_load_state("networkidle")
    check("phone: the purchase orders list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
