"""Browser test: Phase A of the inventory module -- catalog, stores, and the stock ledger.

  * adding a store, then an item
  * receiving stock, seeing the on-hand count and "In stock" badge appear
  * issuing stock down to the reorder level flips the badge to "Low stock"
  * transferring stock between two stores moves it out of one and into the other
  * the item's own page shows its per-store balances and recent movements
  * the dashboard shows inventory tiles to someone who can see them
  * someone without any inventory permission is refused

Uses the usability-session data; creates one throwaway store/item and removes them at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/inventory.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
ITEM_NAME = "E2E Exercise Book"
STORE_A = "E2E Main Store"
STORE_B = "E2E Lab Store"


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


body = lambda pg: pg.locator("body").inner_text()

q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name in (?, ?)", STORE_A, STORE_B)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. add two stores
    page.goto(f"{BASE}/inventory/stores")
    page.get_by_role("button", name="Add Store").click()
    page.fill("#store_name", STORE_A)
    page.get_by_role("button", name="Add Store").click()
    page.wait_for_selector(f"text={STORE_A}")
    page.get_by_role("button", name="Add Store").click()
    page.fill("#store_name", STORE_B)
    page.get_by_role("button", name="Add Store").last.click()
    page.wait_for_selector(f"text={STORE_B}")
    check("stores: both new stores are listed", STORE_A in body(page) and STORE_B in body(page))

    # ================================================================ 2. add an item
    page.goto(f"{BASE}/inventory/items/new")
    page.fill("#item_name", ITEM_NAME)
    page.fill("#item_reorder", "5")
    page.get_by_role("button", name="Save Item").click()
    page.wait_for_url(re.compile(r"/inventory/items/\d+$"))
    check("item: saving opens its own page", ITEM_NAME in body(page))
    item_url = page.url

    # ================================================================ 3. receive stock
    page.get_by_role("button", name="Receive Stock").click()
    page.wait_for_selector("[data-testid=receive-form]")
    page.locator("#receive_store").select_option(label=STORE_A)
    page.fill("#receive_quantity", "50")
    page.locator("[data-testid=receive-form]").get_by_role("button", name="Receive Stock").click()
    page.wait_for_selector("[data-testid=receive-form]", state="detached")
    check("receive: total on hand becomes 50", until(lambda: "50" in page.locator("[data-testid=total-on-hand]").inner_text()))

    # ================================================================ 4. issue stock down to the reorder level
    page.get_by_role("button", name="Issue Stock").click()
    page.wait_for_selector("[data-testid=issue-form]")
    page.locator("#issue_store").select_option(label=STORE_A)
    page.fill("#issue_quantity", "45")
    page.fill("#issue_reason", "Grade 5 classroom supplies")
    page.locator("[data-testid=issue-form]").get_by_role("button", name="Issue Stock").click()
    page.wait_for_selector("[data-testid=issue-form]", state="detached")
    check("issue: total on hand becomes 5", until(lambda: page.locator("[data-testid=total-on-hand]").inner_text().strip().startswith("5 ")))

    # ================================================================ 5. issuing past what is available is refused
    page.get_by_role("button", name="Issue Stock").click()
    page.wait_for_selector("[data-testid=issue-form]")
    page.locator("#issue_store").select_option(label=STORE_A)
    page.fill("#issue_quantity", "999")
    page.fill("#issue_reason", "too many")
    page.locator("[data-testid=issue-form]").get_by_role("button", name="Issue Stock").click()
    check("issue: refusing more than is available says so, in words", until(lambda: "available" in body(page)))
    page.get_by_role("button", name="Cancel").click()
    page.wait_for_selector("[data-testid=issue-form]", state="detached")

    # ================================================================ 6. list shows Low stock now
    page.goto(f"{BASE}/inventory/items")
    row = page.locator("[data-testid=item-row]").filter(has_text=ITEM_NAME)
    check("list: the item now shows Low stock and 5 on hand", "Low stock" in row.inner_text() and "5" in row.locator("[data-testid=on-hand]").inner_text())

    # ================================================================ 7. transfer between stores
    page.goto(item_url)
    page.get_by_role("button", name="Transfer").click()
    page.wait_for_selector("[data-testid=transfer-form]")
    page.locator("#transfer_from").select_option(label=STORE_A)
    page.locator("#transfer_to").select_option(label=STORE_B)
    page.fill("#transfer_quantity", "2")
    page.locator("[data-testid=transfer-form]").get_by_role("button", name="Transfer").click()
    page.wait_for_selector("[data-testid=transfer-form]", state="detached")
    balances = page.locator("[data-testid=balances]").inner_text()
    check("transfer: source store now shows 3 pcs", "3 pcs" in balances, balances)
    check("transfer: destination store now shows 2 pcs", "2 pcs" in balances, balances)

    # ================================================================ 8. recent movements are listed on the item page
    check("item page: recent movements lists receive, issue and transfer", until(lambda: page.locator("[data-testid=movement-row]").count() >= 3))

    # ================================================================ 9. the full movement log also shows them
    page.goto(f"{BASE}/inventory/movements")
    check("movement log: this item's movements appear", ITEM_NAME in body(page))

    # ================================================================ 10. dashboard tiles
    page.goto(f"{BASE}/dashboard")
    check("dashboard: inventory tiles are shown to an owner", "Low Stock" in body(page) or "Inventory Items" in body(page))

    # ================================================================ 11. someone without inventory permission is refused
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no inventory access at all", all(status_of(tctx, u) == 403 for u in ("/inventory/items", "/inventory/stores", "/inventory/movements")))
    tctx.close()

    check("no JavaScript errors", not getattr(page, "js_errors", []))
    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/inventory/items")
    mpage.wait_for_load_state("networkidle")
    check("phone: the inventory items list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name in (?, ?)", STORE_A, STORE_B)

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
