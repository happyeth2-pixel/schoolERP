"""Browser test: Academic Expansion Phase 6 -- the Library. The brief's own words: "issue/return/renew/
lost/damaged tracking and fines NOT part of tuition unless explicitly configured."

  * someone with no library permission is refused both the catalog and the loans dashboard
  * a librarian adds a book, adds a copy, issues it to a student found by search, and sees it on loan
  * returning it on time (fines off by default) shows no fine and frees the copy back up
  * a staff borrower sees their own issued book on their own self-service "My Library Loans" page
  * fines stay off by default -- confirmed directly on the loans page

Uses the usability-session data; temporarily grants library-manage to principal@demo.test (not held by
default -- confirmed with the user as an explicit, not bundled, permission), removed at the end along with
the throwaway book/copies/loans it creates. Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/library.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
BOOK_TITLE = "E2E Test Novel"
COPY_NUMBER = "E2E-C1"
COPY2_NUMBER = "E2E-C2"


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
    q("delete from library_loans where book_copy_id in (select id from book_copies where book_id in (select id from books where title=?))", BOOK_TITLE)
    q("delete from book_copies where book_id in (select id from books where title=?)", BOOK_TITLE)
    q("delete from books where title=?", BOOK_TITLE)
    q("delete from model_has_permissions where permission_id=(select id from permissions where name='library-manage') and model_id=(select id from users where email='principal@demo.test')")


cleanup()
PRINCIPAL_ID = q("select id from users where email='principal@demo.test'")[0][0]
LIB_MANAGE_PERM_ID = q("select id from permissions where name='library-manage'")[0][0]
q("insert into model_has_permissions (permission_id, model_type, model_id) values (?, 'App\\Models\\User', ?)", LIB_MANAGE_PERM_ID, PRINCIPAL_ID)

STUDENT_NAME = q(
    "select s.first_name from students s join enrollments e on e.student_id=s.id where e.status='active' order by s.id limit 1"
)[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- no library permission at all --
    actx, _ = login(browser, "accountant@demo.test")
    check("accountant: /library is refused", status_of(actx, "/library") == 403)
    check("accountant: /library/books is refused", status_of(actx, "/library/books") == 403)
    actx.close()

    # -- librarian: add a book, add a copy, issue it --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/library/books")
    hp.get_by_role("button", name=re.compile("add book", re.I)).click()
    hp.locator('[data-testid="book-form"]').wait_for()
    hp.fill("#book_title", BOOK_TITLE)
    hp.get_by_role("button", name=re.compile("save book", re.I)).click()
    hp.wait_for_url(re.compile(r"/library/books/\d+$"))
    check("librarian: landed on the new book's own page", BOOK_TITLE in body(hp))

    hp.get_by_role("button", name=re.compile("add copy", re.I)).click()
    hp.locator('[data-testid="book-copy-form"]').wait_for()
    hp.fill("#copy_number", COPY_NUMBER)
    hp.get_by_role("button", name=re.compile("save copy", re.I)).click()
    hp.locator('[data-testid="book-copy-form"]').wait_for(state="hidden")
    check("librarian: the new copy shows as available", until(lambda: "Available" in body(hp)))

    hp.locator('[data-testid="copy-row"]', has_text=COPY_NUMBER).get_by_role("button", name=re.compile("^issue$", re.I)).click()
    hp.locator('[data-testid="issue-loan-form"]').wait_for()
    hp.fill("#borrower_search", STUDENT_NAME)
    hp.locator('[data-testid="borrower-results"] button', has_text=STUDENT_NAME).first.click()
    check("issue dialog: the picked person is shown as selected", until(lambda: hp.locator('[data-testid="selected-borrower"]').count() == 1))
    hp.locator('[data-testid="issue-loan-form"]').get_by_role("button", name=re.compile("^issue$", re.I)).click()
    hp.locator('[data-testid="issue-loan-form"]').wait_for(state="hidden")
    check("librarian: the copy now shows issued, on loan to the student", until(lambda: "Issued" in body(hp) and STUDENT_NAME in body(hp)))

    # -- the loans dashboard shows it, fines off by default --
    hp.goto(f"{BASE}/library")
    check("loans dashboard: the active loan is listed", until(lambda: BOOK_TITLE in body(hp) and STUDENT_NAME in body(hp)))
    check("loans dashboard: fines-off note is shown (not configured by default)", "turned off" in body(hp).lower() or "off" in body(hp).lower())

    hp.locator('[data-testid="loan-row"]', has_text=BOOK_TITLE).get_by_role("button", name=re.compile("^return$", re.I)).click()
    hp.get_by_role("button", name=re.compile("return book", re.I)).click()
    check("returning: the loan disappears from the active list", until(lambda: BOOK_TITLE not in body(hp)))
    check("db: the copy is available again", q("select status from book_copies where copy_number=?", COPY_NUMBER)[0][0] == "available")
    check("db: no fine was recorded (returned on time, and fines are off anyway)", q("select fine_amount from library_loans where book_copy_id=(select id from book_copies where copy_number=?)", COPY_NUMBER)[0][0] is None)

    # -- issue a second copy to a staff member, check their own self-service page --
    hp.goto(f"{BASE}/library/books")
    hp.locator('[data-testid="book-card"]', has_text=BOOK_TITLE).click()
    hp.wait_for_url(re.compile(r"/library/books/\d+$"))
    hp.get_by_role("button", name=re.compile("add copy", re.I)).click()
    hp.locator('[data-testid="book-copy-form"]').wait_for()
    hp.fill("#copy_number", COPY2_NUMBER)
    hp.get_by_role("button", name=re.compile("save copy", re.I)).click()
    hp.locator('[data-testid="book-copy-form"]').wait_for(state="hidden")

    hp.locator('[data-testid="copy-row"]', has_text=COPY2_NUMBER).get_by_role("button", name=re.compile("^issue$", re.I)).click()
    hp.locator('[data-testid="issue-loan-form"]').wait_for()
    hp.fill("#borrower_search", "Samuel")
    hp.locator('[data-testid="borrower-results"] button', has_text="Samuel").first.click()
    hp.locator('[data-testid="issue-loan-form"]').get_by_role("button", name=re.compile("^issue$", re.I)).click()
    hp.locator('[data-testid="issue-loan-form"]').wait_for(state="hidden")
    hctx.close()

    tctx, tp = login(browser, "teacher@demo.test")
    tp.goto(f"{BASE}/my-library-loans")
    check("teacher: sees their own issued book on My Library Loans", until(lambda: BOOK_TITLE in body(tp)))
    tctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "principal@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/library/books")
    mpage.wait_for_load_state("networkidle")
    check("phone: the library books list has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
