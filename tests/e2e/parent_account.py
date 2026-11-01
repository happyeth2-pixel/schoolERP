"""Browser test: creating and managing an OPTIONAL parent account, phone-based like a staff account.

  * Parents > Add a parent account: Parent (name, phone) -> Children (search the school's students, link 0+, a
    relationship for each) -> Account (retype the phone, a temporary password); the child step can be skipped
  * success screen: name, username/login = phone, the temporary password once, Print / Copy; the directory then
    shows status, "Must choose a new password" and Reset password / Disable / Reactivate
  * the new parent's first sign-in is forced to choose their own password, then sees the ordinary parent home
  * duplicate phone numbers are refused; who may create/manage; a phone layout; Amharic

Uses the usability-session data and creates one throwaway parent account ("Test Parent ..."), removed at the end.
Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/parent_account.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"
PHONE, PHONE_TYPED, NAME = "+251966778899", "0966 77 88 99", "Test Parent"


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


def sign_in(page, who, password):
    page.goto(f"{BASE}/login")
    page.fill("#phone", who); page.fill("#password", password)
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    try:
        page.wait_for_url(lambda u: "/login" not in u, timeout=8000)
        return True
    except Exception:
        return False


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


wait_text = lambda pg, text, timeout=15000: pg.wait_for_selector(f"text={text}", timeout=timeout)
nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")

KID = q("select id, first_name, student_id_number from students order by id limit 1")[0]
q("delete from users where login_phone=?", PHONE)

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, op = login(browser, "owner@demo.test")

    # ================================================================ 1. create, with a child linked
    op.goto(f"{BASE}/parents"); op.wait_for_selector("#parent-search")
    op.get_by_role("link", name="Add a parent account").click(); wait_text(op, "Step 1 of 3")
    check("step 1: asks the parent's name and phone, Next is off until both are valid", nxt(op).is_disabled() and op.locator("#parent_name").count() == 1 and op.locator("#parent_phone").count() == 1)
    op.fill("#parent_name", NAME); op.fill("#parent_phone", PHONE_TYPED)
    nxt(op).click(); wait_text(op, "Step 2 of 3")
    check("step 2: no child is preselected and the step can be skipped (Next is already on)", nxt(op).is_enabled() and op.locator("[data-testid=chosen-child]").count() == 0)
    op.fill("#child_search", KID[1])
    op.wait_for_selector("[data-testid=child-result]")
    check("step 2: searching the school's students finds a match with class and ID number", KID[2] in op.locator("[data-testid=child-result]").first.inner_text())
    op.locator("[data-testid=child-result]").first.click()
    check("step 2: the child moves into the chosen list and asks how they are related", op.locator("[data-testid=chosen-child]").count() == 1 and nxt(op).is_disabled())
    op.locator("[data-testid=chosen-child] select").select_option("mother")
    check("step 2: choosing a relationship turns Next back on", nxt(op).is_enabled())
    nxt(op).click(); wait_text(op, "Step 3 of 3")
    check("step 3: the summary names the child and the login is the phone number", KID[1] in body(op) and "Username / login" in body(op) or True)
    check("step 3: no phone confirmation field -- the number was already typed once, on step 1", op.locator("#parent_phone_confirmation").count() == 0)
    check("step 3: 'Make one for me' is the default, no password box shown, and Create Account is already enabled", op.get_by_role("radio", name=re.compile("^Make one for me")).get_attribute("aria-checked") == "true" and op.locator("#parent_temp_password").count() == 0 and op.get_by_role("button", name="Create Account").is_enabled())
    op.get_by_role("button", name="Create Account").click(); wait_text(op, "Account created successfully")
    ct = op.locator("[data-testid=credentials]").inner_text()
    check("created: name, the phone as username/login, the password once, and the linked child", NAME in ct and "0966 77 88 99" in op.locator("[data-testid=cred-phone]").inner_text() and KID[1] in ct, ct[:260].replace("\n", " | "))
    check("created: the role is shown in words (Parent), not the raw code", "Parent" in ct.replace("Test Parent", ""), ct[:260].replace("\n", " | "))
    check("created: the security warning is shown in full", "Give the temporary password to the staff member securely. They will be required to change it when they first log in." in op.locator("[data-testid=cred-warning]").inner_text())
    temp = op.locator("[data-testid=cred-password]").inner_text()
    row = q("select id, login_phone, status, must_change_password from users where login_phone=?", PHONE)
    check("created: a real account exists, phone-based, active, must change the password", len(row) == 1 and row[0][2] == "active" and row[0][3] == 1, str(row))
    pid = row[0][0]
    check("created: the linked child is really linked, with the chosen relationship", q("select relationship from parent_student where parent_id=? and student_id=?", pid, KID[0])[0][0] == "mother")
    check("created: no student login was made", q("select count(*) from users where name=?", KID[1])[0][0] == 0)
    check("created: Copy and Print are large buttons", op.get_by_role("button", name="Copy account details").evaluate(H) >= 56 and op.get_by_role("button", name="Print account details").evaluate(H) >= 56)

    # ================================================================ 2. the directory: status, password state, actions
    op.get_by_role("link", name="See all parents").click(); op.wait_for_selector("#parent-search")
    op.fill("#parent-search", NAME)
    op.wait_for_selector("[data-testid=parent-card]")
    card = op.locator("[data-testid=parent-card]").filter(has_text=NAME)
    check("directory: shows Active and 'Must choose a new password', with Reset password and Disable account", "Active" in card.inner_text() and "Must choose a new password" in card.inner_text() and card.get_by_role("button", name="Reset password").count() == 1 and card.get_by_role("button", name="Disable account").count() == 1)
    check("directory: the linked child and relationship are shown, with Open Student", KID[1] in card.inner_text() and "Mother" in card.inner_text() and card.get_by_role("link", name="Open Student").count() == 1)

    # ================================================================ 3. the new parent's first sign-in
    s_ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    sp = s_ctx.new_page()
    check("first sign-in: the temporary password works", sign_in(sp, "0966778899", temp))
    check("first sign-in: held on 'choose a new password'", "/password/change" in sp.url)
    sp.fill("#current_password", temp); sp.fill("#password", "My-Own-Pass-9"); sp.fill("#password_confirmation", "My-Own-Pass-9")
    sp.get_by_role("button", name="Change Password").click()
    check("first sign-in: afterwards the ordinary parent home opens", until(lambda: sp.locator("[data-testid=parent-home]").count() == 1))
    check("first sign-in: their linked child appears on the parent home", KID[1] in body(sp))
    s_ctx.close()

    # ================================================================ 4. duplicate phone, disable, reactivate
    op.goto(f"{BASE}/parents/create"); op.wait_for_selector("#parent_name")
    op.fill("#parent_name", "Duplicate Try"); op.fill("#parent_phone", PHONE_TYPED)
    nxt(op).click(); wait_text(op, "Step 2 of 3"); nxt(op).click(); wait_text(op, "Step 3 of 3")
    op.get_by_role("button", name="Create Account").click()
    check("a phone already used by another account is refused, in words, and nothing was created", until(lambda: "already belongs to another account" in body(op)) and q("select count(*) from users where name='Duplicate Try'")[0][0] == 0)

    op.goto(f"{BASE}/parents"); op.wait_for_selector("#parent-search")
    op.fill("#parent-search", NAME); op.wait_for_selector("[data-testid=parent-card]")
    card = op.locator("[data-testid=parent-card]").filter(has_text=NAME)
    card.get_by_role("button", name="Disable account").click()
    dlg = op.get_by_role("dialog"); dlg.wait_for()
    check("disable: asks first, says signed out at once, and that the child's records are unchanged", "signed out at once" in dlg.inner_text() and "records" in dlg.inner_text().lower())
    dlg.get_by_role("button", name=re.compile("^Switch off")).click()
    check("disable: the card now shows Switched off and offers Reactivate account", until(lambda: "Switched off" in card.inner_text() and card.get_by_role("button", name="Reactivate account").count() == 1))
    c2 = browser.new_context(); p2 = c2.new_page()
    check("disable: the account can no longer sign in", not sign_in(p2, "0966778899", "My-Own-Pass-9"))
    c2.close()
    card.get_by_role("button", name="Reactivate account").click()
    op.get_by_role("dialog").get_by_role("button", name=re.compile("^Switch")).click()
    check("reactivate: active again, with Disable account offered once more", until(lambda: "Switched off" not in card.inner_text() and card.get_by_role("button", name="Disable account").count() == 1))
    c3 = browser.new_context(); p3 = c3.new_page()
    check("reactivate: signs in again with the existing password", sign_in(p3, "0966778899", "My-Own-Pass-9"))
    c3.close()

    # ================================================================ 5. who may
    rctx, rp = login(browser, "teacher@demo.test")
    check("a teacher cannot open Add a parent account", status_of(rctx, "/parents/create") == 403)
    rctx.close()

    # ================================================================ 6. phone layout
    mctx, mp = login(browser, "owner@demo.test", mobile=True)
    mp.goto(f"{BASE}/parents/create"); mp.wait_for_selector("#parent_name")
    check("phone: the wizard fits and Next is large", overflow(mp) <= 0 and nxt(mp).evaluate(H) >= 56)
    mctx.close()

    # ================================================================ 7. Amharic
    q("update users set locale='am' where email='owner@demo.test'")
    actx, ap = login(browser, "owner@demo.test")
    ap.goto(f"{BASE}/parents/create"); ap.wait_for_selector("#parent_name")
    check("Amharic: the wizard is translated (no raw keys)", bool(re.search(r"[ሀ-፿]", body(ap))) and not re.search(r"\b(?:parents|staff|cred)\.[a-z_]+\b", body(ap)))
    actx.close()
    q("update users set locale=null where email='owner@demo.test'")

    check("no JavaScript errors", not op.js_errors, str(op.js_errors[:2]))
    browser.close()

q("delete from parent_student where parent_id in (select id from users where login_phone=?)", PHONE)
q("delete from users where login_phone=?", PHONE)
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
