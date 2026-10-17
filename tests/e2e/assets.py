"""Browser test: Phase C of the inventory module -- fixed assets and their history.

  * adding an asset (it starts "Available" at a chosen store)
  * assigning it to a person, then reassigning it to someone else
  * sending it for repair and completing the repair (fixed -> back to its holder)
  * returning it to a store, then transferring it to another store
  * marking it lost, then retiring and disposing of it
  * every step appears in the asset's own history
  * someone without any asset permission is refused

Uses the usability-session data; creates one throwaway store/asset and removes them at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/assets.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
ASSET_NAME = "E2E Laptop"
STORE_NAME = "E2E ICT Store"
STORE2_NAME = "E2E Lab Store"


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

q("delete from asset_events where asset_id in (select id from assets where name=?)", ASSET_NAME)
q("delete from assets where name=?", ASSET_NAME)
q("delete from inventory_stores where name in (?, ?)", STORE_NAME, STORE2_NAME)

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "owner@demo.test")

    page.goto(f"{BASE}/inventory/stores")
    for name in (STORE_NAME, STORE2_NAME):
        page.get_by_role("button", name="Add Store").click()
        page.fill("#store_name", name)
        page.get_by_role("button", name="Add Store").click()
        page.wait_for_selector(f"text={name}")

    # ================================================================ 1. add an asset
    page.goto(f"{BASE}/assets/new")
    page.fill("#asset_name", ASSET_NAME)
    page.locator("#asset_store").select_option(label=STORE_NAME)
    page.get_by_role("button", name="Save Asset").click()
    page.wait_for_url(re.compile(r"/assets/\d+$"))
    check("save: opens the asset's own page, Available", "Available" in body(page) and ASSET_NAME in body(page))
    check("save: an AST- tag was generated", re.search(r"AST-\d+", body(page)) is not None)
    asset_url = page.url

    # ================================================================ 2. assign, then reassign
    page.get_by_role("button", name="Assign").click()
    page.wait_for_selector("[data-testid=asset-person-form]")
    page.locator("#asset_person").select_option(label="Meseret Alemu")
    page.locator("[data-testid=asset-person-form]").get_by_role("button", name="Assign").click()
    page.wait_for_selector("[data-testid=asset-person-form]", state="detached")
    check("assign: status becomes Assigned, to the chosen person", until(lambda: "Assigned" in body(page) and "Meseret Alemu" in body(page)))

    page.get_by_role("button", name="Reassign").click()
    page.wait_for_selector("[data-testid=asset-person-form]")
    page.locator("#asset_person").select_option(label="Samuel Girma")
    page.locator("[data-testid=asset-person-form]").get_by_role("button", name="Reassign").click()
    page.wait_for_selector("[data-testid=asset-person-form]", state="detached")
    check("reassign: now with the new person", until(lambda: "Samuel Girma" in body(page)))

    # ================================================================ 3. repair cycle
    page.get_by_role("button", name="Send for Repair").click()
    page.wait_for_selector("[data-testid=asset-notes-form]")
    page.fill("#asset_notes", "Keyboard not working")
    page.locator("[data-testid=asset-notes-form]").get_by_role("button", name="Send for Repair").click()
    page.wait_for_selector("[data-testid=asset-notes-form]", state="detached")
    check("repair: status becomes Under repair", until(lambda: "Under repair" in body(page)))

    page.get_by_role("button", name="Record Repair Outcome").click()
    page.wait_for_selector("[data-testid=asset-repair-complete-form]")
    page.get_by_role("radio", name="Fixed").click()
    page.locator("[data-testid=asset-repair-complete-form]").get_by_role("button", name="Record Repair Outcome").click()
    page.wait_for_selector("[data-testid=asset-repair-complete-form]", state="detached")
    check("repair complete (fixed): back to Assigned, still with Samuel", until(lambda: "Assigned" in body(page) and "Samuel Girma" in body(page)))

    # ================================================================ 4. return, then transfer
    page.get_by_role("button", name="Return to Store").click()
    page.wait_for_selector("[data-testid=asset-store-form]")
    page.locator("#asset_store").select_option(label=STORE_NAME)
    page.locator("[data-testid=asset-store-form]").get_by_role("button", name="Return to Store").click()
    page.wait_for_selector("[data-testid=asset-store-form]", state="detached")
    check("return: status becomes Available again, at the chosen store", until(lambda: "Available" in body(page) and STORE_NAME in body(page)))

    page.get_by_role("button", name="Move to Another Store").click()
    page.wait_for_selector("[data-testid=asset-store-form]")
    page.locator("#asset_store").select_option(label=STORE2_NAME)
    page.locator("[data-testid=asset-store-form]").get_by_role("button", name="Move to Another Store").click()
    page.wait_for_selector("[data-testid=asset-store-form]", state="detached")
    check("transfer: now at the other store", until(lambda: STORE2_NAME in body(page)))

    # ================================================================ 5. lost, retire, dispose
    page.get_by_role("button", name="Mark Lost").click()
    page.wait_for_selector("[data-testid=asset-notes-form]")
    page.fill("#asset_notes", "Not found after the office move")
    page.locator("[data-testid=asset-notes-form]").get_by_role("button", name="Mark Lost").click()
    page.wait_for_selector("[data-testid=asset-notes-form]", state="detached")
    check("mark lost: status becomes Lost", until(lambda: "Lost" in body(page)))

    page.get_by_role("button", name="Retire").click()
    page.wait_for_selector("[data-testid=asset-notes-form]")
    page.locator("[data-testid=asset-notes-form]").get_by_role("button", name="Retire").click()
    page.wait_for_selector("[data-testid=asset-notes-form]", state="detached")
    check("retire: status becomes Retired", until(lambda: "Retired" in body(page)))

    page.get_by_role("button", name="Dispose").click()
    page.wait_for_selector("[data-testid=asset-notes-form]")
    page.locator("[data-testid=asset-notes-form]").get_by_role("button", name="Dispose").click()
    page.wait_for_selector("[data-testid=asset-notes-form]", state="detached")
    check("dispose: status becomes Disposed, and no more actions are offered", until(lambda: "Disposed" in body(page)) and page.get_by_role("button", name="Assign").count() == 0)

    # ================================================================ 6. the full history is there
    events = q("select event_type from asset_events where asset_id=(select id from assets where name=?) order by id", ASSET_NAME)
    types = [e[0] for e in events]
    check("db: every step left its own event, in order", types == ["assigned", "reassigned", "repair_started", "repair_completed", "returned", "transferred", "marked_lost", "retired", "disposed"], str(types))
    check("page: the history lists at least as many rows", page.locator("[data-testid=asset-event-row]").count() >= len(types))

    # ================================================================ 7. someone without any asset permission is refused
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no asset access at all", all(status_of(tctx, u) == 403 for u in ("/assets", "/assets/new", asset_url.replace(BASE, ""))))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/assets")
    mpage.wait_for_load_state("networkidle")
    check("phone: the assets list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

q("delete from asset_events where asset_id in (select id from assets where name=?)", ASSET_NAME)
q("delete from assets where name=?", ASSET_NAME)
q("delete from inventory_stores where name in (?, ?)", STORE_NAME, STORE2_NAME)

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
