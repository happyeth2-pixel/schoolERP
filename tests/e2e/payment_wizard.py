"""Browser test: the parent's guided payment-proof wizard (resources/js/Components/PaymentProofWizard.jsx).

Drives the REAL app in a phone-sized Chromium (Playwright) and checks behaviour, tap-target sizes,
server-error handling, Back-keeps-answers, and layout in Amharic/Oromo/Tigrinya. Not part of
`php artisan test` -- it needs a running server -- so run it by hand (and before a release):

    # 1. throwaway database, NEVER the dev or production one
    export DB_CONNECTION=sqlite DB_DATABASE=/abs/path/uat.sqlite   # create the empty file first
    php artisan migrate --force && php artisan db:seed --force
    php artisan tinker --execute="require base_path('tests/e2e/seed_parent_invoice.php');"
    # 2. serve it (file sessions/cache so the sqlite DB needs no extra tables)
    SESSION_DRIVER=file CACHE_STORE=file APP_URL=http://127.0.0.1:8011 php artisan serve --port=8011
    # 3. run this (pip install playwright && playwright install chromium)
    E2E_DB=/abs/path/uat.sqlite python tests/e2e/payment_wizard.py

It DELETES all rows in that database's payments table at the start and switches the demo parent's
language while running, so only ever point it at the throwaway database above.
"""
import base64, os, sqlite3, sys
from playwright.sync_api import sync_playwright

import tempfile
SC = tempfile.mkdtemp(prefix="payment_wizard_e2e_")  # fixtures + screenshots land here
BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
results = []

def check(name, ok, detail=""):
    results.append((name, ok, detail))
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))

PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==")
open(f"{SC}/receipt.png", "wb").write(PNG)
open(f"{SC}/virus.exe", "wb").write(b"MZ")
open(f"{SC}/big.pdf", "wb").write(b"%PDF-1.4\n" + b"0" * (6 * 1024 * 1024))
open(f"{SC}/fake.png", "wb").write(b"this is not really an image")

def rows():
    con = sqlite3.connect(DB)
    r = con.execute("select amount, method, reference_number, status, proof_path from payments").fetchall()
    con.close()
    return r

_c = sqlite3.connect(DB); _c.execute("delete from payments"); _c.execute("update users set locale=null where email='parent@demo.test'"); _c.commit(); _c.close()  # scratch DB only
with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True)
    page = ctx.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))

    page.goto(f"{BASE}/login")
    page.fill("#phone", "parent@demo.test")
    page.fill("#password", "password")
    page.get_by_role("button", name="Log in").click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=15000)
    page.goto(f"{BASE}/invoices/1")
    page.wait_for_selector("text=Tell the school you paid", timeout=15000)
    page.locator("text=Tell the school you paid").scroll_into_view_if_needed()
    page.screenshot(path=f"{SC}/pw_step1.png", full_page=True)

    next_btn = lambda: page.get_by_role("button", name="Next")
    check("with no payment accounts configured the intro does not point at 'above'", page.locator("text=Pay the school in the way it has told you").count() == 1 and "details above" not in page.inner_text("form"))
    check("starts on step 1 of 4 with 'How did you pay?'", page.locator("text=Step 1 of 4").count() == 1 and page.locator("h3:has-text('How did you pay?')").count() == 1)
    cards = page.locator("button[role=radio]")
    check("four method cards", cards.count() == 4)
    heights = [cards.nth(i).bounding_box()["height"] for i in range(4)]
    check("method cards are >= 56px tall", min(heights) >= 56, f"min {min(heights):.0f}px")
    check("Next disabled until a method is chosen", next_btn().is_disabled())
    check("no method is pre-selected", page.locator("button[role=radio][aria-checked=true]").count() == 0)

    cards.nth(1).click()
    check("choosing a card selects it and enables Next", cards.nth(1).get_attribute("aria-checked") == "true" and next_btn().is_enabled())
    nb = next_btn().bounding_box()
    check("Next button >= 56px tall", nb["height"] >= 56, f"{nb['height']:.0f}px")
    next_btn().click()

    # step 2: amount
    page.wait_for_selector("text=Step 2 of 4")
    check("step 2 asks how much", page.locator("h3:has-text('How much did you pay?')").count() == 1)
    check("shows balance owed", page.locator("text=1,000.00 ETB").count() >= 1)
    check("Next disabled with empty amount", next_btn().is_disabled())
    page.fill("#amount", "1500")
    check("over-balance amount: plain-language error + Next disabled", page.locator("text=can't pay more than the amount still owed").count() == 1 and next_btn().is_disabled())
    page.fill("#amount", "0")
    check("zero amount blocked", next_btn().is_disabled())
    page.get_by_role("button", name="I paid the full amount").click()
    check("'paid the full amount' fills the balance", page.input_value("#amount") == "1000")
    page.fill("#amount", "400.50")
    check("valid partial amount enables Next", next_btn().is_enabled())
    # Enter in the amount field must advance, not submit half a form
    page.press("#amount", "Enter")
    page.wait_for_selector("text=Step 3 of 4")
    check("Enter key moves to next step, sends nothing", len(rows()) == 0)

    # step 3: proof
    check("step 3 title", page.locator("h3:has-text('Add your proof')").count() == 1)
    check("Next disabled with no file", next_btn().is_disabled())
    fi = page.locator("input[type=file]")
    fi.set_input_files(f"{SC}/virus.exe")
    check("wrong file type: plain error + Next disabled", page.locator("text=file type isn't allowed").count() == 1 and next_btn().is_disabled())
    fi.set_input_files(f"{SC}/big.pdf")
    check("file over 5 MB: plain error + Next disabled", page.locator("text=too big").count() == 1 and next_btn().is_disabled())
    fi.set_input_files(f"{SC}/fake.png")
    check("looks-like-a-png passes client check", page.locator("text=fake.png").count() == 1 and next_btn().is_enabled())
    page.fill("#reference_number", "FT26-UAT-778")
    next_btn().click()

    # step 4: review then server rejects fake.png -> should jump back to step 3 with the server message
    page.wait_for_selector("text=Step 4 of 4")
    body = page.inner_text("form")
    check("review shows method, amount, reference, file", all(s in body for s in ["Mobile money", "400.50 ETB", "FT26-UAT-778", "fake.png"]))
    check("review explains what happens next", "what happens next" in body.lower())
    page.screenshot(path=f"{SC}/pw_step4.png", full_page=True)
    check("arriving at the review step sends NOTHING by itself", page.get_by_role("button", name="Sending").count() == 0 and len(rows()) == 0)
    send = page.get_by_role("button", name="Send to school")
    check("final button is 'Send to school' and >= 56px", send.count() == 1 and send.evaluate("e => e.offsetHeight") >= 56, f"layout height={send.evaluate('e => e.offsetHeight')}px (visual box {send.bounding_box()['height']:.1f}px is scaled by the card tilt effect)")
    send.click()
    page.wait_for_selector("text=Step 3 of 4", timeout=15000)
    check("server rejected the disguised file, nothing was saved", len(rows()) == 0, str(rows()))
    err = page.inner_text("form")
    check("jumped back to the proof step and shows the server's message beside the field", "Step 3 of 4" in err and ("valid" in err.lower() or "invalid" in err.lower() or "file" in err.lower()), err[-200:].replace("\n", " "))
    page.screenshot(path=f"{SC}/pw_server_error.png", full_page=True)
    check("the refused file is cleared and Next is disabled until a new one is chosen", page.locator("form img").count() == 0 and page.locator("text=fake.png").count() == 0 and next_btn().is_disabled())
    check("earlier answers survived the round trip (reference kept)", page.input_value("#reference_number") == "FT26-UAT-778")

    # Back preserves data
    page.get_by_role("button", name="Back").click()
    page.wait_for_selector("text=Step 2 of 4")
    check("Back keeps the amount", page.input_value("#amount") == "400.5" or page.input_value("#amount") == "400.50")
    page.get_by_role("button", name="Back").click()
    page.wait_for_selector("text=Step 1 of 4")
    check("Back keeps the chosen method", page.locator("button[role=radio][aria-checked=true]").count() == 1)
    next_btn().click(); page.wait_for_selector("text=Step 2 of 4")
    next_btn().click(); page.wait_for_selector("text=Step 3 of 4")

    # now a real file
    page.locator("input[type=file]").set_input_files(f"{SC}/receipt.png")
    check("image shows a preview thumbnail", page.locator("form img").count() == 1)
    next_btn().click(); page.wait_for_selector("text=Step 4 of 4")
    page.get_by_role("button", name="Send to school").click()
    page.wait_for_selector("text=Your proof was sent", timeout=15000)
    check("success panel shown in place (not just a toast)", page.locator("[role=status]:has-text('Your proof was sent')").count() == 1)
    r = rows()
    check("exactly one pending payment stored with the right values", len(r) == 1 and r[0][0] == 400.5 and r[0][1] == "mobile_money" and r[0][2] == "FT26-UAT-778" and r[0][3] == "pending", str(r))
    page.screenshot(path=f"{SC}/pw_done.png", full_page=True)
    check("form is hidden after success (no accidental double-send)", page.locator("text=Step 1 of 4").count() == 0)
    page.get_by_role("button", name="Send another proof").click()
    check("'Send another proof' restarts at step 1, empty", page.locator("text=Step 1 of 4").count() == 1 and page.locator("button[role=radio][aria-checked=true]").count() == 0)
    check("no JavaScript errors during the whole flow", not js_errors, "; ".join(js_errors))

    # desktop layout sanity
    d = browser.new_context(viewport={"width": 1280, "height": 900}).new_page()
    d.goto(f"{BASE}/login"); d.fill("#phone", "parent@demo.test"); d.fill("#password", "password"); d.get_by_role("button", name="Log in").click()
    d.wait_for_url(lambda u: "/login" not in u); d.goto(f"{BASE}/invoices/1")
    d.wait_for_selector("text=Tell the school you paid")
    d.screenshot(path=f"{SC}/pw_desktop.png", full_page=True)
    check("desktop renders the wizard", d.locator("text=Step 1 of 4").count() == 1)
    # text expansion / script rendering in each language: no horizontal overflow, nothing clipped
    for loc in ["am", "om", "ti"]:
        con = sqlite3.connect(DB); con.execute("update users set locale=? where email='parent@demo.test'", (loc,)); con.commit(); con.close()
        lp = browser.new_context(viewport={"width": 390, "height": 844}, has_touch=True, is_mobile=True).new_page()
        lp.goto(f"{BASE}/login"); lp.fill("#phone", "parent@demo.test"); lp.fill("#password", "password"); lp.get_by_role("button").filter(has_text=__import__("re").compile("log in|ግባ|seeni|እቶ", __import__("re").I)).first.click()
        lp.wait_for_url(lambda u: "/login" not in u); lp.goto(f"{BASE}/invoices/1")
        lp.wait_for_selector("button[role=radio]", timeout=15000)
        lp.locator("button[role=radio]").nth(0).click()
        lp.screenshot(path=f"{SC}/pw_{loc}_step1.png", full_page=True)
        lp.get_by_role("button").filter(has=lp.locator("xpath=.")).last  # no-op
        overflow1 = lp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
        lp.locator("form button:not([role=radio])").last.click()
        lp.wait_for_selector("#amount")
        lp.fill("#amount", "1500")
        lp.screenshot(path=f"{SC}/pw_{loc}_step2_error.png", full_page=True)
        overflow2 = lp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
        english_left = lp.locator("form").inner_text()
        check(f"{loc}: no horizontal overflow on step 1 and 2 (mobile)", overflow1 <= 0 and overflow2 <= 0, f"{overflow1}/{overflow2}px")
        check(f"{loc}: wizard is translated (no English step title left)", "How did you pay?" not in english_left and "How much did you pay?" not in english_left and "Pay the school" not in english_left)
    _c = sqlite3.connect(DB); _c.execute("update users set locale=null where email='parent@demo.test'"); _c.commit(); _c.close()  # leave the demo parent in English
    browser.close()

fails = [r for r in results if not r[1]]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
