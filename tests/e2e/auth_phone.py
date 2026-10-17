"""Browser test: signing in with a phone number, temporary passwords and password resets, in a real browser.

  * The sign-in page: a phone number (not an email), a Show password button, the school office as the answer to a
    forgotten password (no email link), large controls, Amharic, a phone-sized screen
  * The same number typed 09 / 07 / +251 / with spaces / without the 0 signs in the same person; a wrong password
    and an unknown number get the same answer; an email-only account still signs in with its email; the throttle
    counts a number however it is typed and does not hold up anyone else
  * An administrator adds a staff member (phone + temporary password shown once), the person signs in with the
    temporary password, can do nothing but choose a new password, and then the temporary one is dead
  * An administrator resets a password: asks first, shows the new temporary password once, the person must change it

Uses the usability-session data (demo accounts have phone numbers 0911000001..0911000010) and adds staff. Only run it
against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/auth_phone.py
"""
import os, re, sqlite3, sys, time
from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(encoding="utf-8")
BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"


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
        time.sleep(0.4)
    return False


body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")


def new_page(browser, mobile=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    ctx = browser.new_context(**kw)
    page = ctx.new_page()
    page.js_errors = []
    page.on("pageerror", lambda e: page.js_errors.append(str(e)))
    return ctx, page


def try_login(page, who, password="password"):
    page.goto(f"{BASE}/login"); page.wait_for_selector("#phone")
    page.fill("#phone", who); page.fill("#password", password)
    page.get_by_role("button", name="Log in").click()
    page.wait_for_load_state("networkidle")


def signed_in(page):
    return "/login" not in page.url


def leaves(page, fragment, timeout=20000):
    """True once the address no longer contains `fragment` (the sync API only learns of navigation while it is being called)."""
    try:
        page.wait_for_url(lambda u: fragment not in u, timeout=timeout)
        return True
    except Exception:
        return False


for e in ("owner", "principal", "teacher", "accountant"):
    q("update users set locale=null where email=?", f"{e}@demo.test")
q("delete from users where phone in ('+251711223344')")

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ================================================================ 1. the sign-in page
    ctx, page = new_page(browser)
    page.goto(f"{BASE}/login"); page.wait_for_selector("#phone")
    check("sign-in: 'School ERP', Phone number, Password, Show password, Log in", all(w in body(page) for w in ("School ERP", "Phone number", "Password", "Show password", "Log in")))
    check("sign-in: it is a phone field (no email field, phone keyboard on a phone)", page.locator("#email").count() == 0 and page.locator("#phone").get_attribute("type") == "tel" and page.locator("#phone").get_attribute("inputmode") == "tel")
    check("sign-in: an example number is shown", "0911 22 33 44" in page.locator("#phone-hint").inner_text())
    check("sign-in: a forgotten password is answered by the school office, with no email link", "Ask the school office" in page.locator("[data-testid=forgot-note]").inner_text() and page.locator("a[href*='forgot-password']").count() == 0)
    check("sign-in: the controls are large", page.get_by_role("button", name="Log in").evaluate(H) >= 56 and page.locator("#phone").evaluate(H) >= 56 and page.get_by_role("button", name="Show password").evaluate(H) >= 44)
    page.fill("#password", "abc12345")
    check("sign-in: the password is hidden, Show password reveals it and says Hide password", page.locator("#password").get_attribute("type") == "password")
    page.get_by_role("button", name="Show password").click()
    check("sign-in: ...revealed", page.locator("#password").get_attribute("type") == "text" and page.get_by_role("button", name="Hide password").count() == 1)
    page.get_by_role("button", name="Hide password").click()
    check("sign-in: ...and hidden again", page.locator("#password").get_attribute("type") == "password")
    ctx.close()

    # ================================================================ 2. every everyday way of typing a number
    for typed, who in (("0911000001", "owner"), ("+251911000002", "principal"), ("911000003", "teacher"), ("0911 00 00 04", "accountant"), ("+251 911 000 001", "owner"), ("00251911000002", "principal")):
        ctx, page = new_page(browser)
        try_login(page, typed)
        check(f"same person, typed {typed!r}: signs in", signed_in(page), page.url)
        ctx.close()

    ctx, page = new_page(browser)
    try_login(page, "owner@demo.test")
    check("an account with an email keeps signing in with it while it has no phone yet", signed_in(page))
    ctx.close()

    # ================================================================ 3. wrong things look the same
    ctx, page = new_page(browser)
    try_login(page, "0911000001", "wrong-password")
    msg1 = page.locator("#phone-error").inner_text() if page.locator("#phone-error").count() else ""
    check("wrong password: stays on the page with a plain message beside the phone field", not signed_in(page) and "phone number or password is incorrect" in msg1, msg1)
    try_login(page, "0977123456", "wrong-password")
    msg2 = page.locator("#phone-error").inner_text() if page.locator("#phone-error").count() else ""
    check("unknown number: exactly the same message (nothing reveals which accounts exist)", msg2 == msg1 and msg2 != "")
    try_login(page, "hello", "x")
    check("something that is not a number at all: the same message again", page.locator("#phone-error").inner_text() == msg1)
    check("the sign-in page itself says where to get help with a forgotten password", "Ask the school office" in page.locator("[data-testid=forgot-note]").inner_text())
    ctx.close()

    # ================================================================ 4. an administrator adds a staff member
    octx, op = new_page(browser)
    try_login(op, "0911000001")
    op.goto(f"{BASE}/staff/create"); op.wait_for_selector("#staff_name")
    op.fill("#staff_name", "Tsion Bekele"); op.fill("#staff_phone", "0711 22 33 44")
    op.locator("form").get_by_role("button", name="Next").click(); op.wait_for_selector("text=Step 2 of 3")
    op.get_by_role("radio", name=re.compile("^Teacher")).click()
    op.locator("form").get_by_role("button", name="Next").click(); op.wait_for_selector("text=Step 3 of 3")
    op.fill("#staff_phone_confirmation", "+251 711 22 33 44")
    op.get_by_role("button", name="Create Account").click(); op.wait_for_selector("[data-testid=credentials]")
    temp = op.locator("[data-testid=cred-password]").inner_text()
    check("new staff: their 07 number and a temporary password are shown once", "0711 22 33 44" in op.locator("[data-testid=cred-who]").inner_text() and re.fullmatch(r"[A-Za-z2-9]{10}", temp) is not None, temp)
    row = q("select login_phone, phone, email, must_change_password from users where name='Tsion Bekele'")
    check("new staff: stored as one canonical number, no email, must change the password", row == [("+251711223344", "+251711223344", None, 1)], str(row))
    check("new staff: the stored password is a hash, not the password", q("select password from users where name='Tsion Bekele'")[0][0] != temp)

    # ================================================================ 5. their first sign-in
    sctx, sp = new_page(browser)
    try_login(sp, "+251 711 22 33 44", temp)
    sp.wait_for_selector("[data-testid=welcome]")
    check("first sign-in: 'Welcome, Tsion Bekele' and 'please create a new password'", "Welcome, Tsion Bekele" in sp.locator("[data-testid=welcome]").inner_text() and "For your security, please create a new password." in body(sp))
    check("first sign-in: current, new and confirm boxes and a 'Change Password' button", all(sp.locator(f"#{i}").count() == 1 for i in ("current_password", "password", "password_confirmation")) and sp.get_by_role("button", name="Change Password").evaluate(H) >= 56)
    sp.goto(f"{BASE}/students"); sp.wait_for_load_state("networkidle")
    check("first sign-in: nothing else opens before the password is changed", "/password/change" in sp.url)
    sp.fill("#current_password", "wrong"); sp.fill("#password", "Brand-New-Pass!77"); sp.fill("#password_confirmation", "Brand-New-Pass!77")
    sp.get_by_role("button", name="Change Password").click()
    check("first sign-in: a wrong current password is explained beside its box", until(lambda: sp.locator("#current_password-error").count() == 1))
    sp.fill("#current_password", temp); sp.fill("#password", "Brand-New-Pass!77"); sp.fill("#password_confirmation", "different")
    sp.get_by_role("button", name="Change Password").click()
    check("first sign-in: a confirmation that does not match is explained", until(lambda: sp.locator("#password-error").count() == 1))
    check("first sign-in: nothing changed yet", q("select must_change_password from users where name='Tsion Bekele'")[0][0] == 1)
    sp.fill("#current_password", temp); sp.fill("#password", "Brand-New-Pass!77"); sp.fill("#password_confirmation", "Brand-New-Pass!77")
    sp.get_by_role("button", name="Change Password").click()
    check("first sign-in: after choosing a new password the person is in", leaves(sp, "/password/change") and until(lambda: q("select must_change_password from users where name='Tsion Bekele'")[0][0] == 0), sp.url)
    sp.goto(f"{BASE}/dashboard"); sp.wait_for_load_state("networkidle")
    check("first sign-in: the system now opens", "/dashboard" in sp.url)
    check("first sign-in: the change is in the audit log (without the password)", q("select count(*) from audit_logs where action='account.password_changed'")[0][0] >= 1)
    sctx.close()
    c2, p2 = new_page(browser)
    try_login(p2, "0711223344", temp)
    check("the temporary password no longer works", not signed_in(p2))
    try_login(p2, "0711223344", "Brand-New-Pass!77")
    check("the new password works", signed_in(p2))
    c2.close()

    # ================================================================ 6. a password reset
    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    card = op.locator("[data-testid=staff-row]").filter(has_text="Tsion Bekele")
    check("reset: the list shows the person's phone number and a Reset password button", "0711 22 33 44" in card.inner_text() and card.get_by_role("button", name="Reset password").evaluate(H) >= 44)
    card.get_by_role("button", name="Reset password").click(); op.get_by_role("dialog").get_by_role("button", name="Reset password for Tsion Bekele").click()
    op.wait_for_selector("[data-testid=reset-result] [data-testid=cred-password]")
    temp2 = op.locator("[data-testid=reset-result] [data-testid=cred-password]").inner_text()
    check("reset: a new temporary password is shown once (never the old one)", re.fullmatch(r"[A-Za-z2-9]{10}", temp2) is not None and temp2 != temp and "Brand-New-Pass" not in body(op))
    check("reset: the person must change it again", q("select must_change_password from users where name='Tsion Bekele'")[0][0] == 1)
    c3, p3 = new_page(browser)
    try_login(p3, "0711223344", "Brand-New-Pass!77")
    check("reset: the old password stopped working at once", not signed_in(p3))
    try_login(p3, "0711223344", temp2)
    p3.wait_for_selector("[data-testid=welcome]")
    check("reset: the new temporary password leads to the choose-a-password page again", "/password/change" in p3.url)
    p3.get_by_role("button", name="Sign out instead").click()
    check("reset: the person can sign out from that page", leaves(p3, "/password/change"), p3.url)
    c3.close()
    op.reload(); op.wait_for_selector("[data-testid=staff-row]")
    check("reset: the details are not shown again after a reload", op.locator("[data-testid=cred-password]").count() == 0)
    own = op.locator("[data-testid=staff-row]").filter(has_text="Selam Bekele")
    own.get_by_role("button", name="Reset password").click(); op.get_by_role("dialog").get_by_role("button", name="Reset password for Selam Bekele").click()
    check("reset: nobody can reset their own password that way", until(lambda: "cannot reset your own password" in body(op)))
    octx.close()

    # ================================================================ 7. the throttle
    ctx, page = new_page(browser)
    for typed in ("0977000111", "+251977000111", "977000111", "0977 00 01 11", "251977000111", "0977000111"):
        try_login(page, typed, "nope")
    check("throttle: many wrong tries for one number (typed different ways) are slowed down, with a plain message", "Too many unsuccessful login attempts" in (page.locator("#phone-error").inner_text() if page.locator("#phone-error").count() else ""))
    thr = page.locator("#phone-error").inner_text()
    check("throttle: the message says how long to wait, and nothing about limits or why", re.search(r"wait \d+ (second|minute)", thr) is not None and "locked" not in thr.lower() and "exist" not in thr.lower(), thr)
    ctx.close()
    c4, p4 = new_page(browser)
    try_login(p4, "0911000006")
    check("throttle: a different person on the same address is not held up", signed_in(p4))
    c4.close()

    # ================================================================ 7b. what an administrator sees and can do
    oc, opg = new_page(browser); try_login(opg, "0911000001")
    for _ in range(3):
        cx, px = new_page(browser); try_login(px, "0911000007", "wrong-guess"); cx.close()
    cx, px = new_page(browser); try_login(px, "0911000007")
    check("the paused person is held back even with the right password (the password is not checked during the wait)", not signed_in(px) and "Too many unsuccessful login attempts" in px.locator("#phone-error").inner_text())
    cx.close()
    opg.goto(f"{BASE}/staff"); opg.wait_for_selector("[data-testid=staff-row]")
    t2 = opg.locator("[data-testid=staff-row]").filter(has_text="Meseret Alemu")
    check("admin: after repeated failures the staff list says sign-in is paused for that person, with a way to allow it again", "Sign-in paused for now" in t2.inner_text() and t2.get_by_role("button", name="Allow sign-in again").count() == 1, t2.inner_text()[:80])
    t2.get_by_role("button", name="Allow sign-in again").click()
    dlg = opg.get_by_role("dialog"); dlg.wait_for()
    check("admin: allowing sign-in again asks first and says the password is not changed and that it is written to the security log", "password is not changed" in dlg.inner_text() and "security log" in dlg.inner_text())
    dlg.get_by_role("button", name="Allow Meseret Alemu to try again").click()
    check("admin: the pause is cleared", until(lambda: opg.locator("[data-testid=staff-row]").filter(has_text="Meseret Alemu").get_by_role("button", name="Allow sign-in again").count() == 0))
    cx, px = new_page(browser); try_login(px, "0911000007")
    check("...and the person can sign in at once (their password was never changed)", signed_in(px))
    cx.close()
    opg.goto(f"{BASE}/audit-log"); opg.get_by_role("link", name="Sign-in security").click(); opg.wait_for_selector("[data-testid=security-event]")
    stext = body(opg)
    check("security log: failed tries, the wait, the unlock and a sign-in after failures are listed in plain words", all(w in stext for w in ("A sign-in try failed", "Repeated failed tries: a short wait began", "An administrator let a person try to sign in again", "Signed in after some failed tries")))
    check("security log: numbers are partly hidden and no password appears", "+2519*****007" in stext and "0911000007" not in stext and "wrong-guess" not in stext and "+251911000007" not in stext)
    opg.select_option("#sec-event", value="login_failed")
    check("security log: it can be narrowed to one kind of event", until(lambda: all("login_failed" in t for t in opg.locator("[data-testid=security-event]").all_inner_texts()) and opg.locator("[data-testid=security-event]").count() >= 1))
    oc.close()
    tc2, tp2 = new_page(browser); try_login(tp2, "0911000003")
    check("someone who may not read the audit log cannot open the security log", tc2.request.get(BASE + "/audit-log/security", max_redirects=0).status == 403)
    tc2.close()

    # ================================================================ 8. phone and Amharic
    mctx, mp = new_page(browser, mobile=True)
    mp.goto(f"{BASE}/login"); mp.wait_for_selector("#phone")
    check("phone: the sign-in page has no sideways scroll and a large Log in button", overflow(mp) <= 1 and mp.get_by_role("button", name="Log in").evaluate(H) >= 56)
    mctx.close()
    actx, ap = new_page(browser)
    ap.goto(f"{BASE}/login"); ap.wait_for_selector("#phone")
    ap.get_by_role("button", name="አማርኛ").click(); ap.wait_for_timeout(600)
    t = body(ap)
    check("Amharic: the sign-in page is translated (heading, phone, buttons), no raw keys", bool(re.search(r"[ሀ-፿]", ap.locator("h1").inner_text())) and re.search(r"\bsignin\.[a-z_]+", t) is None and "Show password" not in t, t[:120].replace("\n", " | "))
    actx.close()

    browser.close()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
