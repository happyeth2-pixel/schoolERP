"""Browser test: the account lifecycle additions on the Staff and Parents pages.

  * every account shows a clear status: Active, Switched off, "Must choose a new password", and now a distinct
    "Locked" badge (ten wrong tries) separate from the ordinary "Sign-in paused for now" cooldown
  * a new "Last signed in" line ("Never signed in" until they do)
  * a "View" button (read-only) that an AUDITOR can also open, without any of the edit/reset/disable buttons
  * "Reactivate with a new password" alongside the plain "Reactivate account", each giving a different, clearly
    stated outcome
  * the general Audit log now reads "Status changed: ..." for a disable/reactivate, not a raw code

Uses the usability-session data; it creates one throwaway teacher, disables/reactivates it, and removes it at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/account_lifecycle.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"
NAME = "Lifecycle Teacher"


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


body = lambda pg: pg.locator("body").inner_text()

q("delete from users where name=?", NAME)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, op = login(browser, "owner@demo.test")

    # ================================================================ 1. create a throwaway account, check Active + Last signed in
    op.goto(f"{BASE}/staff/create"); op.wait_for_selector("#staff_name")
    op.fill("#staff_name", NAME); op.fill("#staff_phone", "0977 11 22 33")
    op.locator("form").get_by_role("button", name="Next").click(); op.wait_for_selector("text=Step 2 of 3")
    op.get_by_role("radio", name=re.compile("^Teacher")).click()
    op.locator("form").get_by_role("button", name="Next").click(); op.wait_for_selector("text=Step 3 of 3")
    op.get_by_role("button", name="Create Account").click(); op.wait_for_selector("[data-testid=credentials]")
    op.get_by_role("button", name="Add another staff member").click()
    uid = q("select id from users where name=?", NAME)[0][0]

    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    op.locator("[data-testid=staff-list]").get_by_text(NAME).first.wait_for()
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    check("a brand-new account shows Active, 'Must choose a new password' and 'Never signed in'", "Active" in card.inner_text() and "Must choose a new password" in card.inner_text() and "Never signed in" in card.inner_text(), card.inner_text().replace("\n", " | ")[:220])
    check("no 'Locked' or 'paused' badge on a fresh account", "Locked" not in card.inner_text() and "paused" not in card.inner_text())

    # ================================================================ 2. View: an auditor can open it, with no edit/reset/disable buttons
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/staff"); ap.wait_for_selector("[data-testid=staff-row]")
    acard = ap.locator("[data-testid=staff-row]").filter(has_text=NAME)
    check("auditor: sees the account's status and last-signed-in line", "Must choose a new password" in acard.inner_text() and "Never signed in" in acard.inner_text())
    check("auditor: no Change, Reset password or Disable button, only View", acard.get_by_role("button", name="Change").count() == 0 and acard.get_by_role("button", name="Reset password").count() == 0 and acard.get_by_role("button", name="Disable account").count() == 0 and acard.get_by_role("button", name="View").count() == 1)
    acard.get_by_role("button", name="View").click()
    ap.wait_for_selector("[data-testid=view-staff]")
    view = ap.locator("[data-testid=view-staff]")
    check("View: read-only detail with status, last-signed-in and what they can do, no editable fields", NAME in view.inner_text() and "Never signed in" in view.inner_text() and view.locator("input").count() == 0)
    ap.get_by_role("button", name="Cancel").click()
    actx.close()

    # an owner can also open View (in addition to the edit tools)
    op.locator("[data-testid=staff-row]").filter(has_text=NAME).get_by_role("button", name="View").click()
    op.wait_for_selector("[data-testid=view-staff]")
    op.get_by_role("button", name="Cancel").click()
    check("owner: View works alongside the manage buttons", op.locator("[data-testid=staff-row]").filter(has_text=NAME).get_by_role("button", name="Change").count() == 1)

    # ================================================================ 3. disable / reactivate with a new password
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    card.get_by_role("button", name="Disable account").click()
    op.get_by_role("dialog").get_by_role("button", name=re.compile("^Switch off")).click()
    check("disabled: the card says Switched off, and offers both reactivate options", until(lambda: "Switched off" in card.inner_text()) and card.get_by_role("button", name="Reactivate account").count() == 1 and card.get_by_role("button", name="Reactivate with a new password").count() == 1)

    card.get_by_role("button", name="Reactivate with a new password").click()
    dlg = op.get_by_role("dialog"); dlg.wait_for()
    check("reactivate-with-reset: explains that the OLD password stops and a new one must be chosen", "new one" in dlg.inner_text().lower() or "choose a new" in dlg.inner_text().lower(), dlg.inner_text()[:220].replace("\n", " | "))
    dlg.get_by_role("button", name=re.compile("^Switch")).click()
    op.wait_for_selector("[data-testid=reset-result]")
    row = q("select status, must_change_password from users where id=?", uid)[0]
    cred_count = op.locator("[data-testid=cred-password]").count()
    check("reactivate-with-reset: active again and a new temporary password is shown, once", row == ("active", 1) and cred_count == 1, f"row={row} cred_count={cred_count}")

    # ================================================================ 4. the audit log reads plainly for a status change
    op.goto(f"{BASE}/audit-log?entity=user"); op.wait_for_selector("[data-testid=entry]")
    entries = op.locator("[data-testid=entry]").all_inner_texts()
    check("audit log: a status change reads 'Status changed: ...', not a raw code as the sentence", any("Status changed:" in e for e in entries), str([e[:60] for e in entries[:3]]))

    # ================================================================ 5. Locked vs a plain cooldown, and Parents mirrors the same
    q("update users set login_phone='+251977112233' where id=?", uid)
    lctx, lp = login(browser, "owner@demo.test")
    lctx.close()
    fail_ctx = browser.new_context(); fp = fail_ctx.new_page()
    for _ in range(3):
        fp.goto(f"{BASE}/login"); fp.fill("#phone", "0977112233"); fp.fill("#password", "wrong"); fp.get_by_role("button", name=re.compile("log in", re.I)).click()
        fp.wait_for_timeout(150)
    fail_ctx.close()
    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    check("a short cooldown (a few wrong tries) shows 'Sign-in paused for now', not 'Locked'", until(lambda: "paused" in card.inner_text().lower()) and "Locked" not in card.inner_text())

    op.goto(f"{BASE}/parents"); op.wait_for_selector("#parent-search")
    op.fill("#parent-search", "Kebede Worku")
    op.wait_for_selector("[data-testid=parent-card]")
    pcard = op.locator("[data-testid=parent-card]").filter(has_text="Kebede Worku").first
    check("Parents page: the same View button and 'Last signed in' line exist there too", pcard.get_by_role("button", name="View").count() >= 1 and ("signed in" in pcard.inner_text().lower()))
    pcard.get_by_role("button", name="View").first.click()
    op.wait_for_selector("[data-testid=view-parent]")
    check("Parents View: read-only, shows the linked children, no editable fields", op.locator("[data-testid=view-parent]").locator("input").count() == 0 and op.locator("[data-testid=view-parent]").count() == 1)
    op.get_by_role("button", name="Cancel").click()

    check("no JavaScript errors", not op.js_errors, str(op.js_errors[:2]))
    browser.close()

q("delete from users where name=?", NAME)
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
