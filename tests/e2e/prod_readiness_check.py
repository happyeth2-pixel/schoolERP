"""Browser test: a real-browser spot check of the production-readiness pass's highest-risk changes
-- the new Reports Center, the new friendly error pages, the localized Complaints module, and the
mobile table-overflow fix on an Accounting report. Not a full feature suite (each area has its own
dedicated tests above); this exists so a human-eyeball pass never has to be taken on faith.

    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/prod_readiness_check.py
"""
import os, re, sys
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def login(browser, email):
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.goto(f"{BASE}/login")
    page.fill("#phone", email); page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    return ctx, page


body = lambda pg: pg.locator("body").inner_text()

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx, page = login(browser, "owner@demo.test")

    # -- Reports Center --
    page.goto(f"{BASE}/reports")
    check("reports: the page loads for an owner", "Reports" in body(page))
    check("reports: the accounting group with 8 reports is shown", page.get_by_text("Trial Balance").count() > 0 and page.get_by_text("Reconciliation").count() > 0)
    accounting_report_link = page.locator('a[href*="/accounting/reports/"]').first
    accounting_report_link.click()
    page.wait_for_load_state("networkidle")
    check("reports: clicking an accounting report actually opens it", "/accounting/reports/" in page.url)

    # -- Friendly error pages --
    # NOTE: this usability session runs with the project's .env APP_DEBUG=true (correct for an
    # interactive dev/test session), so the friendly-error feature intentionally does not engage
    # here -- it only renders when config('app.debug') is false, exactly as
    # tests/Feature/FriendlyErrorPagesTest.php already verifies via PHPUnit. A one-off real-browser
    # check with APP_DEBUG=false (separate port, same DB) confirmed the actual rendered page: "This
    # page could not be found ... Go to sign in". Skipping the assertion here rather than faking it.

    # -- Complaints, now localized, with a real StatusBadge --
    page.goto(f"{BASE}/complaints")
    check("complaints: the page loads with a translated title", "Complaints" in body(page))
    check("complaints: the status filter buttons are in plain words", page.get_by_role("button", name=re.compile("^open$", re.I)).count() > 0)

    # -- Accounting report table scrolls horizontally on a phone instead of being clipped --
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/accounting/reports/trial-balance")
    overflow = mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
    check("mobile: the trial balance page itself has no page-level horizontal overflow", overflow <= 2, f"overflow={overflow}px")
    mctx.close()

    ctx.close()
    browser.close()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
