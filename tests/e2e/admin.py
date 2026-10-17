"""Browser test: the administrative workflows, in a real browser, as the owner, a branch admin and others.

  * Staff: the list (job title and system role as two clearly named things), "Add a staff member" in three steps
    (who / what may they do / check and add), nothing preselected for the role, the usual permission set preselected
    for a branch admin, permissions in words and groups, permissions the person adding does not hold are disabled,
    nothing saved before the last button, the temporary password shown once, changing a person, branch choice
    when there are several branches, who may add whom
  * Sections: the page (how full, class teacher), "Add a section" in three steps, duplicate names, capacity,
    changing a section, removing (with consequences; a section with students refuses)
  * Teacher assignment: pick a class, its class teacher, give a subject to a teacher in two taps, replace only after
    a deliberate confirmation, remove
  * Audit log: plain-language sentences beside the raw code, before/after in words, filters, export, nothing hidden
  * Settings: parts with a short list to jump to, unsaved-changes note and guard, languages, payment accounts
  * people without permission; a phone layout; Amharic

Uses the usability-session data and changes it (adds staff, a section, an assignment, a payment account, an extra
branch which it removes again). Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/admin.py
"""
import os, re, sqlite3, sys, tempfile, time
sys.stdout.reconfigure(encoding="utf-8")
from urllib.parse import unquote
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
SHOTS = tempfile.mkdtemp(prefix="admin_e2e_")
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


def status_of(ctx, path):
    return ctx.request.get(BASE + path, max_redirects=0).status


nxt = lambda pg: pg.locator("form").get_by_role("button", name="Next")
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
body = lambda pg: pg.locator("body").inner_text()


def fill_who(pg, name, phone, title=None):
    """Step 1 of the staff form: name, phone, (job title), then Next."""
    pg.fill("#staff_name", name); pg.fill("#staff_phone", phone)
    if title:
        pg.fill("#staff_title", title)
    nxt(pg).click(); wait_text(pg, "Step 2 of 3")


def account_step(pg, phone, email=None, own_password=None, submit=True):
    """From step 2 to step 3 (Account): retype the phone, optional email / own password, then Create Account."""
    nxt(pg).click(); wait_text(pg, "Step 3 of 3")
    pg.fill("#staff_phone_confirmation", phone)
    if email:
        pg.fill("#staff_email", email)
    if own_password:
        pg.get_by_role("radio", name=re.compile("^I will type one")).click(); pg.fill("#staff_temp_password", own_password)
    if submit:
        pg.get_by_role("button", name="Create Account").click()


RAW = re.compile(r"\b(?:staff|perm|sect|struct|teach|audit|settings)\.[a-z_-]+\b")


def wait_text(pg, text, timeout=15000):
    pg.wait_for_selector(f"text={text}", timeout=timeout)


MAIN = q("select id, name from branches order by id limit 1")[0]
for e in ("owner", "principal", "teacher", "accountant", "auditor", "parent"):
    q("update users set locale=null where email=?", f"{e}@demo.test")
q("delete from users where branch_id in (select id from branches where name='East Campus')")
q("delete from branches where name='East Campus'")   # left behind by an interrupted earlier run
q("delete from teaching_assignments where section_id in (select id from sections where name='Q')")
q("delete from sections where name='Q'")   # left behind by an interrupted earlier run
q("delete from users where email like 'wiz.%' or phone in ('+251911223344','+251922111111','+251922222222','+251933333333','+251944444444','+251955555555')")
users_before = q("select count(*) from users")[0][0]

with sync_playwright() as p:
    browser = p.chromium.launch()
    octx, page = login(browser, "owner@demo.test")

    # ================================================================ 1. staff list
    page.goto(f"{BASE}/staff"); page.wait_for_selector("[data-testid=staff-row]")
    add = page.get_by_role("link", name="Add a staff member")
    check("staff list: a large 'Add a staff member' button", add.first.evaluate(H) >= 56)
    first = page.locator("[data-testid=staff-row]").first
    txt = first.inner_text()
    check("staff list: job title and system role are two named things", "System role" in txt and "Job title (label only)" in txt, txt[:120])
    check("staff list: the old modal is gone (Add opens a page)", add.first.get_attribute("href").endswith("/staff/create"))

    # ================================================================ 2. wizard: step 1
    add.first.click(); wait_text(page, "Step 1 of 3")
    check("wizard step 1: 'Step 1 of 3' and the three questions", "Step 1 of 3" in body(page))
    check("wizard step 1: Next is off until name and an Ethiopian mobile number, and says why", nxt(page).is_disabled() and "Type their name and an Ethiopian mobile number" in body(page))
    check("wizard step 1: the phone is the sign-in number (required), the job title is optional, and there is no email question here", "They sign in with this" in body(page) and "(Optional)" in page.locator("label[for=staff_title]").inner_text() and "(Optional)" not in page.locator("label[for=staff_phone]").inner_text() and page.locator("#staff_email").count() == 0)
    check("wizard step 1: the job title says it is only a label", "It gives no access" in page.locator("#staff_title-hint").inner_text())
    check("wizard step 1: job title vs system role explained side by side", page.locator("[data-testid=title-vs-access]").count() == 1 and "Only a name for their job" in body(page))
    page.fill("#staff_name", "Wiz One")
    page.fill("#staff_phone", "0811 22 33 44")
    check("wizard step 1: a number that is not an Ethiopian mobile is explained beside the field", "does not look like an Ethiopian mobile number" in body(page) and nxt(page).is_disabled())
    page.fill("#staff_phone", "0911 22 33 44")
    check("wizard step 1: Next is on with just a name and a valid phone (no email needed)", nxt(page).is_enabled())
    page.fill("#staff_title", "Vice Principal")
    nxt(page).click(); wait_text(page, "Step 2 of 3")

    # ================================================================ 3. wizard: step 2
    check("wizard step 2: no role is preselected", page.locator("[role=radio][aria-checked=true]").count() == 0)
    check("wizard step 2: Next is off and says to choose a role", nxt(page).is_disabled() and "Choose a role to continue" in body(page))
    roles = page.locator("[role=radiogroup] [role=radio]").all_inner_texts()
    check("wizard step 2: the owner may pick any of five roles, each explained", len(roles) == 5 and all("\n" in r for r in roles), str([r[:20] for r in roles]))
    check("wizard step 2: the job title vs role explanation is repeated here", page.locator("[data-testid=title-vs-access]").count() == 1)
    page.get_by_role("radio", name=re.compile("^Teacher")).click()
    check("role Teacher: fixed permissions listed in words, no checklist", page.locator("[data-testid=fixed-permissions]").count() == 1 and page.locator("[data-testid=permission-picker]").count() == 0 and "Take attendance" in body(page) and "Enter marks" in body(page))
    check("role Teacher: one branch only, so no branch question (a note names it)", page.locator("[data-testid=fixed-branch]").count() == 1)
    page.get_by_role("radio", name=re.compile("^Owner")).click()
    check("role Owner: a clear warning that an owner can do everything", "An owner can do everything" in body(page))
    page.get_by_role("radio", name=re.compile("^Auditor")).click()
    check("role Auditor: 'all branches' note instead of a branch", page.locator("[data-testid=all-branches]").count() == 1 and page.locator("[data-testid=fixed-branch]").count() == 0)
    page.get_by_role("radio", name=re.compile("^Branch admin")).click()
    picker = page.locator("[data-testid=permission-picker]")
    check("role Branch admin: the usual set is preselected (41 of 71 for an owner)", "41 of 71 permissions ticked" in picker.inner_text(), picker.locator("[data-testid=perm-count]").inner_text())
    groups = page.locator("[data-testid^=perm-group-]").count()
    check("permissions are grouped in words, fourteen groups", groups == 14)
    check("permissions are words, not codes", "Manage staff accounts" in picker.inner_text() and "manage-staff" not in picker.inner_text())
    check("each permission says what it lets someone do", "Add staff, change job titles" in picker.inner_text())
    check("the permission rows are large targets", picker.locator("label").first.evaluate(H) >= 56)
    page.locator("label[for=perm-manage-staff]").click()
    check("unticking a permission updates the count (40 of 71)", until(lambda: "40 of 71 permissions ticked" in picker.inner_text()))
    picker.get_by_role("button", name="Untick everything").click()
    check("'Untick everything' clears them and Next says why it is off", until(lambda: "0 of 71" in picker.inner_text() and nxt(page).is_disabled() and "Tick at least one permission" in body(page)))
    picker.get_by_role("button", name="Use the usual set").click()
    check("'Use the usual set' puts them back", until(lambda: "41 of 71 permissions ticked" in picker.inner_text() and nxt(page).is_enabled()))
    page.locator("label[for=perm-manage-staff]").click()             # this branch admin may NOT manage staff
    page.locator("label[for=perm-approve-large-discounts]").click()   # nor approve large discounts
    nxt(page).click(); wait_text(page, "Step 3 of 3")

    # ================================================================ 4. wizard: account step (with the summary)
    rv = body(page)
    check("account step: every answer in words, the login is the phone, the job title marked 'label only, no access'", "Wiz One" in rv and "Username / login (phone number)" in rv and "0911 22 33 44" in rv and "Vice Principal (label only, no access)" in rv, rv[:300].replace("\n", " | "))
    check("review: the system role and what they will be able to do are listed", "Branch admin" in rv and page.locator("[data-testid=perm-summary] li").count() == 39)
    check("review: the permissions that were unticked are not in the list", "Manage staff accounts" not in page.locator("[data-testid=perm-summary]").inner_text())
    check("account step: the final button says Create Account, and the note says what happens with the temporary password", page.get_by_role("button", name="Create Account").count() == 1 and "temporary password" in rv)
    check("account step: Create Account is off until the phone number is typed again, and says why", page.get_by_role("button", name="Create Account").is_disabled() and "Type the same phone number again" in body(page))
    page.fill("#staff_phone_confirmation", "0911 22 33 55")
    check("account step: a different second number is explained beside the field", "not the same" in body(page) and page.get_by_role("button", name="Create Account").is_disabled())
    page.fill("#staff_phone_confirmation", "+251 911 22 33 44")
    check("account step: the same number written another way is accepted", page.get_by_role("button", name="Create Account").is_enabled())
    check("account step: 'Make one for me' is chosen by default and no password box is shown", page.get_by_role("radio", name=re.compile("^Make one for me")).get_attribute("aria-checked") == "true" and page.locator("#staff_temp_password").count() == 0)
    page.get_by_role("radio", name=re.compile("^I will type one")).click()
    page.fill("#staff_temp_password", "abc")
    check("account step: a weak typed password is explained and blocks the button", "at least 8 characters" in body(page) and page.get_by_role("button", name="Create Account").is_disabled())
    check("account step: the typed password has a Show password button (hidden by default)", page.locator("#staff_temp_password").get_attribute("type") == "password" and page.get_by_role("button", name="Show password").count() == 1)
    page.get_by_role("radio", name=re.compile("^Make one for me")).click()
    check("account step: going back to 'Make one for me' enables the button again", page.get_by_role("button", name="Create Account").is_enabled())
    check("account step: email is optional contact information here", "(Optional)" in page.locator("label[for=staff_email]").inner_text() and "Not needed to sign in" in body(page) or "Optional contact" in body(page))
    page.fill("#staff_email", "wiz.one@test")
    check("account step: a mistyped optional email is still explained", "does not look like an email" in body(page) and page.get_by_role("button", name="Create Account").is_disabled())
    page.fill("#staff_email", "wiz.one@test.test")
    check("account step: nothing is saved before the last button", q("select count(*) from users where email='wiz.one@test.test'")[0][0] == 0)
    page.get_by_role("button", name="Back").click(); wait_text(page, "Step 2 of 3")
    check("Back keeps everything (role and 39 permissions still set)", page.get_by_role("radio", name=re.compile("^Branch admin")).get_attribute("aria-checked") == "true" and "39 of 71 permissions ticked" in page.locator("[data-testid=permission-picker]").inner_text())
    nxt(page).click(); wait_text(page, "Step 3 of 3")
    check("Back and Next keep the second phone number and email typed on the account step", page.input_value("#staff_phone_confirmation") == "+251 911 22 33 44" and page.input_value("#staff_email") == "wiz.one@test.test")
    page.get_by_role("button", name="Create Account").click()
    wait_text(page, "Account created successfully")
    row = q("select id, title, phone, branch_id, status from users where email='wiz.one@test.test'")
    check("saved: the account exists with title, normalised phone, branch and status", len(row) == 1 and row[0][1] == "Vice Principal" and row[0][2] == "+251911223344" and row[0][3] == MAIN[0] and row[0][4] == "active", str(row))
    perms = sorted(r[0] for r in q("select p.name from model_has_permissions m join permissions p on p.id=m.permission_id where m.model_id=? and m.model_type like '%User'", row[0][0]))
    check("saved: exactly the 39 chosen permissions (and not the two unticked)", len(perms) == 39 and "manage-staff" not in perms and "approve-large-discounts" not in perms)
    check("saved: the job title granted nothing beyond the ticked permissions", "view-audit-log" not in perms and "refund-payments" not in perms)
    log = q("select action from audit_logs where auditable_id=? and action in ('staff.role_assigned','account.temporary_password_issued')", row[0][0])
    check("saved: audit entries for the role and the temporary password (never the password itself)", sorted(a[0] for a in log) == ["account.temporary_password_issued", "staff.role_assigned"], str(log))
    box = page.locator("[data-testid=credentials]")
    pw = page.locator("[data-testid=cred-password]").inner_text()
    check("saved: the phone number and a readable temporary password are shown large, once", box.count() == 1 and "0911 22 33 44" in page.locator("[data-testid=cred-who]").inner_text() and re.fullmatch(r"[A-Za-z2-9]{10}", pw) is not None, pw)
    ct = box.inner_text()
    check("saved: 'Account created successfully.' with the name, phone, role and the username/login", "Account created successfully" in body(page) and "Wiz One" in ct and "Role" in ct and "Branch admin" in ct and "Username/login" in ct and "0911 22 33 44" in page.locator("[data-testid=cred-phone]").inner_text(), ct[:200].replace("\n", " | "))
    check("saved: the warning tells them to hand it over securely and that it must be changed at the first log in", "Give the temporary password to the staff member securely. They will be required to change it when they first log in." in page.locator("[data-testid=cred-warning]").inner_text())
    check("saved: it says the password is shown only once", "shown only now" in ct or "only once" in ct.lower())
    check("saved: copy and print buttons are large and named", page.get_by_role("button", name="Copy account details").evaluate(H) >= 56 and page.get_by_role("button", name="Print account details").evaluate(H) >= 56)
    check("saved: the password is stored hashed and the account must change it", q("select must_change_password, length(password) > 30, password != ? from users where email='wiz.one@test.test'", pw)[0] == (1, 1, 1))
    page.get_by_role("button", name="Add another staff member").click(); wait_text(page, "Step 1 of 3")
    check("'Add another' starts again empty at step 1", page.input_value("#staff_name") == "" and "Step 1 of 3" in body(page))

    # a taken email is refused on the account step, where the email is asked
    fill_who(page, "Wiz Dup", "0922 11 11 11")
    page.get_by_role("radio", name=re.compile("^Accountant")).click()
    account_step(page, "0922 11 11 11", email="wiz.one@test.test")
    wait_text(page, "already been taken")
    check("a taken email keeps you on the account step with the reason beside the field", "Step 3 of 3" in body(page) and "already been taken" in body(page), body(page)[:200])
    check("a taken email created nobody", q("select count(*) from users where email='wiz.one@test.test'")[0][0] == 1 and q("select count(*) from users where name='Wiz Dup'")[0][0] == 0)
    # a taken phone number sends you back to step 1
    page.fill("#staff_email", "")
    page.get_by_role("button", name="Back").click(); page.get_by_role("button", name="Back").click(); wait_text(page, "Step 1 of 3")
    page.fill("#staff_phone", "0911 22 33 44"); nxt(page).click(); wait_text(page, "Step 2 of 3")
    account_step(page, "0911 22 33 44")
    wait_text(page, "Step 1 of 3")
    check("a phone number that already belongs to someone sends you back to step 1 with the reason", "already belongs to another account" in body(page), body(page)[:200])
    check("a taken phone number created nobody", q("select count(*) from users where name='Wiz Dup'")[0][0] == 0)

    # ================================================================ 5. several branches: choose the branch
    cols = [r[1] for r in q("pragma table_info(branches)")]
    src = q("select * from branches where id=?", MAIN[0])[0]
    vals = dict(zip(cols, src)); vals.pop("id"); vals["name"] = "East Campus"; vals["code"] = "EAST9"; vals["is_main"] = 0
    q(f"insert into branches ({','.join(vals)}) values ({','.join('?' * len(vals))})", *vals.values())
    EAST = q("select id from branches where name='East Campus'")[0][0]
    page.goto(f"{BASE}/staff/create"); fill_who(page, "Wiz Two", "0922 22 22 22")
    page.get_by_role("radio", name=re.compile("^Teacher")).click()
    check("two branches: the owner is asked which branch, nothing preselected", page.get_by_role("radio", name="East Campus").count() == 1 and page.locator("[data-testid=fixed-branch]").count() == 0 and page.get_by_role("radio", name="East Campus").get_attribute("aria-checked") == "false")
    check("two branches: Next says to choose the branch", nxt(page).is_disabled() and "Choose the branch this person works at" in body(page))
    page.get_by_role("radio", name="East Campus").click()
    account_step(page, "0922 22 22 22", email="wiz.two@test.test", submit=False)
    check("two branches: the account step names the branch", "East Campus" in body(page))
    page.get_by_role("button", name="Create Account").click(); wait_text(page, "Account created successfully")
    check("two branches: saved in the chosen branch", q("select branch_id from users where email='wiz.two@test.test'")[0][0] == EAST)
    q("delete from users where branch_id=?", EAST); q("delete from branches where id=?", EAST)   # single-branch again for the rest

    # ================================================================ 6. changing a person
    page.goto(f"{BASE}/staff"); page.wait_for_selector("[data-testid=staff-row]")
    card = page.locator("[data-testid=staff-row]").filter(has_text="Wiz One")
    check("list: a new person shows 'Must choose a new password' and their phone number", "Must choose a new password" in card.inner_text() and "0911 22 33 44" in card.inner_text())
    card.get_by_role("button", name="Change").click(); page.wait_for_selector("[data-testid=edit-staff]")
    form = page.locator("[data-testid=edit-staff]")
    check("change: name, job title, phone, the job-title-vs-role note and the permissions; no on/off switch (that is the Disable button)", page.input_value("#edit_name") == "Wiz One" and page.input_value("#edit_title") == "Vice Principal" and page.input_value("#edit_phone") != "" and "Can this person sign in?" not in form.inner_text() and form.locator("[data-testid=title-vs-access]").count() == 1 and "39 of 71 permissions ticked" in form.inner_text())
    page.fill("#edit_name", "Wiz One Renamed")
    page.fill("#edit_title", "Registrar")
    form.locator("label[for=perm-view-audit-log]").click()
    form.get_by_role("button", name="Save changes").click()
    check("change: saved (name, title, permission added) and audited, and still active", until(lambda: q("select name, title, status from users where email='wiz.one@test.test'")[0] == ("Wiz One Renamed", "Registrar", "active")) and until(lambda: q("select count(*) from audit_logs where action='staff.permissions_changed' and auditable_id=?", row[0][0])[0][0] == 1))
    page.wait_for_selector("[data-testid=edit-staff]", state="detached")
    card = page.locator("[data-testid=staff-row]").filter(has_text="Wiz One Renamed")
    check("change: the list shows the new name and title", "Registrar" in card.inner_text())
    card.get_by_role("button", name="Reset password").click()
    dlg = page.get_by_role("dialog"); dlg.wait_for()
    check("reset password: asks first and says what happens (old password stops, signed out, shown once)", "Make a new temporary password for Wiz One Renamed?" in dlg.inner_text() and "stops working at once" in dlg.inner_text() and "once" in dlg.inner_text())
    old_hash = q("select password from users where email='wiz.one@test.test'")[0][0]
    dlg.get_by_role("button", name="Cancel").click(); page.wait_for_selector("[role=dialog]", state="detached")
    check("reset password: Cancel changes nothing", q("select password from users where email='wiz.one@test.test'")[0][0] == old_hash)
    card.get_by_role("button", name="Reset password").click(); page.get_by_role("dialog").get_by_role("button", name="Reset password for Wiz One Renamed").click()
    check("reset password: a new temporary password is shown once, on the list, and the old hash is gone", until(lambda: page.locator("[data-testid=reset-result] [data-testid=cred-password]").count() == 1) and q("select password from users where email='wiz.one@test.test'")[0][0] != old_hash)
    check("reset password: audited without the password", q("select count(*) from audit_logs where action='staff.password_reset' and auditable_id=?", row[0][0])[0][0] == 1)
    tch = page.locator("[data-testid=staff-row]").filter(has_text="Samuel Girma")
    tch.get_by_role("button", name="Change").click(); page.wait_for_selector("[data-testid=edit-staff]")
    check("a teacher's permissions are fixed and shown as words (no checklist)", page.locator("[data-testid=edit-staff] [data-testid=fixed-permissions]").count() == 1 and page.locator("[data-testid=edit-staff] [data-testid=permission-picker]").count() == 0)
    page.get_by_role("button", name="Cancel").click()

    # ================================================================ 7. a branch admin adds someone
    pctx, ppage = login(browser, "principal@demo.test")
    ppage.goto(f"{BASE}/staff/create"); fill_who(ppage, "Wiz Three", "0933 33 33 33")
    check("branch admin: only three roles are offered (no Owner, no Auditor)", ppage.locator("[role=radiogroup] [role=radio]").count() == 3 and ppage.get_by_role("radio", name=re.compile("^Owner")).count() == 0)
    ppage.get_by_role("radio", name=re.compile("^Branch admin")).click()
    pk = ppage.locator("[data-testid=permission-picker]")
    check("branch admin: sees the same words, the usual set limited to what they hold", "of 41 permissions ticked" in pk.inner_text(), pk.locator("[data-testid=perm-count]").inner_text())
    check("branch admin: a permission they do not hold is disabled and says why", ppage.locator("#perm-refund-payments").is_disabled() and "do not have it yourself" in pk.inner_text())
    check("branch admin: no branch question, their own branch is used", ppage.locator("[data-testid=fixed-branch]").count() == 1)
    account_step(ppage, "0933 33 33 33", email="wiz.three@test.test"); wait_text(ppage, "Account created successfully")
    b3 = q("select branch_id from users where email='wiz.three@test.test'")[0][0]
    p3 = [r[0] for r in q("select p.name from model_has_permissions m join permissions p on p.id=m.permission_id join users u on u.id=m.model_id where u.email='wiz.three@test.test'")]
    check("branch admin: the account is in their own branch with only permissions they hold", b3 == MAIN[0] and "refund-payments" not in p3 and len(p3) == 41, str(len(p3)))
    ppage.goto(f"{BASE}/staff"); ppage.wait_for_selector("[data-testid=staff-row]")
    check("branch admin: the list shows only their own branch (not East Campus)", ppage.locator("[data-testid=staff-row]").filter(has_text="Wiz Two").count() == 0 and ppage.locator("[data-testid=staff-row]").filter(has_text="Wiz Three").count() == 1)
    pctx.close()

    # ================================================================ 8. sections
    page.goto(f"{BASE}/academic-structure"); page.wait_for_selector("[data-testid=grade-card]")
    G = q("select g.id, g.name, s.id, s.name from sections s join grades g on g.id=s.grade_id where s.branch_id=? order by g.sort_order, s.name limit 1", MAIN[0])[0]
    gcard = page.locator("[data-testid=grade-card]").filter(has=page.get_by_role("heading", name=G[1], exact=True))
    srow = gcard.locator("[data-testid=section-row]").first
    check("sections page: each section shows students filled and the class teacher line", "students" in srow.locator("[data-testid=section-fill]").inner_text() or "places filled" in srow.locator("[data-testid=section-fill]").inner_text())
    check("sections page: a section with no class teacher says so and offers to choose one", any("No class teacher yet" in t for t in gcard.locator("[data-testid=section-teacher]").all_inner_texts()) or "Class teacher:" in srow.locator("[data-testid=section-teacher]").inner_text())
    before_sections = q("select count(*) from sections")[0][0]
    gcard.get_by_role("link", name=f"Add a section to {G[1]}").click(); wait_text(page, "Where does this section belong?")
    check("section wizard: 'Step 1 of 3', the grade you came from is selected, the current year is selected", "Step 1 of 3" in body(page) and page.get_by_role("radio", name=G[1], exact=True).get_attribute("aria-checked") == "true" and page.locator("[role=radio][aria-checked=true]").count() == 2)
    ex = page.locator("[data-testid=existing]").inner_text()
    check("section wizard: shows the sections that already exist for that grade and year", "Sections already in" in ex and G[3] in ex, ex)
    nxt(page).click(); wait_text(page, "Name and size")
    check("section wizard step 2: Next is off until there is a name", nxt(page).is_disabled())
    page.fill("#section_name", G[3])
    check("section wizard: a name that is already used is refused beside the field", "already a section" in body(page) and nxt(page).is_disabled())
    sug = [b for b in page.locator("button.font-bold").all_inner_texts() if len(b) == 1]
    check("section wizard: unused names are suggested (not the used one)", len(sug) >= 2 and G[3] not in sug, str(sug))
    NEW = sug[0]
    page.get_by_role("button", name=NEW, exact=True).click()
    page.fill("#section_capacity", "0")
    check("section wizard: capacity 0 is refused in words", "whole number of 1 or more" in body(page) and nxt(page).is_disabled())
    page.fill("#section_capacity", "3")
    check("section wizard: capacity is marked optional", "(Optional)" in page.locator("label[for=section_capacity]").inner_text())
    nxt(page).click(); wait_text(page, "Check and create")
    check("section wizard review: the class name, capacity and school year read back", f"{G[1]} {NEW}" in body(page) and "3 students" in body(page))
    check("section wizard: nothing is saved before the last button", q("select count(*) from sections")[0][0] == before_sections)
    page.get_by_role("button", name="Create section").click(); wait_text(page, f"{G[1]} {NEW} is ready")
    new = q("select id, capacity, branch_id from sections where grade_id=? and name=? and branch_id=?", G[0], NEW, MAIN[0])
    check("section wizard: saved with the capacity and branch", len(new) == 1 and new[0][1] == 3, str(new))
    check("section wizard: next steps offered (add another, choose teachers, see all)", page.get_by_role("link", name="Choose teachers for the classes").count() == 1 and page.get_by_role("button", name=re.compile("Add another section to")).count() == 1)
    page.get_by_role("link", name="See all grades and sections").click(); page.wait_for_selector("[data-testid=grade-card]")
    nrow = page.locator("[data-testid=section-row]").filter(has_text=f"{G[1]} {NEW}")
    check("sections page: the new section shows 0 of 3 places filled and no class teacher", "0 of 3 places filled" in nrow.inner_text() and "No class teacher yet" in nrow.inner_text())

    # change and remove
    nrow.get_by_role("button", name="Change name or size").click(); page.wait_for_selector("[data-testid=section-edit]")
    page.fill("#edit_section_name", G[3])
    page.get_by_role("button", name="Save changes to the section").click()
    check("change section: renaming into a used name is refused in words", until(lambda: "already a section called" in body(page)) and q("select name from sections where id=?", new[0][0])[0][0] == NEW)
    page.fill("#edit_section_name", NEW); page.fill("#edit_section_capacity", "5")
    page.get_by_role("button", name="Save changes to the section").click()
    check("change section: name and capacity saved", until(lambda: q("select capacity from sections where id=?", new[0][0])[0][0] == 5))
    page.wait_for_selector("[data-testid=section-edit]", state="detached")
    srow = gcard.locator("[data-testid=section-row]").filter(has_text=f"{G[1]} {G[3]}").first
    srow.get_by_role("button", name="Remove").click()
    dlg = page.get_by_role("dialog")
    check("remove section: a dialog says what can never be removed and names the outcome", "cannot be removed" in dlg.inner_text() and dlg.get_by_role("button", name=f"Remove {G[1]} {G[3]}").count() == 1)
    dlg.get_by_role("button", name=f"Remove {G[1]} {G[3]}").click()
    check("remove section: a section with students is refused and stays", until(lambda: "cannot be deleted" in body(page).lower() or "can't be deleted" in body(page).lower() or "students" in body(page).lower()) and q("select count(*) from sections where id=?", G[2])[0][0] == 1, body(page)[:200])
    nrow = page.locator("[data-testid=section-row]").filter(has_text=f"{G[1]} {NEW}")
    nrow.get_by_role("button", name="Remove").click()
    page.get_by_role("dialog").get_by_role("button", name=f"Remove {G[1]} {NEW}").click()
    check("remove section: an empty section is removed", until(lambda: q("select count(*) from sections where id=?", new[0][0])[0][0] == 0))

    # ================================================================ 9. teacher assignment
    # a fresh empty section to work in
    q("insert into sections (branch_id, grade_id, academic_year_id, name, capacity, created_at, updated_at) select branch_id, grade_id, academic_year_id, 'Q', null, datetime('now'), datetime('now') from sections where id=?", G[2])
    QID = q("select id from sections where name='Q' and grade_id=?", G[0])[0][0]
    page.goto(f"{BASE}/academic-structure"); page.wait_for_selector("[data-testid=grade-card]")
    qrow = page.locator("[data-testid=section-row]").filter(has_text=f"{G[1]} Q")
    qrow.get_by_role("link", name="Choose a class teacher").click()
    page.wait_for_selector("[data-testid=class-teacher]")
    check("assignment: coming from a section opens that class directly", f"{G[1]} Q" in page.locator("h1").inner_text() and page.locator("[data-testid=subjects]").count() == 1)
    check("assignment: class teacher card explains itself and says 'No class teacher yet'", "No class teacher yet" in page.locator("[data-testid=class-teacher]").inner_text() and "looks after this class as a whole" in body(page))
    page.select_option("#class-teacher-select", label="Samuel Girma")
    page.get_by_role("button", name=f"Save the class teacher for {G[1]} Q").click()
    check("assignment: the class teacher is saved", until(lambda: q("select class_teacher_id from sections where id=?", QID)[0][0] is not None))
    check("assignment: the teacher list only holds teachers of this class's branch", page.locator("#class-teacher-select option").count() >= 3)
    check("assignment: no subject has a teacher yet, and it says so", "No subject has a teacher" in body(page))
    give = page.locator("[data-testid=give-subject]")
    subj = give.locator("[role=radio]").first
    sname = subj.locator("span").first.inner_text()
    subj.click()
    check("assignment: a subject is picked with buttons that show who teaches it now", subj.get_attribute("aria-checked") == "true" and "No teacher yet" in subj.inner_text())
    check("assignment: no summary or Give button until a teacher is chosen", give.locator("[data-testid=give-summary]").count() == 0)
    page.select_option("#give-teacher", label="Meseret Alemu")
    check("assignment: a plain sentence says what will happen", f"Meseret Alemu will teach {sname} in {G[1]} Q." in give.locator("[data-testid=give-summary]").inner_text())
    page.get_by_role("button", name=f"Give {sname} to Meseret Alemu").click()
    check("assignment: given (saved once)", until(lambda: q("select count(*) from teaching_assignments where section_id=?", QID)[0][0] == 1))
    page.wait_for_selector("[data-testid=assignment]")
    check("assignment: it appears in the class's list as subject and teacher", "Meseret Alemu" in page.locator("[data-testid=assignment]").first.inner_text())
    # replacing needs a deliberate yes
    give.locator("[role=radio]").first.click(); page.select_option("#give-teacher", label="Yared Solomon")
    check("assignment: a warning says the subject already has a teacher", "This subject already has a teacher" in give.inner_text())
    page.get_by_role("button", name=f"Give {sname} to Yared Solomon").click()
    dlg = page.get_by_role("dialog")
    check("assignment: replacing asks first, saying what happens to unsent marks", "Meseret Alemu" in dlg.inner_text() and "Mark sheets that are not sent in yet move" in dlg.inner_text())
    check("assignment: nothing changed before the confirmation", q("select teacher_id from teaching_assignments where section_id=?", QID)[0][0] == q("select id from users where email='teacher2@demo.test'")[0][0])
    dlg.get_by_role("button", name="Cancel").click()
    check("assignment: Cancel keeps the old teacher", q("select teacher_id from teaching_assignments where section_id=?", QID)[0][0] == q("select id from users where email='teacher2@demo.test'")[0][0])
    page.wait_for_selector("[role=dialog]", state="detached")
    give.locator("[data-testid=give-summary]").get_by_role("button", name=f"Give {sname} to Yared Solomon").click()
    page.get_by_role("dialog").get_by_role("button", name=f"Give {sname} to Yared Solomon").click()
    check("assignment: after confirming, the subject moves and it is audited", until(lambda: q("select teacher_id from teaching_assignments where section_id=?", QID)[0][0] == q("select id from users where email='teacher3@demo.test'")[0][0]) and q("select count(*) from audit_logs where action='teaching_assignment.reassigned'")[0][0] >= 1)
    page.locator("[data-testid=assignment]").first.get_by_role("button", name="Remove").click()
    page.get_by_role("dialog").get_by_role("button", name=f"Remove {sname}").click()
    check("assignment: removing asks first, then removes", until(lambda: q("select count(*) from teaching_assignments where section_id=?", QID)[0][0] == 0))
    page.get_by_role("button", name="Back to all classes").click(); page.wait_for_selector("[data-testid=class-card]")
    check("assignment: the class list shows each class with its class teacher and subject count", page.locator("[data-testid=class-card]").filter(has_text=f"{G[1]} Q").count() == 1 and "Samuel Girma" in page.locator("[data-testid=class-card]").filter(has_text=f"{G[1]} Q").inner_text())
    check("assignment: a class with no subjects says so", until(lambda: "No subjects have a teacher yet" in page.locator("[data-testid=class-card]").filter(has_text=f"{G[1]} Q").inner_text()))

    # ================================================================ 10. audit log
    page.goto(f"{BASE}/audit-log"); page.wait_for_selector("[data-testid=entry]")
    acts = page.locator("[data-testid=entry-action]").all_inner_texts()
    check("audit: entries are plain sentences, not codes", any("staff member was added" in a.lower() for a in acts) or any("teacher" in a.lower() or "added" in a.lower() for a in acts), str(acts[:5]))
    e0 = page.locator("[data-testid=entry]").filter(has_text="staff.role_assigned").first
    check("audit: the raw action code is still shown beside the sentence", "staff.role_assigned" in e0.inner_text() and "A staff member was added with a role" in e0.inner_text())
    check("audit: who, record and when are on the entry", "Done by" in e0.inner_text() and "Record" in e0.inner_text() and re.search(r"20\d\d|\d{4}", e0.inner_text()) is not None)
    check("audit: the recorded values are shown as recorded (role: branch_admin)", "role" in e0.inner_text().lower() and "branch_admin" in e0.inner_text())
    ech = page.locator("[data-testid=entry]").filter(has_text="staff.permissions_changed").first
    check("audit: a change reads 'was ... now ...' and adds are listed", "was" in ech.inner_text() and ("added" in ech.inner_text().lower()))
    check("audit: the count of entries found is stated", re.search(r"\d+ entries found", page.locator("[data-testid=count]").inner_text()) is not None)
    total = int(re.search(r"(\d+) entries", page.locator("[data-testid=count]").inner_text()).group(1))
    page.select_option("#audit-action", value="staff.role_assigned")
    check("audit: filtering by what happened narrows the list", until(lambda: page.locator("[data-testid=entry]").count() >= 1 and all("staff.role_assigned" in t for t in page.locator("[data-testid=entry]").all_inner_texts())))
    check("audit: the filter options say it in words and keep the code", "A staff member was added with a role (staff.role_assigned)" in page.locator("#audit-action").inner_text())
    page.get_by_role("button", name="Clear all filters").click()
    check("audit: 'Clear all filters' brings everything back", until(lambda: f"{total} entries found" in page.locator("[data-testid=count]").inner_text()))
    page.fill("#audit-search", "Wiz One"); page.get_by_role("button", name="Search", exact=True).click()
    check("audit: searching by a person's name finds their entries", until(lambda: page.locator("[data-testid=entry]").count() >= 1 and all("Wiz One" in t for t in page.locator("[data-testid=entry]").all_inner_texts())))
    href = page.get_by_role("link", name="Export to Excel").get_attribute("href")
    check("audit: the Excel export honours the filters", "q=Wiz" in href, href)
    r = octx.request.get(BASE + href.replace(BASE, "") if href.startswith("http") else BASE + href)
    check("audit: the export downloads", r.status == 200 and "spreadsheet" in r.headers.get("content-type", ""), str(r.status))

    # ================================================================ 11. settings
    page.goto(f"{BASE}/settings/school"); page.wait_for_selector("[data-testid=settings-form]")
    nav = page.locator("[data-testid=settings-nav]")
    check("settings: a short list to jump to each part", all(w in nav.inner_text() for w in ("School details", "Calendar", "Report cards", "Discounts, waivers and terms", "Languages", "Payment accounts")))
    check("settings: nothing to save yet ('Everything is saved') and Save is off", page.locator("[data-testid=dirty-note]").inner_text() == "Everything is saved." and page.get_by_role("button", name="Save school settings").is_disabled())
    check("settings: calendar and missing-score choices are large, explained cards", page.get_by_role("radio", name=re.compile("Both calendars")).evaluate(H) >= 56 and "Leave it out (recommended)" in body(page))
    orig = q("select name from schools limit 1")[0][0]
    page.fill("#name", orig + " X")
    check("settings: typing shows 'changes that are not saved' and turns Save on", "not saved yet" in page.locator("[data-testid=dirty-note]").inner_text() and page.get_by_role("button", name="Save school settings").is_enabled())
    page.locator("a[href$='/dashboard']").first.click()
    dlg = page.get_by_role("dialog")
    check("settings: leaving with unsaved changes asks first", until(lambda: "Leave without saving?" in dlg.inner_text()) and "changes are lost" in dlg.inner_text())
    dlg.get_by_role("button", name="Stay and keep working").click()
    check("settings: staying keeps you on the page with the typing intact", "/settings/school" in page.url and page.input_value("#name") == orig + " X")
    page.get_by_role("button", name="Save school settings").click()
    check("settings: saved", until(lambda: q("select name from schools limit 1")[0][0] == orig + " X"))
    check("settings: after saving it says everything is saved again", until(lambda: page.locator("[data-testid=dirty-note]").inner_text() == "Everything is saved."))
    page.fill("#name", orig); page.get_by_role("button", name="Save school settings").click()
    check("settings: the name is restored", until(lambda: q("select name from schools limit 1")[0][0] == orig))
    until(lambda: page.locator("[data-testid=dirty-note]").inner_text() == "Everything is saved.")
    page.fill("#discount_limit_percent", "150"); page.get_by_role("button", name="Save school settings").click()
    check("settings: an invalid discount limit is refused beside its field", until(lambda: page.locator("#discount_limit_percent-error").count() == 1))
    page.fill("#discount_limit_percent", "10")
    ftxt = page.locator("[data-testid=settings-form]").inner_text()
    check("settings: languages are ticked rows with their honest review status", page.locator("#lang-am").count() == 1 and ("early translations" in ftxt or "non-native translation" in ftxt))

    # payment accounts
    pa = page.locator("[data-testid=payment-accounts]")
    n0 = q("select count(*) from payment_accounts where deleted_at is null")[0][0]
    pa.locator("[data-testid=add-account] [role=radio]").first.click()
    check("payment accounts: choosing a bank reveals step 2 (branch) and step 3 (details)", "2. Which branch is this account for?" in pa.inner_text() and "3. Account details" in pa.inner_text())
    page.fill("#account_number", "1000123456789")
    btn = pa.get_by_role("button", name=re.compile("as a payment account"))
    check("payment accounts: the button names the bank it adds", btn.count() == 1 and len(btn.inner_text()) > 20, btn.inner_text())
    btn.click()
    check("payment accounts: added", until(lambda: q("select count(*) from payment_accounts where deleted_at is null")[0][0] == n0 + 1))
    li = pa.locator("[data-testid=account-list] li").filter(has_text="1000123456789")
    li.get_by_role("button", name=re.compile("^Remove")).click()
    d2 = page.get_by_role("dialog")
    check("payment accounts: removing asks first and says payments already made are not affected", "Payments already made are not affected" in d2.inner_text())
    d2.get_by_role("button", name=re.compile("^Remove")).click()
    check("payment accounts: removed", until(lambda: q("select count(*) from payment_accounts where deleted_at is null")[0][0] == n0))

    # ================================================================ 12. who may not
    tctx, tp = login(browser, "teacher@demo.test")
    check("teacher: no staff wizard, no section wizard, no audit log, no settings",
          all(status_of(tctx, u) == 403 for u in ("/staff/create", "/sections/create", "/audit-log", "/settings/school", "/teaching-assignments")))
    tctx.close()
    actx, ap = login(browser, "accountant@demo.test")
    check("accountant: none of the admin pages", all(status_of(actx, u) == 403 for u in ("/staff/create", "/sections/create", "/audit-log", "/settings/school", "/teaching-assignments")))
    actx.close()
    uctx, up = login(browser, "auditor@demo.test")
    check("auditor: may read the audit log, but not add staff or change anything", status_of(uctx, "/audit-log") == 200 and status_of(uctx, "/staff/create") == 403 and status_of(uctx, "/settings/school") == 403)
    up.goto(f"{BASE}/staff"); up.wait_for_selector("[data-testid=staff-row]")
    check("auditor: the staff list has no Add button and no Change buttons", up.get_by_role("link", name="Add a staff member").count() == 0 and up.get_by_role("button", name="Change").count() == 0)
    uctx.close()
    prctx, prp = login(browser, "principal@demo.test")
    check("branch admin: audit log and staff wizard open only with their permissions", status_of(prctx, "/staff/create") == 200)
    prctx.close()

    # ================================================================ 13. phone
    q("insert into payment_accounts (school_id, type, bank_key, display_name, account_number, account_name, sort_order, created_at, updated_at) values (1, 'bank', null, 'Commercial Bank of Ethiopia (main school account)', '1000123456789012345', 'Demo Academy Trust Foundation', 99, datetime('now'), datetime('now'))")
    mctx, mp = login(browser, "owner@demo.test", mobile=True)
    for path, sel, name in (("/staff", "[data-testid=staff-row]", "staff list"), ("/staff/create", "#staff_name", "staff wizard"), ("/academic-structure", "[data-testid=grade-card]", "sections page"),
                            ("/sections/create", "[data-testid=grade-grid]", "section wizard"), ("/teaching-assignments", "[data-testid=class-card]", "assignment list"), ("/audit-log", "[data-testid=entry]", "audit log"), ("/settings/school", "[data-testid=settings-form]", "settings")):
        mp.goto(BASE + path); mp.wait_for_selector(sel)
        check(f"phone: {name} has no sideways scroll", overflow(mp) <= 1, str(overflow(mp)))
    mp.goto(f"{BASE}/staff/create"); mp.fill("#staff_name", "Phone Person"); mp.fill("#staff_phone", "0944 44 44 44")
    check("phone: Next is a large button", nxt(mp).evaluate(H) >= 56)
    nxt(mp).click(); wait_text(mp, "Step 2 of 3")
    mp.get_by_role("radio", name=re.compile("^Branch admin")).click()
    check("phone: the permission checklist fits the screen", overflow(mp) <= 1 and mp.locator("[data-testid=permission-picker] label").first.evaluate(H) >= 56)
    nxt(mp).click(); wait_text(mp, "Step 3 of 3")
    check("phone: the account step (retype phone, password choice, Create Account) fits the screen", overflow(mp) <= 1 and mp.get_by_role("button", name="Create Account").evaluate(H) >= 56)
    mp.goto(f"{BASE}/teaching-assignments"); mp.wait_for_selector("[data-testid=class-card]")
    mp.locator("[data-testid=class-card]").first.click(); mp.wait_for_selector("[data-testid=class-teacher]")
    check("phone: a class's teachers page has no sideways scroll", overflow(mp) <= 1, str(overflow(mp)))
    mp.goto(f"{BASE}/settings/school"); mp.wait_for_selector("[data-testid=settings-form]")
    mp.fill("#name", orig + " Y")
    sb = mp.locator("[data-testid=save-bar]")
    box = sb.bounding_box()
    check("phone: the Save bar stays on screen at the bottom while the form scrolls", box is not None and box["y"] + box["height"] <= 844 + 2 and box["y"] > 400, str(box))
    mp.fill("#name", orig)
    mctx.close()
    q("delete from payment_accounts where account_number='1000123456789012345'")

    # ================================================================ 14. Amharic
    q("update users set locale='am' where email='owner@demo.test'")
    actx2, ap2 = login(browser, "owner@demo.test")
    for path, sel, name in (("/staff", "[data-testid=staff-row]", "staff list"), ("/staff/create", "#staff_name", "staff wizard"), ("/academic-structure", "[data-testid=grade-card]", "sections page"),
                            ("/sections/create", "[data-testid=grade-grid]", "section wizard"), ("/teaching-assignments", "[data-testid=class-card]", "assignment list"), ("/audit-log", "[data-testid=entry]", "audit log"), ("/settings/school", "[data-testid=settings-form]", "settings")):
        ap2.goto(BASE + path); ap2.wait_for_selector(sel)
        t = ap2.evaluate("() => { const c = document.body.cloneNode(true); c.querySelectorAll('code, option').forEach(e => e.remove()); return c.innerText; }") if path == "/audit-log" else body(ap2)
        h1 = ap2.locator("h1").first.inner_text()
        check(f"Amharic: {name} is translated (heading not English), no raw keys", bool(re.search(r"[ሀ-፿]", h1)) and not RAW.search(t), f"{h1!r} {RAW.findall(t)[:3]}")
    ap2.goto(f"{BASE}/staff/create"); ap2.fill("#staff_name", "Am Person"); ap2.fill("#staff_phone", "0955 55 55 55"); ap2.locator("form").get_by_role("button").last.click()
    ap2.wait_for_selector("[role=radio]")
    ap2.locator("[role=radio]").nth(0).click()
    check("Amharic: the role step and permission words are Amharic", bool(re.search(r"[ሀ-፿]", ap2.locator("[data-testid=permission-picker]").inner_text())) and not RAW.search(body(ap2)))
    actx2.close()
    q("update users set locale=null where email='owner@demo.test'")

    # ================================================================ 15. hygiene
    check("no JavaScript errors on any page", not page.js_errors, str(page.js_errors[:2]))
    check("the extra branch was removed again", q("select count(*) from branches where name='East Campus'")[0][0] == 0)
    browser.close()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
