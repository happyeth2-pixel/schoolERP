"""Browser test: the staff account lifecycle, in a real browser.

An administrator creates an account (no email), hands over the temporary password shown once, the person signs in
with their phone number and must choose their own password; the administrator can find them by phone, switch the
account off (nothing is deleted, they are signed out and cannot sign in) and switch it on again.

Uses the usability-session data and adds one account (removed again). Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/staff_lifecycle.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
H = "e => Math.round(e.offsetHeight)"
PHONE, PHONE_TYPED, NAME = "+251966112233", "0966 11 22 33", "Life Cycle"
TYPED_PW, OWN_PW = "Typed1234", "My-Own-Pass-55"


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


def new_page(browser, mobile=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    ctx = browser.new_context(**kw)
    page = ctx.new_page()
    page.js_errors = []
    page.on("pageerror", lambda e: page.js_errors.append(str(e)))
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


wait_text = lambda pg, text, timeout=15000: pg.wait_for_selector(f"text={text}", timeout=timeout)
nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")

q("delete from users where login_phone=?", PHONE)
free = q("select s.id, g.name || ' ' || s.name, s.class_teacher_id from sections s join grades g on g.id=s.grade_id join academic_years y on y.id=s.academic_year_id where y.is_current=1 order by s.id limit 1")[0]
q("update sections set class_teacher_id=null where id=?", free[0])   # so the wizard has a free class to offer (restored at the end)
users_before = q("select count(*) from users")[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, op = new_page(browser)
    check("the owner signs in", sign_in(op, "owner@demo.test", "password"))

    # ================================================================ 0. editing your OWN account doesn't crash
    # Real bug found by hand: Field (Components/ui/Form.jsx) clones its single child with React.Children.only --
    # the isOwnAccount branch of EditStaffForm gave it two (an <Input> plus a separate hint <p>), which only ever
    # renders when someone opens the Change dialog on THEIR OWN row -- every other account's dialog takes a
    # different branch, so this was invisible until an owner tried to edit themselves.
    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    own_card = op.locator("[data-testid=staff-row]").filter(has_text="owner@demo.test")
    own_card.get_by_role("button", name="Change").click()
    op.wait_for_selector("[data-testid=edit-staff]")
    dialog_text = op.locator("[data-testid=edit-staff]").inner_text()
    check("own account: the Change dialog opens without crashing and explains the role is locked",
          "cannot change your own system role" in dialog_text.lower(), dialog_text[:200])
    check("own account: opening it raised no JavaScript error", not op.js_errors, str(op.js_errors[:2]))
    op.get_by_role("button", name="Cancel").click()
    op.wait_for_selector("[data-testid=edit-staff]", state="detached")

    # ================================================================ 1. create (typed temporary password, class teacher)
    op.goto(f"{BASE}/staff/create"); wait_text(op, "Step 1 of 3")
    op.fill("#staff_name", NAME); op.fill("#staff_phone", PHONE_TYPED); op.fill("#staff_title", "Science teacher")
    nxt(op).click(); wait_text(op, "Step 2 of 3")
    check("step 2 asks about the class only once a teacher is chosen", op.locator("[data-testid=class-question]").count() == 0)
    op.get_by_role("radio", name=re.compile("^Teacher")).click()
    cq = op.locator("[data-testid=class-question]")
    check("a teacher: an optional 'class teacher of' question lists a class that has none", cq.count() == 1 and free[1] in cq.inner_text() and "(Optional)" in cq.inner_text(), cq.inner_text()[:120] if cq.count() else "")
    op.select_option("#staff_class", value=str(free[0]))
    op.get_by_role("radio", name=re.compile("^Branch admin")).click()
    check("choosing another role hides the class question", op.locator("[data-testid=class-question]").count() == 0)
    op.get_by_role("radio", name=re.compile("^Teacher")).click()
    check("choosing Teacher again asks again, and forgot the old class (nothing silently kept)", op.locator("#staff_class").input_value() == "")
    op.select_option("#staff_class", value=str(free[0]))
    nxt(op).click(); wait_text(op, "Step 3 of 3")
    check("account step: the summary names the class", free[1] in body(op))
    op.get_by_role("radio", name=re.compile("^I will type one")).click()
    op.fill("#staff_temp_password", TYPED_PW)
    op.get_by_role("button", name="Show password").click()
    check("account step: 'Show password' reveals what was typed", op.locator("#staff_temp_password").get_attribute("type") == "text")
    op.get_by_role("button", name="Create Account").click(); wait_text(op, "Account created successfully")
    row = q("select id, email, login_phone, status, must_change_password from users where login_phone=?", PHONE)
    check("created: no email, canonical login phone, active, must change the password", len(row) == 1 and row[0][1] is None and row[0][3] == "active" and row[0][4] == 1, str(row))
    uid = row[0][0]
    check("created: the class teacher was set", q("select class_teacher_id from sections where id=?", free[0])[0][0] == uid)
    ct = op.locator("[data-testid=credentials]").inner_text()
    check("created: name, phone, role, class, username/login and the temporary password are all shown", NAME in ct and "Teacher" in ct and free[1] in ct and "Username/login" in ct and "0966 11 22 33" in op.locator("[data-testid=cred-who]").inner_text() and op.locator("[data-testid=cred-password]").inner_text() == TYPED_PW, ct[:250].replace("\n", " | "))
    check("created: the warning is shown in full", "Give the temporary password to the staff member securely. They will be required to change it when they first log in." in op.locator("[data-testid=cred-warning]").inner_text())
    check("created: the stored password is a hash, not the typed one", q("select password from users where id=?", uid)[0][0] != TYPED_PW)
    check("created: Copy and Print details are buttons", op.get_by_role("button", name="Copy account details").count() == 1 and op.get_by_role("button", name="Print account details").count() == 1)
    op.emulate_media(media="print")
    check("printing: the account details (with the warning) are the printed area", op.locator("[data-print-area]").count() == 1 and TYPED_PW in op.locator("[data-print-area]").inner_text() and "Account created successfully" in op.locator("[data-print-area]").inner_text() and op.locator("[data-print-area] [data-testid=cred-warning]").is_visible())
    op.emulate_media(media="screen")
    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    check("the details are gone after leaving the page (never shown again)", op.locator("[data-testid=cred-password]").count() == 0 and TYPED_PW not in body(op))

    # ================================================================ 2. first sign-in
    sctx, sp = new_page(browser)
    check("the new person signs in with their phone number and the temporary password", sign_in(sp, "0966 11 22 33", TYPED_PW))
    sp.wait_for_selector("[data-testid=welcome]")
    check("first sign-in: they are held on 'choose a new password'", "/password/change" in sp.url)
    sp.fill("#current_password", TYPED_PW); sp.fill("#password", OWN_PW); sp.fill("#password_confirmation", OWN_PW)
    sp.get_by_role("button", name="Change Password").click()
    check("first sign-in: after choosing their own password they are in", until(lambda: q("select must_change_password from users where id=?", uid)[0][0] == 0))
    sp.goto(f"{BASE}/dashboard"); sp.wait_for_load_state("networkidle")
    check("first sign-in: the system opens", "/dashboard" in sp.url)

    # ================================================================ 3. find them by phone; switch off
    op.goto(f"{BASE}/staff"); op.wait_for_selector("[data-testid=staff-row]")
    op.get_by_placeholder(re.compile("Search name, phone")).fill("966 11 22")
    check("search: typing part of the phone number (with spaces) finds the person", until(lambda: op.locator("[data-testid=staff-row]").count() == 1 and NAME in op.locator("[data-testid=staff-row]").inner_text()))
    op.get_by_placeholder(re.compile("Search name, phone")).fill("+251966112233")
    check("search: the full +251 number finds them too", until(lambda: op.locator("[data-testid=staff-row]").count() == 1))
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    ctext = card.inner_text()
    check("list: name, phone, job title, role, branch and status are all on the card", NAME in ctext and "0966 11 22 33" in ctext and "Science teacher" in ctext and "Teacher" in ctext and "Active" in ctext and card.get_by_role("button", name="Disable account").count() == 1 and card.get_by_role("button", name="Reset password").count() == 1)
    check("list: no password of any kind is on the card", TYPED_PW not in ctext and OWN_PW not in ctext and "password" not in ctext.replace("Reset password", "").replace("Must choose a new password", "").lower())
    own = op.locator("[data-testid=staff-row]")
    op.get_by_placeholder(re.compile("Search name, phone")).fill("owner@demo.test")
    check("the signed-in person's own card has no Disable button", until(lambda: op.locator("[data-testid=staff-row]").count() >= 1) and op.get_by_role("button", name="Disable account").count() == 0)
    op.get_by_placeholder(re.compile("Search name, phone")).fill("966 11 22")
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    card.get_by_role("button", name="Disable account").click()
    dlg = op.get_by_role("dialog"); dlg.wait_for()
    dt = dlg.inner_text()
    check("disable: asks first and says signed out at once, cannot sign in, and that nothing is deleted", f"Switch off {NAME}'s account?" in dt and "signed out at once" in dt and "Nothing is deleted" in dt and "attendance, marks, payments and history" in dt, dt[:200].replace("\n", " | "))
    dlg.get_by_role("button", name="Cancel").click(); op.wait_for_selector("[role=dialog]", state="detached")
    check("disable: Cancel changes nothing", q("select status from users where id=?", uid)[0][0] == "active")
    audit_before = q("select count(*) from audit_logs where user_id=?", uid)[0][0]
    card.get_by_role("button", name="Disable account").click(); op.get_by_role("dialog").get_by_role("button", name=f"Switch off {NAME}'s account").click()
    check("disable: the account is switched off in the database", until(lambda: q("select status from users where id=?", uid)[0][0] == "inactive"))
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    check("disable: the card is marked 'Switched off' and offers 'Reactivate account' instead", until(lambda: card.get_attribute("data-status") == "inactive") and "Switched off" in card.inner_text() and card.get_by_role("button", name="Reactivate account").count() == 1 and card.get_by_role("button", name="Disable account").count() == 0)
    check("disable: it is written to the security log", q("select count(*) from security_events where event='account_disabled' and user_id=?", uid)[0][0] == 1)
    check("disable: nothing was deleted (the account, the class teacher link and the audit trail remain)", q("select count(*) from users where id=?", uid)[0][0] == 1 and q("select class_teacher_id from sections where id=?", free[0])[0][0] == uid and q("select count(*) from audit_logs where user_id=?", uid)[0][0] == audit_before and q("select count(*) from users")[0][0] == users_before + 1)
    sp.goto(f"{BASE}/dashboard"); sp.wait_for_load_state("networkidle")
    check("disable: someone already signed in is sent to the sign-in page on their next click", "/login" in sp.url, sp.url)
    sctx.close()
    c2, p2 = new_page(browser)
    check("disable: the right phone and password no longer sign in", not sign_in(p2, "0966112233", OWN_PW))
    check("disable: and the reason given is the same generic message (nothing reveals the account is off)", "phone number or password is incorrect" in p2.locator("#phone-error").inner_text())
    c2.close()

    # ================================================================ 4. switch on again
    card.get_by_role("button", name="Reactivate account").click()
    dlg = op.get_by_role("dialog"); dlg.wait_for()
    check("reactivate: asks first and says the existing password works again", "existing password" in dlg.inner_text())
    dlg.get_by_role("button", name=f"Switch {NAME}'s account on").click()
    check("reactivate: the account is active again and the card says so", until(lambda: q("select status from users where id=?", uid)[0][0] == "active") and until(lambda: op.locator("[data-testid=staff-row]").filter(has_text=NAME).get_attribute("data-status") == "active"))
    check("reactivate: written to the security log", q("select count(*) from security_events where event='account_enabled' and user_id=?", uid)[0][0] == 1)
    c3, p3 = new_page(browser)
    check("reactivate: they sign in again with their own password", sign_in(p3, "0966112233", OWN_PW))
    c3.close()

    # ================================================================ 5. change details, reset
    card = op.locator("[data-testid=staff-row]").filter(has_text=NAME)
    card.get_by_role("button", name="Change").click(); op.wait_for_selector("[data-testid=edit-staff]")
    op.fill("#edit_name", "Life Cycle Two"); op.fill("#edit_phone", "0966 11 22 44")
    op.locator("[data-testid=edit-staff]").get_by_role("button", name="Save changes").click()
    check("change: name and phone updated (the phone is the new login)", until(lambda: q("select name, login_phone from users where id=?", uid)[0] == ("Life Cycle Two", "+251966112244")))
    op.wait_for_selector("[data-testid=edit-staff]", state="detached")
    card = op.locator("[data-testid=staff-row]").filter(has_text="Life Cycle Two")
    card.get_by_role("button", name="Reset password").click(); op.get_by_role("dialog").get_by_role("button", name="Reset password for Life Cycle Two").click()
    op.wait_for_selector("[data-testid=reset-result] [data-testid=cred-password]")
    rt = op.locator("[data-testid=reset-result]").inner_text()
    check("reset: a new temporary password is shown with the login and the warning, once", "New temporary password for Life Cycle Two" in rt and "0966 11 22 44" in rt and "Give the temporary password to the staff member securely" in rt and op.get_by_role("button", name="Print account details").count() >= 1)
    check("reset: the person must choose a new password again", q("select must_change_password from users where id=?", uid)[0][0] == 1)

    # ================================================================ 6. phone layout
    mctx, mp = new_page(browser, mobile=True)
    sign_in(mp, "owner@demo.test", "password")
    mp.goto(f"{BASE}/staff"); mp.wait_for_selector("[data-testid=staff-row]")
    check("phone: the staff list (with Disable / Reactivate) has no sideways scroll", overflow(mp) <= 1, str(overflow(mp)))
    mp.locator("[data-testid=staff-row]").filter(has_text="Life Cycle Two").get_by_role("button", name="Disable account").click()
    d = mp.get_by_role("dialog"); d.wait_for()
    check("phone: the confirmation fits and its buttons are large", overflow(mp) <= 1 and d.get_by_role("button", name=re.compile("^Switch off")).evaluate(H) >= 44)
    mctx.close()

    check("no JavaScript errors", not op.js_errors, str(op.js_errors[:2]))
    browser.close()

q("update sections set class_teacher_id=? where id=?", free[2], free[0])
q("delete from users where login_phone in (?, ?)", PHONE, "+251966112244")
fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
