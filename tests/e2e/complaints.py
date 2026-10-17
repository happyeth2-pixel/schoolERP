"""Browser test: the Complaints inbox -- a parent/student channel to raise a concern, routed to staff with
manage-complaints (plus Auditor, read-only), scoped to the handler's own branch. Localized during the
production-readiness pass (previously this module had zero translated strings and hand-rolled status pills).

  * someone with neither manage-complaints nor a parent/student role is refused the route outright
  * a parent submits a complaint about a specific child; it appears in their own "My Complaints" list
  * a branch_admin (manage-complaints) sees it in the staff inbox, opens it, resolves it with a note
  * the status badge (shared StatusBadge component) updates to "Resolved" immediately, no page reload needed
  * the parent sees the resolution note once they reopen the complaint
  * an auditor can view the same complaint but has no manage form (no save button) -- read only
  * Amharic: the page renders with translated labels, not raw i18n keys

Uses the usability-session data; writes one real complaint from the demo parent account, removed at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/complaints.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
SUBJECT = "E2E-Late-Bus-Pickup-Sentinel"


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
    q("delete from complaints where subject=?", SUBJECT)
    q("update users set locale='en' where email='principal@demo.test'")


cleanup()

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- teacher: no manage-complaints, not a parent/student -- refused outright --
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: /complaints is refused outright", status_of(tctx, "/complaints") == 403)
    check("teacher: /complaints/create is refused outright", status_of(tctx, "/complaints/create") == 403)
    tctx.close()

    # -- parent: submits a complaint about a specific child --
    pctx, pp = login(browser, "parent@demo.test")
    pp.goto(f"{BASE}/complaints/create")
    pp.wait_for_selector("#subject")
    has_child_picker = pp.locator("#student_id").count() > 0
    if has_child_picker:
        pp.locator("#student_id").select_option(index=1)
    pp.fill("#subject", SUBJECT)
    pp.fill("#body", "The school bus has been more than 30 minutes late every day this week.")
    pp.get_by_role("button", name=re.compile("submit complaint", re.I)).click()
    pp.wait_for_url(re.compile(r"/complaints$"))
    check("parent: lands back on 'My Complaints' with a success message", until(lambda: SUBJECT in body(pp)))

    complaint_id = q("select id from complaints where subject=?", SUBJECT)[0][0]

    pp.get_by_role("link", name=re.compile("^view$", re.I)).first.click()
    pp.wait_for_url(f"{BASE}/complaints/{complaint_id}")
    check("parent: the complaint detail page shows it as Open", "Open" in body(pp))
    check("parent: no 'Manage' section for a non-staff submitter", pp.get_by_role("button", name=re.compile("^save$", re.I)).count() == 0)

    # -- branch_admin: sees it in the staff inbox, resolves it with a note --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/complaints")
    check("branch_admin: the inbox lists the new complaint with who filed it", until(lambda: SUBJECT in body(hp) and "Kebede Worku" in body(hp)))

    hp.get_by_role("link", name=re.compile("^view$", re.I)).first.click()
    hp.wait_for_url(f"{BASE}/complaints/{complaint_id}")
    check("branch_admin: a 'Manage' form is offered", hp.get_by_role("button", name=re.compile("^save$", re.I)).count() == 1)

    hp.locator("select").first.select_option("resolved")
    hp.fill("textarea", "Driver has been reminded; route adjusted starting Monday.")
    hp.get_by_role("button", name=re.compile("^save$", re.I)).click()
    check("branch_admin: the status badge flips to Resolved right on this page", until(lambda: "Resolved" in body(hp)))

    # -- the parent reopens it and sees the resolution note --
    pp.goto(f"{BASE}/complaints/{complaint_id}")
    check("parent: sees the status is now Resolved", until(lambda: "Resolved" in body(pp)))
    check("parent: sees the staff's resolution note", "Driver has been reminded" in body(pp))

    # -- auditor: can view, but no write controls --
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/complaints/{complaint_id}")
    check("auditor: can see the resolved complaint", until(lambda: SUBJECT in body(ap) or "Resolved" in body(ap)))
    check("auditor: no 'Save' button -- read only", ap.get_by_role("button", name=re.compile("^save$", re.I)).count() == 0)
    actx.close()

    # -- Amharic: labels are translated, not raw i18n keys --
    q("update users set locale='am' where email='principal@demo.test'")
    hp.goto(f"{BASE}/complaints")
    check("Amharic: the inbox title is translated", "complaints." not in body(hp) and ("ቅሬታዎች" in body(hp)))
    q("update users set locale='en' where email='principal@demo.test'")
    hctx.close()
    pctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "principal@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/complaints")
    mpage.wait_for_load_state("networkidle")
    check("phone: the complaints inbox has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
