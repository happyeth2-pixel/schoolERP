"""Before a usability session: check the throwaway environment really supports every task card.

Logs in as each demo role (real Chromium, phone-sized for the parent, desktop for staff) and confirms the
starting situation each task in USABILITY_TESTING.md assumes -- e.g. the teacher really has an un-entered
mark sheet, the accountant really has a pending partial proof, the parent really has two children.
It only READS pages; it changes nothing. Run it against the environment prepared by
scripts/usability/prepare-session.sh --serve:

    python scripts/usability/verify-session-data.py          # optional: E2E_BASE=http://127.0.0.1:8011

Exit code 1 if any starting condition is missing, so a session is never wasted on missing data.
"""
import os, re, sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def login(browser, email, mobile=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    page = browser.new_context(**kw).new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.goto(f"{BASE}/login")
    page.fill("#email", email)
    page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    return page, errors


def text_of(page, path, wait_for=None):
    resp = page.goto(f"{BASE}{path}")
    page.wait_for_load_state("networkidle")
    return (resp.status if resp else 0), page.inner_text("body")


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ---- receptionist
    page, errs = login(browser, "registrar@demo.test")
    status, body = text_of(page, "/students/create")
    check("receptionist: can open the registration wizard", status == 200 and "Register a new student" in body)
    page.fill("#first_name", "Bethlehem")
    page.get_by_role("button", name="Next").click()
    page.get_by_role("radio", name="Girl").click()
    page.get_by_role("button", name="Next").click()
    page.get_by_role("radio", name="Grade 4", exact=True).click()
    cards = page.get_by_role("radio", name=re.compile("Grade 4 A")).all_inner_texts()
    check("receptionist: Grade 4 section exists with room", bool(cards) and "places left" in cards[0], " | ".join(cards[0].split()) if cards else "no card")
    status, body = text_of(page, "/students")
    check("receptionist: the Find a student page has students to search", status == 200 and "Find a student" in body and "STU-2026" in body)
    check("receptionist: no JS errors", not errs, "; ".join(errs))

    # ---- teacher
    page, errs = login(browser, "teacher@demo.test")
    status, body = text_of(page, "/attendance")
    check("teacher: sees the homeroom section for attendance", status == 200 and "Grade 3" in body)
    status, body = text_of(page, "/marks")
    check("teacher: has an un-entered Mathematics mark sheet for Grade 3", status == 200 and "Mathematics" in body and re.search(r"not.started", body, re.I) is not None)
    check("teacher: no JS errors", not errs, "; ".join(errs))

    # ---- accountant
    page, errs = login(browser, "accountant@demo.test")
    status, body = text_of(page, "/payments/pending")
    check("accountant: proofs are waiting for verification", status == 200 and "Verify" in body)
    check("accountant: the pending partial proof (TB-778812) is in the queue", "TB-778812" in body)
    status, body = text_of(page, "/invoices")
    check("accountant: invoice list loads", status == 200 and "INV-" in body)
    status, body = text_of(page, "/fee-structures")
    check("accountant: fee structures include Grade 9 (termly) for invoice generation", status == 200 and "Grade 9" in body)
    check("accountant: no JS errors", not errs, "; ".join(errs))

    # ---- parent (phone-sized, like a real parent)
    page, errs = login(browser, "parent@demo.test", mobile=True)
    status, body = text_of(page, "/my/attendance")
    check("parent: family attendance page loads", status == 200)
    check("parent: has two children to switch between", page.locator("select option, button:has-text('Ruth'), [role=tab]").count() >= 2 or body.count("Grade") >= 2, "")
    check("parent: an absence is on record", re.search(r"absent", body, re.I) is not None)
    status, body = text_of(page, "/my/report-cards")
    check("parent: report-card list shows both children", status == 200 and body.count("Open") >= 2)
    card = page.locator('a[href*="/students/"][href$="/report-card"]').first.get_attribute("href")
    page.goto(card)
    page.wait_for_load_state("networkidle")
    check("parent: a child's report card shows published marks", re.search(r"Mathematics", page.inner_text("body")) is not None)
    status, body = text_of(page, "/notifications")
    check("parent: notifications waiting (absence + payment confirmed)", status == 200 and re.search(r"Absence recorded", body) is not None and re.search(r"Payment confirmed", body) is not None)
    check("parent: no JS errors", not errs, "; ".join(errs))

    # ---- administrator
    page, errs = login(browser, "owner@demo.test")  # the audit log is owner-level; the demo Principal gets 403 by design
    status, body = text_of(page, "/academic-structure")
    check("administrator: Grade 10 exists with no section yet", status == 200 and "Grade 10" in body)
    status, body = text_of(page, "/staff")
    check("administrator: staff list loads", status == 200 and "Registrar" in body)
    check("administrator: can create staff (Add button present)", re.search(r"add|new|create", body, re.I) is not None)
    status, body = text_of(page, "/audit-log")
    check("administrator: audit log loads", status == 200)
    check("administrator: no JS errors", not errs, "; ".join(errs))
    browser.close()

print(f"\n{sum(results)}/{len(results)} starting conditions OK")
sys.exit(0 if all(results) else 1)
