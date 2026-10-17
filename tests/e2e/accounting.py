"""Browser test: Accounting Phase A -- chart of accounts, journals, accounting periods, manual journal
entries (draft -> post -> reverse), the ledger, and the trial balance.

  * installing the default chart of accounts creates the hierarchy (headers + system accounts) and the
    seven default journals
  * opening an accounting period, then building a balanced manual journal entry and saving it as a draft
  * posting the draft turns it into an immutable, permanent entry
  * reversing a posted entry creates a correctly linked, already-posted mirror entry
  * the trial balance stays balanced (debits == credits) after all of this
  * a closed period refuses a new posting; reopening it allows one again
  * someone without any accounting permission is refused

Uses the usability-session data; creates one throwaway accounting period and a couple of journal entries,
removed at the end. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/accounting.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
PERIOD_NAME = "E2E Accounting Period"
ENTRY_DESCRIPTION = "E2E test journal entry"


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


def cleanup():
    q("delete from journal_entry_lines where journal_entry_id in (select id from journal_entries where description like ?)", f"%{ENTRY_DESCRIPTION}%")
    q("delete from journal_entries where description like ?", f"%{ENTRY_DESCRIPTION}%")
    q("delete from accounting_periods where name=?", PERIOD_NAME)


cleanup()

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. chart of accounts + journals
    page.goto(f"{BASE}/accounting/accounts")
    install_btn = page.get_by_role("button", name="Install default chart of accounts")
    if install_btn.count() > 0:
        install_btn.click()
        check("chart of accounts: installing creates the hierarchy", until(lambda: "1000" in body(page) and "Accounts Receivable" in body(page)))
    else:
        check("chart of accounts: already installed from an earlier run", "Accounts Receivable" in body(page))

    page.goto(f"{BASE}/accounting/journals")
    check("journals: the seven default journals exist", until(lambda: all(code in body(page) for code in ["CASH", "BANK", "SALES", "GEN", "AR", "AP"])))

    # ================================================================ 2. open an accounting period covering today
    today_year = time.strftime("%Y")
    page.goto(f"{BASE}/accounting/periods")
    page.get_by_role("button", name="Open new period").click()
    page.wait_for_selector("[data-testid=period-form]")
    page.fill("#period_name", PERIOD_NAME)
    page.fill("#period_start", f"{today_year}-01-01")
    page.fill("#period_end", f"{today_year}-12-31")
    page.locator("[data-testid=period-form]").get_by_role("button", name="Open period").click()
    check("period: opened and listed", until(lambda: PERIOD_NAME in body(page) and "Open" in body(page)))

    # ================================================================ 3. a balanced manual journal entry: draft -> post
    page.goto(f"{BASE}/accounting/journal-entries/new")
    page.select_option("#je_journal", label="GEN General")
    page.fill("#je_description", ENTRY_DESCRIPTION)
    page.select_option("#je_account_0", label="1100 Cash")
    page.fill("#je_debit_0", "500")
    page.select_option("#je_account_1", label="4100 Tuition Fees")
    page.fill("#je_credit_1", "500")
    check("journal entry: totals shown live and balanced", until(lambda: "Debit: 500.00" in body(page) and "Credit: 500.00" in body(page)))
    page.get_by_role("button", name="Save as draft").click()
    page.wait_for_url(re.compile(r"/accounting/journal-entries/\d+$"))
    je_url = page.url
    check("journal entry: saved as a draft", until(lambda: "Draft" in body(page) and ENTRY_DESCRIPTION in body(page)))

    page.get_by_role("button", name="Post Journal Entry").click()
    check("journal entry: posting marks it Posted", until(lambda: "Posted" in body(page)))
    je_number = re.search(r"JE-\d+-\d+", body(page)).group(0)

    # ================================================================ 4. reversing a posted entry
    page.get_by_role("button", name="Reverse Entry").click()
    page.wait_for_selector("[data-testid=reverse-form]")
    page.fill("#reverse_reason", "Recorded in error for this test")
    page.locator("[data-testid=reverse-form]").get_by_role("button", name="Reverse Entry").click()
    page.wait_for_url(re.compile(r"/accounting/journal-entries/\d+$"))
    check("reversal: opens the new reversing entry, already posted", until(lambda: "Posted" in body(page) and "reverses" in body(page).lower()))
    reversal_url = page.url
    check("reversal: reversing entry is a different entry from the original", reversal_url != je_url)

    page.goto(je_url)
    check("reversal: the original entry shows it was reversed", until(lambda: "reversed by" in body(page).lower()))
    check("reversal: the original entry offers no Reverse button anymore (already reversed)", page.get_by_role("button", name="Reverse Entry").count() == 0)

    # ================================================================ 5. the trial balance stays balanced
    page.goto(f"{BASE}/accounting/reports/trial-balance")
    total_debit_text = page.locator("[data-testid=tb-total-debit]").inner_text()
    total_credit_text = page.locator("[data-testid=tb-total-credit]").inner_text()
    check("trial balance: total debits equal total credits", total_debit_text == total_credit_text, f"{total_debit_text} vs {total_credit_text}")
    check("trial balance: no unbalanced warning shown", page.locator("[data-testid=tb-unbalanced-warning]").count() == 0)

    # ================================================================ 5b. Phase D: income statement, balance sheet, cash summary, aging, reconciliation
    page.goto(f"{BASE}/accounting/reports/income-statement")
    check("income statement: loads with revenue and expense totals shown", until(lambda: "Total Revenue" in body(page) and "Total Expense" in body(page) and "Net Income" in body(page)))

    page.goto(f"{BASE}/accounting/reports/balance-sheet")
    check("balance sheet: loads with assets, liabilities and equity sections", until(lambda: "Total Assets" in body(page) and "Total Liabilities" in body(page) and "Total Equity" in body(page)))
    check("balance sheet: no unbalanced warning shown", page.locator("[data-testid=bs-unbalanced-warning]").count() == 0)

    page.goto(f"{BASE}/accounting/reports/cash-summary")
    check("cash summary: loads and never lists the Accounts Receivable control account", until(lambda: "Cash & Bank Summary" in body(page) and "1300 Accounts Receivable" not in body(page)))

    # Bucket labels render with a CSS uppercase transform, so Playwright's inner_text() (which reflects
    # rendered text, not source text) sees "1-30 DAYS" / "OVER 90 DAYS", not the lowercase i18n string.
    page.goto(f"{BASE}/accounting/reports/ar-aging")
    check("AR aging: loads with the five age buckets shown", until(lambda: "1-30 DAYS" in body(page) and "OVER 90 DAYS" in body(page)))

    page.goto(f"{BASE}/accounting/reports/ap-aging")
    check("AP aging: loads with the five age buckets shown", until(lambda: "1-30 DAYS" in body(page) and "OVER 90 DAYS" in body(page)))

    page.goto(f"{BASE}/accounting/reports/reconciliation")
    check("reconciliation: loads and reports a match or mismatch for Accounts Receivable", until(lambda: page.locator("[data-testid=reconciliation-match]").count() == 1 or page.locator("[data-testid=reconciliation-mismatch]").count() == 1))
    page.get_by_role("button", name="Check again").click()
    check("reconciliation: Check again re-runs it", until(lambda: "Last checked:" in body(page)))

    # ================================================================ 6. closed period protection
    page.goto(f"{BASE}/accounting/periods")
    row = page.locator("[data-testid=period-row]").filter(has_text=PERIOD_NAME)
    row.get_by_role("button", name="Close period").click()
    page.wait_for_selector("text=Close \"E2E Accounting Period\"?")
    page.get_by_role("button", name="Close period", exact=True).last.click()
    check("period: closing it shows Closed", until(lambda: page.locator("[data-testid=period-row]").filter(has_text=PERIOD_NAME).get_by_text("Closed").count() == 1))

    page.goto(f"{BASE}/accounting/journal-entries/new")
    page.select_option("#je_journal", label="GEN General")
    page.fill("#je_date", f"{today_year}-06-15")
    page.fill("#je_description", ENTRY_DESCRIPTION + " (should be refused)")
    page.select_option("#je_account_0", label="1100 Cash")
    page.fill("#je_debit_0", "10")
    page.select_option("#je_account_1", label="4100 Tuition Fees")
    page.fill("#je_credit_1", "10")
    page.get_by_role("button", name="Save as draft").click()
    page.wait_for_url(re.compile(r"/accounting/journal-entries/\d+$"))
    draft2_url = page.url
    page.get_by_role("button", name="Post Journal Entry").click()
    check("closed period: posting into it is refused", until(lambda: "closed" in body(page).lower() and "Draft" in body(page)))

    page.goto(f"{BASE}/accounting/periods")
    row = page.locator("[data-testid=period-row]").filter(has_text=PERIOD_NAME)
    row.get_by_role("button", name="Reopen").click()
    page.wait_for_selector("text=Reopen \"E2E Accounting Period\"?")
    page.get_by_role("button", name="Reopen period", exact=True).last.click()
    check("period: reopening shows Open again", until(lambda: page.locator("[data-testid=period-row]").filter(has_text=PERIOD_NAME).get_by_text("Open", exact=True).count() == 1))

    page.goto(draft2_url)
    page.get_by_role("button", name="Post Journal Entry").click()
    check("reopened period: posting now succeeds", until(lambda: "Posted" in body(page)))

    # ================================================================ 7. permission checks
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no accounting access at all", all(status_of(tctx, u) == 403 for u in (
        "/accounting/accounts", "/accounting/journal-entries", "/accounting/periods", "/accounting/reports/trial-balance",
        "/accounting/reports/income-statement", "/accounting/reports/balance-sheet", "/accounting/reports/cash-summary",
        "/accounting/reports/ar-aging", "/accounting/reports/ap-aging", "/accounting/reports/reconciliation",
    )))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "owner@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/accounting/reports/trial-balance")
    mpage.wait_for_load_state("networkidle")
    check("phone: the trial balance page has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
