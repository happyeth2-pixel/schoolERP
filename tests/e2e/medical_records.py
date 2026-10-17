"""Browser test: Academic Expansion Phase 4 -- Student Medical Records. The brief's own three explicit
requirements, each checked live in the browser: permission (separate from manage-students), audit, and
never shown in the ordinary student list.

  * a registrar (manage-students, but no medical permission) cannot open the medical record page at all,
    and never even sees the "Medical Record" button on the student's profile
  * a branch_admin who DOES hold medical-records-manage (the default for that tier) sees the button, opens
    the page, saves a record, and sees it come back
  * an auditor can open the page read-only (no save control) but cannot write to it
  * searching the ordinary student list for the saved allergy text finds nothing -- it never left the
    medical page

Uses the usability-session data; writes to one real demo student's medical record, cleared at the end. Only
run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/medical_records.py
"""
import os, re, sqlite3, sys, time
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []
SENTINEL = "E2E-Severe-Peanut-Allergy-Sentinel"


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
    q("delete from student_medical_records where allergies like ?", f"%{SENTINEL}%")


cleanup()
STUDENT_ID, STUDENT_NAME = q(
    "select s.id, s.first_name from students s join enrollments e on e.student_id=s.id "
    "where e.status='active' order by s.id limit 1"
)[0]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # -- registrar: manage-students, but no medical permission at all --
    rctx, rp = login(browser, "registrar@demo.test")
    check("registrar: the medical route is refused outright", status_of(rctx, f"/students/{STUDENT_ID}/medical") == 403)
    rp.goto(f"{BASE}/students/{STUDENT_ID}/edit")
    check("registrar: no 'Medical Record' button on the profile at all", rp.get_by_role("link", name=re.compile("medical record", re.I)).count() == 0)
    rctx.close()

    # -- branch_admin: holds medical-records-manage by default -- sees the button, saves a record --
    hctx, hp = login(browser, "principal@demo.test")
    hp.goto(f"{BASE}/students/{STUDENT_ID}/edit")
    check("branch_admin: the 'Medical Record' button is shown", until(lambda: hp.get_by_role("link", name=re.compile("medical record", re.I)).count() > 0))
    hp.get_by_role("link", name=re.compile("medical record", re.I)).click()
    hp.wait_for_url(re.compile(r"/medical$"))
    check("medical page: the right student's name is shown", STUDENT_NAME in body(hp))

    hp.fill("#med_allergies", SENTINEL)
    hp.fill("#med_blood_type", "O+")
    hp.get_by_role("button", name=re.compile("save medical record", re.I)).click()
    check("medical page: saving confirms success", until(lambda: "saved" in body(hp).lower()))
    hctx.close()

    check("db: the record was actually written", q(
        "select allergies, blood_type from student_medical_records where student_id=?", STUDENT_ID,
    )[0][0] == SENTINEL)

    # -- auditor: read-only, no explicit permission needed, but cannot write --
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/students/{STUDENT_ID}/medical")
    # the allergy is a textarea's VALUE, not page text -- innerText() never shows a form control's value
    check("auditor: can open the page and see the saved allergy", until(lambda: ap.locator("#med_allergies").input_value() == SENTINEL))
    check("auditor: no save button (read only)", ap.get_by_role("button", name=re.compile("save medical record", re.I)).count() == 0)
    actx.close()

    # -- the ordinary student list never shows medical content --
    h2ctx, h2p = login(browser, "principal@demo.test")
    h2p.goto(f"{BASE}/students?search={SENTINEL}")
    check("the ordinary student list never contains the allergy text", SENTINEL not in body(h2p))
    h2ctx.close()

    # ================================================================ phone viewport
    mctx = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    mpage = mctx.new_page()
    mpage.goto(f"{BASE}/login")
    mpage.fill("#phone", "accountant@demo.test"); mpage.fill("#password", "password")
    mpage.get_by_role("button", name=re.compile("log in", re.I)).click()
    mpage.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    mpage.goto(f"{BASE}/students/{STUDENT_ID}/medical")
    mpage.wait_for_load_state("networkidle")
    check("phone: a student's medical record has no page-level horizontal overflow", mpage.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth") <= 2)
    mctx.close()

    browser.close()

cleanup()

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
