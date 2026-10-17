"""Browser test: Phase D of the inventory module -- stocktakes.

  * starting a stocktake snapshots the store's current balances
  * Complete is off until every item has a count, and off again until every difference has a reason
  * completing turns a real difference into a stock adjustment (the balance actually changes)
  * a second stocktake cannot start for a store that already has one in progress
  * someone without stock-adjust is refused

Uses the usability-session data; creates one throwaway store/item/stocktake and removes them at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/stocktakes.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
ITEM_NAME = "E2E Stocktake Paper"
STORE_NAME = "E2E Stocktake Store"


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
q("delete from stocktake_lines where stocktake_id in (select id from stocktakes where store_id in (select id from inventory_stores where name=?))", STORE_NAME)
q("delete from stocktakes where store_id in (select id from inventory_stores where name=?)", STORE_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name=?", STORE_NAME)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "owner@demo.test")

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
    page.fill("#receive_quantity", "100")
    page.locator("[data-testid=receive-form]").get_by_role("button", name="Receive Stock").click()
    page.wait_for_selector("[data-testid=receive-form]", state="detached")

    # ================================================================ 1. start a stocktake
    page.goto(f"{BASE}/stocktakes")
    page.get_by_role("button", name="Start Stocktake").click()
    page.wait_for_selector("[data-testid=start-stocktake-form]")
    page.locator("#stocktake_store").select_option(label=STORE_NAME)
    page.locator("[data-testid=start-stocktake-form]").get_by_role("button", name="Start Stocktake").click()
    page.wait_for_url(re.compile(r"/stocktakes/\d+$"))
    check("start: shows the item and the system count", ITEM_NAME in body(page) and "100" in body(page))
    stocktake_url = page.url

    # ================================================================ 2. Complete is off until counted
    check("Complete is off before anything is counted", page.get_by_role("button", name="Complete Stocktake").is_disabled())
    check("says why", "needs a count" in body(page))

    # ================================================================ 3. count with a difference, no reason yet
    page.fill("input[id^='count_']", "97")
    check("a difference is shown in words", until(lambda: "3 fewer than the system says" in body(page)))
    check("Complete is still off without a reason", page.get_by_role("button", name="Complete Stocktake").is_disabled())

    page.fill("input[id^='reason_']", "3 reams damaged by damp")
    check("Complete turns on once every difference has a reason", until(lambda: page.get_by_role("button", name="Complete Stocktake").is_enabled()))

    # ================================================================ 4. save without completing keeps it in progress
    page.get_by_role("button", name="Save Counts").click()
    check("save: stays in progress, counted value kept after reload", until(lambda: "In progress" in body(page)))
    page.reload(); page.wait_for_selector("[data-testid=stocktake-lines]")
    check("reload: the saved count and reason are still there", page.input_value("input[id^='count_']") == "97" and page.input_value("input[id^='reason_']") == "3 reams damaged by damp")

    # ================================================================ 5. a second stocktake for the same store is refused
    page.goto(f"{BASE}/stocktakes")
    page.get_by_role("button", name="Start Stocktake").click()
    page.wait_for_selector("[data-testid=start-stocktake-form]")
    page.locator("#stocktake_store").select_option(label=STORE_NAME)
    page.locator("[data-testid=start-stocktake-form]").get_by_role("button", name="Start Stocktake").click()
    check("a second stocktake for a store already being counted is refused, in words", until(lambda: "already has a stocktake in progress" in body(page)))
    page.get_by_role("button", name="Cancel").click()

    # ================================================================ 6. complete: the balance actually changes
    page.goto(stocktake_url)
    page.get_by_role("button", name="Complete Stocktake").click()
    check("complete: status becomes Completed", until(lambda: "Completed" in body(page)))
    check("complete: no more inputs (read-only)", page.locator("input[id^='count_']").count() == 0)
    balance = q("select quantity from stock_balances where item_id=(select id from inventory_items where name=?)", ITEM_NAME)
    check("db: the balance now matches the physical count", balance[0][0] == 97, str(balance))
    movement = q("select type, quantity from stock_movements where item_id=(select id from inventory_items where name=?) and type='adjustment_decrease'", ITEM_NAME)
    check("db: recorded as a real adjustment movement, traceable to this stocktake", len(movement) == 1 and movement[0][1] == 3, str(movement))

    # ================================================================ 7. someone without stock-adjust is refused
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no stocktake access at all", all(status_of(tctx, u) == 403 for u in ("/stocktakes", stocktake_url.replace(BASE, ""))))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/stocktakes")
    mpage.wait_for_load_state("networkidle")
    check("phone: the stocktakes list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

q("delete from stock_movements where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from stocktake_lines where stocktake_id in (select id from stocktakes where store_id in (select id from inventory_stores where name=?))", STORE_NAME)
q("delete from stocktakes where store_id in (select id from inventory_stores where name=?)", STORE_NAME)
q("delete from stock_balances where item_id in (select id from inventory_items where name=?)", ITEM_NAME)
q("delete from inventory_items where name=?", ITEM_NAME)
q("delete from inventory_stores where name=?", STORE_NAME)

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
