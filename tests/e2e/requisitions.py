"""Browser test: Phase B of the inventory module -- requisitions, approvals, and issuing against them.

  * building a requisition in the wizard (where/why -> items -> review) and submitting it
  * approving it (quantities can be reduced from what was asked)
  * issuing it in two parts; the second one completes it automatically (no separate close step)
  * rejecting a requisition, with a reason
  * someone without any requisition permission is refused

Uses the usability-session data; creates one throwaway store/item/requisition and removes them at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/requisitions.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
ITEM_NAME = "E2E Requisition Pencils"
STORE_NAME = "E2E Requisition Store"


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

q("delete from requisition_items where requisition_id in (select id from requisitions where purpose='E2E test requisition')")
q("delete from requisitions where purpose='E2E test requisition'")
q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name=?", STORE_NAME)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "owner@demo.test")

    # ---- set up a store with stock, as the owner (who already holds every inventory permission)
    page.goto(f"{BASE}/inventory/stores")
    page.get_by_role("button", name="Add Store").click()
    page.fill("#store_name", STORE_NAME)
    page.get_by_role("button", name="Add Store").click()
    page.wait_for_selector(f"text={STORE_NAME}")

    page.goto(f"{BASE}/inventory/items/new")
    page.fill("#item_name", ITEM_NAME)
    page.get_by_role("button", name="Save Item").click()
    page.wait_for_url(re.compile(r"/inventory/items/\d+$"))
    page.get_by_role("button", name="Receive Stock").click()
    page.wait_for_selector("[data-testid=receive-form]")
    page.locator("#receive_store").select_option(label=STORE_NAME)
    page.fill("#receive_quantity", "50")
    page.locator("[data-testid=receive-form]").get_by_role("button", name="Receive Stock").click()
    page.wait_for_selector("[data-testid=receive-form]", state="detached")

    # ================================================================ 1. build and submit a requisition
    page.goto(f"{BASE}/requisitions/new"); wait = page.wait_for_selector
    wait("text=Where and why")
    check("wizard step 1: 'Step 1 of 3', Next off until store/purpose/priority", "Step 1 of 3" in body(page) and nxt(page).is_disabled())
    page.locator("#req_store").select_option(label=STORE_NAME)
    page.fill("#req_purpose", "E2E test requisition")
    page.get_by_role("radio", name="Urgent").click()
    check("wizard step 1: Next is on once store/purpose/priority are set", nxt(page).is_enabled())
    nxt(page).click(); wait("text=What do you need")

    page.locator("#req_item_0").select_option(label=ITEM_NAME)
    page.fill("#req_qty_0", "20")
    check("wizard step 2: Next is on with a valid line", nxt(page).is_enabled())
    nxt(page).click(); wait("text=Check and send")
    check("wizard review: the line and the store are shown", ITEM_NAME in body(page) and STORE_NAME in body(page) and "20" in body(page))
    page.get_by_role("button", name="Submit for Approval").click()
    page.wait_for_url(re.compile(r"/requisitions/\d+$"))
    check("submitting opens the requisition's own page, awaiting review", "Awaiting review" in body(page))
    requisition_url = page.url

    # ================================================================ 2. approve with a reduced quantity
    check("show page: the approve form lists the requested quantity", "20 pcs requested" in body(page))
    page.fill("input[id^='approve_qty_']", "15")
    page.get_by_role("button", name="Approve Requisition").click()
    check("approving updates the status", until(lambda: "Approved" in body(page) or "15 approved" in body(page)))

    # ================================================================ 3. issue in two parts
    page.reload(); page.wait_for_selector("text=Issue this item")
    page.get_by_role("button", name="Issue this item").click()
    page.wait_for_selector("[data-testid=requisition-issue-form]")
    page.fill("#issue_qty", "999")
    page.locator("[data-testid=requisition-issue-form]").get_by_role("button", name="Issue").click()
    check("issuing more than remains is refused, in words", until(lambda: "left to issue on this line" in body(page)))
    page.fill("#issue_qty", "10")
    page.locator("[data-testid=requisition-issue-form]").get_by_role("button", name="Issue").click()
    page.wait_for_selector("[data-testid=requisition-issue-form]", state="detached")
    check("partial issue: status becomes partially issued", until(lambda: "Partially issued" in body(page)))

    page.get_by_role("button", name="Issue this item").click()
    page.wait_for_selector("[data-testid=requisition-issue-form]")
    page.fill("#issue_qty", "5")
    page.locator("[data-testid=requisition-issue-form]").get_by_role("button", name="Issue").click()
    page.wait_for_selector("[data-testid=requisition-issue-form]", state="detached")
    check("full issue: completes automatically, no separate close step", until(lambda: "Completed" in body(page)))
    check("full issue: no more 'Issue this item' button once fully issued", page.get_by_role("button", name="Issue this item").count() == 0)

    row = q("select id, status from requisitions where purpose='E2E test requisition'")
    check("db: requisition status is completed", row[0][1] == "completed", str(row))
    moved = q("select sum(quantity) from stock_movements where type='issue' and item_id=(select id from inventory_items where name=?)", ITEM_NAME)
    check("db: exactly 15 issued across the two movements", moved[0][0] == 15, str(moved))

    # ================================================================ 4. rejecting a second requisition
    page.goto(f"{BASE}/requisitions/new")
    page.locator("#req_store").select_option(label=STORE_NAME)
    page.fill("#req_purpose", "E2E test requisition")
    page.get_by_role("radio", name="Low", exact=True).click()
    nxt(page).click(); page.wait_for_selector("text=What do you need")
    page.locator("#req_item_0").select_option(label=ITEM_NAME)
    page.fill("#req_qty_0", "5")
    nxt(page).click(); page.wait_for_selector("text=Check and send")
    page.get_by_role("button", name="Submit for Approval").click()
    page.wait_for_url(re.compile(r"/requisitions/\d+$"))
    page.get_by_role("button", name="Reject Requisition").first.click()
    page.wait_for_selector("[data-testid=reject-form]")
    page.fill("#reject_reason", "No budget left this term")
    page.locator("[data-testid=reject-form]").get_by_role("button", name="Reject Requisition").click()
    check("rejecting shows the reason on the requisition's own page", until(lambda: "No budget left this term" in body(page)))

    # ================================================================ 5. someone without any requisition permission is refused
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no requisition access at all", all(status_of(tctx, u) == 403 for u in ("/requisitions", "/requisitions/new")))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/requisitions")
    mpage.wait_for_load_state("networkidle")
    check("phone: the requisitions list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

q("delete from requisition_items where requisition_id in (select id from requisitions where purpose='E2E test requisition')")
q("delete from requisitions where purpose='E2E test requisition'")
q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name=?", STORE_NAME)

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
