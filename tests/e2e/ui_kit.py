"""Browser test: the shared UX system (resources/js/Components/ui) on its live gallery, /dev/ui-kit.

Checks behaviour (busy buttons, choices, alerts, the confirm dialog's focus and Escape handling, the wizard's
validation/Back/Change/no-auto-submit), touch-target sizes on a phone, the print stylesheet, WCAG-AA colour
contrast of badges/alerts/buttons in light AND dark mode, missing translations in all four languages, and
horizontal overflow. Needs a running app; run by hand before a release (see payment_wizard.py's docstring for
how to build a throwaway database and serve it):

    E2E_DB=/abs/path/uat.sqlite python tests/e2e/ui_kit.py        # E2E_DB only needed for the language pass

It logs in as the demo parent (parent@demo.test) and, for the language pass, sets that user's locale in the
throwaway database -- so only ever point it at the throwaway database.
"""
import os, re, sqlite3, sys, tempfile
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ.get("E2E_DB")
SHOTS = tempfile.mkdtemp(prefix="ui_kit_e2e_")
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def new_page(browser, mobile=True, dark=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    ctx = browser.new_context(**kw)
    if dark:
        ctx.add_init_script("localStorage.setItem('theme','dark')")
    page = ctx.new_page()
    page.js_errors, page.console_problems = [], []
    page.on("pageerror", lambda e: page.js_errors.append(str(e)))
    page.on("console", lambda m: page.console_problems.append(m.text) if m.type in ("error", "warning") else None)
    return page


def login(page):
    page.goto(f"{BASE}/login")
    page.fill("#phone", "parent@demo.test")
    page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in|ግባ|seeni|እቶ", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    page.goto(f"{BASE}/dev/ui-kit")
    page.wait_for_selector("#buttons", timeout=20000)


H = "e => Math.round(e.offsetHeight)"

# WCAG contrast of an element's text against its composited background (walks up through translucent layers)
CONTRAST_JS = """
(sel) => {
  const dark = document.documentElement.classList.contains('dark');
  const parse = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(/[ ,\\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
  const out = [];
  document.querySelectorAll(sel).forEach((el) => {
    const fg0 = parse(getComputedStyle(el).color); if (!fg0) return;
    const layers = []; let n = el;
    while (n) { const b = parse(getComputedStyle(n).backgroundColor); if (b && b.a > 0) { layers.push(b); if (b.a >= 0.999) break; } n = n.parentElement; }
    let base = dark ? { r: 17, g: 24, b: 39 } : { r: 255, g: 255, b: 255 };
    if (layers.length && layers[layers.length - 1].a >= 0.999) base = layers.pop();
    let bg = base;
    for (let i = layers.length - 1; i >= 0; i--) { const l = layers[i]; bg = { r: l.r * l.a + bg.r * (1 - l.a), g: l.g * l.a + bg.g * (1 - l.a), b: l.b * l.a + bg.b * (1 - l.a) }; }
    const fg = { r: fg0.r * fg0.a + bg.r * (1 - fg0.a), g: fg0.g * fg0.a + bg.g * (1 - fg0.a), b: fg0.b * fg0.a + bg.b * (1 - fg0.a) };
    const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    out.push({ text: (el.innerText || '').trim().slice(0, 30), ratio: Math.round(ratio * 100) / 100 });
  });
  return out;
}
"""

if DB:  # start every run in English, whatever an earlier run left behind
    _c = sqlite3.connect(DB); _c.execute("update users set locale=null where email='parent@demo.test'"); _c.commit(); _c.close()

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = new_page(browser)
    login(page)
    page.screenshot(path=f"{SHOTS}/kit_mobile.png", full_page=True)

    # ------------------------------------------------------------------ buttons
    lg = page.locator("#buttons button:not(:has-text('Compact'))")
    heights = [lg.nth(i).evaluate(H) for i in range(lg.count()) if lg.nth(i).get_attribute("aria-label") is None]
    check("large buttons are >= 56px tall", min(heights) >= 56, f"min {min(heights)}px over {len(heights)}")
    check("compact button is 44px", page.locator("#buttons button:has-text('Compact')").evaluate(H) >= 44)
    ib = page.locator("#buttons button[aria-label='Notifications']")
    check("icon-only button has a name and is >= 48px on a phone", ib.evaluate(H) >= 48 and ib.get_attribute("title") == "Notifications", f"{ib.evaluate(H)}px")
    save = page.get_by_role("button", name="Save with progress")
    save.click()
    check("busy button disables itself, says why, and announces busy", save.count() == 0 and page.locator("#buttons button[aria-busy=true]").count() == 1 and page.locator("#buttons button[aria-busy=true]").is_disabled() and "Saving" in page.locator("#buttons button[aria-busy=true]").inner_text())
    page.wait_for_selector("#buttons button[aria-busy=true]", state="detached", timeout=5000)
    check("...and comes back afterwards", page.get_by_role("button", name="Save with progress").is_enabled())

    # ------------------------------------------------------------------ forms
    check("label is tied to its control", page.get_by_label("Full name").count() == 1)
    inp = page.locator("#kit-name")
    check("inputs and selects are >= 56px tall", inp.evaluate(H) >= 56 and page.locator("#kit-section").evaluate(H) >= 56, f"{inp.evaluate(H)}/{page.locator('#kit-section').evaluate(H)}")
    ph = page.locator("#kit-phone")
    err_id = ph.get_attribute("aria-describedby")
    check("field error is beside the field, announced, and wired with aria", ph.get_attribute("aria-invalid") == "true" and err_id and page.locator(f"#{err_id.split()[-1]}[role=alert]").count() == 1)
    check("optional fields say so", "optional" in page.locator("label[for=kit-phone]").inner_text().lower())
    row = page.locator("#forms label:has(input[type=checkbox])").nth(1)
    check("permission rows are whole >= 56px targets", row.evaluate(H) >= 56)
    cb = row.locator("input")
    row.click()
    check("tapping the row toggles it", cb.is_checked())

    # ------------------------------------------------------------------ choices
    cards = page.locator("#choices button[role=radio]")
    check("choice cards >= 64px, none preselected", cards.nth(0).evaluate(H) >= 64 and page.locator("#choices button[aria-checked=true]").count() == 0)
    cards.nth(1).click()
    check("selecting shows a strong state and updates the value", cards.nth(1).get_attribute("aria-checked") == "true" and cards.nth(0).get_attribute("aria-checked") == "false" and "mobile" in page.inner_text("#choice-readout"))
    page.get_by_role("radio", name="Male", exact=True).click()
    check("segmented choice works and is >= 56px", page.get_by_role("radio", name="Male", exact=True).get_attribute("aria-checked") == "true" and page.get_by_role("radio", name="Male", exact=True).evaluate(H) >= 56)

    # ------------------------------------------------------------------ alerts
    check("errors/warnings are role=alert, successes/notes are role=status", page.locator("#alerts [role=alert]").count() == 2 and page.locator("#alerts [role=status]").count() == 2)
    check("every alert has words for screen readers (tone + text)", page.locator("#alerts .sr-only").count() == 4)
    dismiss = page.get_by_role("button", name="Close this message")
    check("only the success alert can be dismissed (an error cannot be waved away)", dismiss.count() == 1)
    check("dismiss target >= 44px", dismiss.evaluate(H) >= 44)
    dismiss.click()
    check("dismissing removes it", page.locator("#alerts [role=status]").count() == 1)

    # ------------------------------------------------------------------ badges
    badges = page.locator("#badges span.rounded-full")
    texts = badges.all_inner_texts()
    check("every status renders words + an icon (never colour alone)", len(texts) >= 20 and all(t.strip() for t in texts) and badges.locator("svg").count() == len(texts), f"{len(texts)} badges")
    check("no raw translation keys shown in badges", not any(re.search(r"status\.|attendance\.", t) for t in texts), str([t for t in texts if "." in t]))

    # ------------------------------------------------------------------ confirm dialog
    page.get_by_role("button", name="Delete Student…").click()
    dlg = page.get_by_role("dialog")
    dlg.wait_for()
    check("dialog is titled by what will happen", dlg.get_by_role("heading", name="Delete Student?").count() == 1)
    check("Cancel (the safe choice) has keyboard focus", page.evaluate("document.activeElement && document.activeElement.innerText.trim()") == "Cancel")
    confirm = dlg.get_by_role("button", name="Delete Student", exact=True)
    check("confirm button names the outcome and is >= 56px", confirm.count() == 1 and confirm.evaluate(H) >= 56)
    page.screenshot(path=f"{SHOTS}/kit_confirm.png")
    page.keyboard.press("Escape")
    page.wait_for_selector("[role=dialog]", state="detached")
    check("Escape cancels", "Cancel" in page.inner_text("#confirm-readout"))
    page.get_by_role("button", name="Delete Student…").click()
    page.get_by_role("dialog").get_by_role("button", name="Delete Student", exact=True).click()
    page.wait_for_selector("[role=dialog]", state="detached")
    check("confirming answers yes", "You chose: Delete Student" in page.inner_text("#confirm-readout"))
    page.get_by_role("button", name="Show the dialog while working").click()
    busy_dlg = page.get_by_role("dialog")
    busy_dlg.wait_for()
    check("while working the dialog cannot be dismissed (Cancel disabled, Escape ignored)", busy_dlg.get_by_role("button", name="Cancel").is_disabled() and (page.keyboard.press("Escape") or True) and page.get_by_role("dialog").count() == 1)
    page.reload(); page.wait_for_selector("#buttons")

    # ------------------------------------------------------------------ states + print
    check("empty state explains and offers the next step", page.locator("#states").get_by_text("No students in this class yet").count() == 1 and page.locator("#states").get_by_role("button", name="Enroll a student").count() == 1)
    check("loading state says so in words", page.locator("#states [role=status]:has-text('Loading students')").count() == 1)
    check("success panel is on the page (not only a banner)", page.locator("#states [role=status]:has-text('Invoices created')").count() == 1)
    page.evaluate("window.__printed = 0; window.print = () => { window.__printed++ }; true")  # trailing value: Playwright would otherwise CALL a returned function
    page.get_by_role("button", name="Print ID card").click()
    check("print button names the document and calls print once", page.evaluate("window.__printed") == 1)
    check("print hint explains the dialog and Save as PDF", "Save as PDF" in page.inner_text("#print"))
    page.emulate_media(media="print")
    vis_area = page.locator("#print [data-print-area]").evaluate("e => getComputedStyle(e).visibility")
    vis_title = page.locator("h1").first.evaluate("e => getComputedStyle(e).visibility")
    check("print stylesheet shows only the print area", vis_area == "visible" and vis_title == "hidden", f"area={vis_area} h1={vis_title}")
    page.emulate_media(media="screen")

    # ------------------------------------------------------------------ wizard
    w = page.locator("#wizard")
    nxt = lambda: w.get_by_role("button", name="Next")
    check("wizard starts at 'Step 1 of 3' with a title and explanation", w.get_by_text("Step 1 of 3").count() == 1 and w.get_by_role("heading", name="What are you adding?").count() == 1 and w.get_by_text("Pick one.").count() == 1)
    check("Next is off and says WHY", nxt().is_disabled() and w.get_by_text("Choose one to continue.").count() == 1)
    w.get_by_role("radio", name=re.compile("A fee")).click()
    nxt().click()
    check("step 2 blocks until valid, with the reason", w.get_by_text("Step 2 of 3").count() == 1 and nxt().is_disabled() and w.get_by_text("at least 3 letters").count() == 1)
    w.locator("#wiz-name").fill("ab")
    check("2 letters still blocked", nxt().is_disabled())
    w.locator("#wiz-name").fill("Tuition")
    w.locator("#wiz-name").press("Enter")
    w.get_by_text("Step 3 of 3").wait_for()
    check("Enter advances; reaching the review does NOT submit", w.get_by_text("Item created").count() == 0)
    check("review lists the answers", "Tuition" in w.locator("dl").inner_text() and "A fee" in w.locator("dl").inner_text())
    final = w.get_by_role("button", name="Create item")
    check("final action is a strong, outcome-named button", final.count() == 1 and final.evaluate(H) >= 56)
    w.get_by_role("button", name="Change").first.click()
    w.get_by_text("Step 1 of 3").wait_for()
    check("'Change' jumps back and keeps the answer", w.locator("button[role=radio][aria-checked=true]").count() == 1)
    nxt().click(); w.get_by_text("Step 2 of 3").wait_for()
    check("Back/forward keep typed text", w.locator("#wiz-name").input_value() == "Tuition")
    nxt().click(); w.get_by_text("Step 3 of 3").wait_for()
    w.get_by_role("button", name="Create item").click()
    w.get_by_text("Item created").wait_for()
    check("success panel appears after the one deliberate click", w.get_by_role("button", name="Add another item").count() == 1)

    # ------------------------------------------------------------------ navigation (phone)
    hdr = page.locator("header button[aria-label]")
    names = hdr.evaluate_all("els => els.map(e => e.getAttribute('aria-label'))")
    hs = hdr.evaluate_all("els => els.map(e => e.offsetHeight)")
    check("every header icon is labelled and >= 44px", len(names) >= 3 and all(n for n in names) and min(hs) >= 44, f"{names} {hs}")
    page.get_by_role("button", name="Open the menu").click()
    page.wait_for_timeout(400)
    links = page.locator("aside nav a")
    lh = [links.nth(i).evaluate(H) for i in range(links.count())]
    check("sidebar links are >= 48px on a phone", len(lh) > 3 and min(lh) >= 48, f"min {min(lh)}px of {len(lh)}")
    check("no horizontal overflow on a phone", page.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
    check("no JS errors, no console errors/warnings (incl. vague-label warnings)", not page.js_errors and not page.console_problems, "; ".join(page.js_errors + page.console_problems)[:200])

    # ------------------------------------------------------------------ desktop is not bloated
    d = new_page(browser, mobile=False); login(d)
    check("desktop: header icons compact (<= 44px)", max(d.locator("header button[aria-label]").evaluate_all("els => els.map(e => e.offsetHeight)")) <= 44)
    d.screenshot(path=f"{SHOTS}/kit_desktop.png", full_page=True)

    # ------------------------------------------------------------------ contrast, light and dark
    SEL = "#badges span.rounded-full, #alerts [role=alert] p, #alerts [role=status] p, #alerts [role=status] div, #buttons button:not([aria-label]), #confirm button"
    for mode, pg in (("light", page), ("dark", None)):
        if mode == "dark":
            pg = new_page(browser, dark=True); login(pg)
            check("dark theme is applied", pg.evaluate("document.documentElement.classList.contains('dark')"))
            pg.screenshot(path=f"{SHOTS}/kit_dark.png", full_page=True)
        pg.reload(); pg.wait_for_selector("#buttons")
        rows = pg.evaluate(CONTRAST_JS, SEL)
        bad = [r for r in rows if r["ratio"] < 4.5 and r["text"]]
        check(f"{mode}: badge/alert/button text meets WCAG AA (4.5:1)", len(rows) > 30 and not bad, f"{len(rows)} checked, worst {min(r['ratio'] for r in rows)}; failing: {bad[:4]}")

    # ------------------------------------------------------------------ every language: no raw keys, no overflow
    if DB:
        for loc in ("am", "om", "ti"):
            con = sqlite3.connect(DB); con.execute("update users set locale=? where email='parent@demo.test'", (loc,)); con.commit(); con.close()
            lp = new_page(browser); login(lp)
            body = lp.inner_text("body")
            raw = re.findall(r"\b(?:ui|status|wizard|common|payments|layout)\.[a-z_]+\b", body)
            check(f"{loc}: no untranslated keys on screen", not raw, str(raw[:5]))
            lp.get_by_text(re.compile("Delete Student…")).click()
            lp.wait_for_selector("[role=dialog]")
            dl = lp.locator("[role=dialog] button").all_inner_texts()
            check(f"{loc}: dialog Cancel is translated (not the English key/word)", "Cancel" not in dl and all(t.strip() for t in dl), str(dl))
            lp.keyboard.press("Escape")
            check(f"{loc}: no horizontal overflow on a phone", lp.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
            lp.screenshot(path=f"{SHOTS}/kit_{loc}.png", full_page=True)
        con = sqlite3.connect(DB); con.execute("update users set locale=null where email='parent@demo.test'"); con.commit(); con.close()
    else:
        print("SKIP language pass (set E2E_DB)")
    browser.close()

print(f"\n{sum(results)}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if all(results) else 1)
