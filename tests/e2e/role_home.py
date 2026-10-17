"""Browser test: role-based home page (task tiles) and navigation, against the usability-session data.

For eight real accounts (owner, principal, registrar = a limited branch admin, teacher, accountant, a limited
"cashier" accountant, parent, auditor) it checks that the home page:
  - asks "What do you want to do today?" and shows 3-6 LARGE tiles in the right priority for that person,
  - only offers tiles the person is authorised for -- every tile and every menu link is requested and must
    load (never 403/404), while pages outside their permissions still refuse them,
  - keeps everything else reachable (More things you can do + the menu), folds a long menu without removing anything,
  - uses plain school words, has no raw translation keys in Amharic/Oromo/Tigrinya, and does not overflow a phone.

Set up as in tests/e2e/payment_wizard.py but with the full session data:
    bash scripts/usability/prepare-session.sh --serve      # fake school on http://127.0.0.1:8011 (all accounts, password "password")
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/role_home.py

The language pass changes the parent/teacher locale in that throwaway database and resets it afterwards.
"""
import os, re, sqlite3, sys, tempfile
sys.stdout.reconfigure(encoding="utf-8")
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ.get("E2E_DB")
SHOTS = tempfile.mkdtemp(prefix="role_home_e2e_")
results = []


def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail else ""))


def session(browser, email, mobile=False):
    kw = {"viewport": {"width": 390, "height": 844}, "is_mobile": True, "has_touch": True} if mobile else {"viewport": {"width": 1280, "height": 900}}
    ctx = browser.new_context(**kw)
    page = ctx.new_page()
    page.js_errors = []
    page.on("pageerror", lambda e: page.js_errors.append(str(e)))
    page.goto(f"{BASE}/login")
    page.fill("#phone", email)
    page.fill("#password", "password")
    page.get_by_role("button", name=re.compile("log in|ግባ|seeni|እቶ", re.I)).click()
    page.wait_for_url(lambda u: "/login" not in u, timeout=20000)
    page.goto(f"{BASE}/dashboard")
    page.wait_for_selector("[data-testid=home-tasks], [data-testid=parent-home]", timeout=20000)
    return ctx, page


def status_of(ctx, href):
    r = ctx.request.get(href if href.startswith("http") else f"{BASE}{href}", max_redirects=5)
    return r.status


def tile_titles(page, scope="[data-testid=main-tiles]"):
    return page.locator(f"{scope} a").evaluate_all("els => els.map(e => e.querySelector('span span').innerText.trim())")


EXPECT = {  # account -> ordered tile titles the person should see as the big tiles
    "owner@demo.test": ["Students", "Staff", "Classes", "School fees", "Check payments", "Attendance report"],
    "principal@demo.test": ["Students", "Staff", "Classes", "School fees", "Check payments", "Attendance report"],
    "registrar@demo.test": ["Register Student", "Find Student", "Print Student Card", "Find Parent", "More"],
    "teacher@demo.test": ["Enter marks", "Notifications", "Announcements"],
    "accountant@demo.test": ["Check payments", "Invoices and reports", "School fees", "Notifications", "Announcements"],
    "cashier@demo.test": ["Check payments", "Invoices and reports", "Notifications", "Announcements"],
    "parent@demo.test": ["My children", "Attendance", "Fees", "Report card", "Notifications", "Complaints"],
    "auditor@demo.test": ["Students", "Attendance report", "Invoices and reports", "Activity history", "Complaints", "Classes"],
}

# pages that must stay closed to each account (the backend is unchanged; this proves the new home did not open anything)
FORBIDDEN = {
    "registrar@demo.test": ["/staff", "/fee-structures", "/payments/pending", "/audit-log"],
    "teacher@demo.test": ["/students", "/invoices", "/staff", "/payments/pending"],
    "accountant@demo.test": ["/staff", "/students", "/attendance", "/marks"],
    "cashier@demo.test": ["/fee-structures", "/staff", "/students"],
    "parent@demo.test": ["/students", "/staff", "/payments/pending", "/invoices", "/fee-structures"],
    "auditor@demo.test": ["/students/create", "/payments/pending", "/attendance"],
    "principal@demo.test": ["/audit-log", "/branches"],
}

DEV_WORDS = re.compile(r"Structure|Assignment|Audit|Verification|Optional Services|Academic Years|Promote|Oversight|Dashboard", re.I)

with sync_playwright() as p:
    browser = p.chromium.launch()

    for email, expected in EXPECT.items():
        who = email.split("@")[0]
        ctx, page = session(browser, email)
        if who == "parent":
            # the parent's home is its own page (Phase 9: child switcher + five cards), covered in detail by tests/e2e/parent.py
            check("parent: home is the parent's own page, not the generic task tiles", page.locator("[data-testid=parent-home]").count() == 1 and page.locator("#home-question").count() == 0)
            phrefs = page.locator("[data-testid=parent-home] a").evaluate_all("els => els.map(e => e.getAttribute('href'))")
            pbad = [(h, status_of(ctx, h)) for h in dict.fromkeys(phrefs) if h and not h.startswith("#") and status_of(ctx, h) != 200]
            check(f"parent: every link on the home page ({len(set(phrefs))}) opens", not pbad, str(pbad))
        else:
            heading = page.locator("#home-question").inner_text()
            if who == "registrar":
                check("registrar: home greets by name and asks 'What do you want to do?'", re.fullmatch(r"Good (morning|afternoon|evening), .+", heading) is not None and "What do you want to do?" in page.locator("[data-testid=home-tasks]").inner_text(), heading)
            else:
                check(f"{who}: home asks 'What do you want to do today?'", heading == "What do you want to do today?", heading)

            titles = tile_titles(page)
            check(f"{who}: big tiles are the right ones, in priority order", titles == expected, f"{titles}")
            check(f"{who}: 3-6 tiles", 3 <= len(titles) <= 6 or (who == "cashier" and len(titles) >= 3), str(len(titles)))
            heights = page.locator("[data-testid=main-tiles] a").evaluate_all("els => els.map(e => e.offsetHeight)")
            check(f"{who}: every tile is >= 96px tall (large target)", min(heights) >= 96, str(heights))

            # every tile must lead somewhere this person is allowed to go
            hrefs = page.locator("[data-testid=main-tiles] a").evaluate_all("els => els.map(e => e.getAttribute('href'))")
            bad = []
            for h in hrefs:
                if h.startswith("#"):
                    if page.locator(h).count() == 0:
                        bad.append((h, "anchor missing"))
                else:
                    st = status_of(ctx, h)
                    if st != 200:
                        bad.append((h, st))
            check(f"{who}: every tile opens (no 403/404, anchors exist)", not bad, str(bad))

        # everything else authorised stays reachable, and works
        more = page.locator("[data-testid=more-tasks]")
        if more.count():
            more.locator("summary").click()
            mh = more.locator("a").evaluate_all("els => els.map(e => e.getAttribute('href'))")
            mbad = [(h, status_of(ctx, h)) for h in mh if not h.startswith("#") and status_of(ctx, h) != 200]
            check(f"{who}: 'More things you can do' ({len(mh)}) all open", not mbad, str(mbad))
        else:
            check(f"{who}: nothing extra to disclose (no 'More' block)", True)

        # menu: every visible link works; a long menu folds but loses nothing
        toggles = page.locator("aside nav button[aria-expanded]")
        if toggles.count():
            for i in range(toggles.count()):
                if toggles.nth(i).get_attribute("aria-expanded") == "false":
                    toggles.nth(i).click()
        links = page.locator("aside nav a").evaluate_all("els => els.map(e => [e.getAttribute('href'), e.innerText.trim()])")
        lbad = [(h, status_of(ctx, h)) for h, _ in links if status_of(ctx, h) != 200]
        check(f"{who}: every menu link ({len(links)}) opens", not lbad, str(lbad))
        labels = " | ".join(t for _, t in links)
        check(f"{who}: menu uses plain school words", not DEV_WORDS.search(labels), labels if DEV_WORDS.search(labels) else "")

        for url in FORBIDDEN.get(email, []):
            st = status_of(ctx, url)
            check(f"{who}: {url} is still refused", st in (403, 404), str(st))

        body = page.inner_text("body")
        money_words = ("Fee Collection Rate", "School Fees (Current Year)", "Payment Proofs Pending")
        if who == "registrar":  # holds no finance permission: the home page must not show money at all
            check("registrar: home shows no fee or payment figures (permission-gated on the server)", not any(w in body for w in money_words), str([w for w in money_words if w in body]))
        if who in ("owner", "principal"):
            check(f"{who}: home still shows the money widgets", all(w in body for w in money_words), str([w for w in money_words if w not in body]))
        check(f"{who}: no developer placeholders on the home page", "Arrives with its module" not in body and "later phase" not in body)
        check(f"{who}: no JS errors", not page.js_errors, "; ".join(page.js_errors)[:200])
        if who in ("owner", "registrar", "parent"):
            page.screenshot(path=f"{SHOTS}/home_{who}.png", full_page=True)
        ctx.close()

    # ---------------------------------------------------------------- progressive disclosure of a long menu (owner)
    ctx, page = session(browser, "owner@demo.test")
    toggles = page.locator("aside nav button[aria-expanded]")
    states = toggles.evaluate_all("els => els.map(e => e.getAttribute('aria-expanded'))")
    check("owner: a long menu folds some sections (not all open)", "false" in states and "true" in states, str(states))
    check("owner: section headers are >= 44px targets", min(toggles.evaluate_all("els => els.map(e => e.offsetHeight)")) >= 44)
    closed = toggles.evaluate_all("els => els.map((e, i) => e.getAttribute('aria-expanded') === 'false' ? i : -1).filter(i => i >= 0)")
    toggles.nth(closed[0]).click()
    check("owner: one tap opens a folded section", toggles.nth(closed[0]).get_attribute("aria-expanded") == "true")
    page.reload(); page.wait_for_selector("aside nav")
    check("owner: the choice is remembered after reload", page.locator("aside nav button[aria-expanded]").nth(closed[0]).get_attribute("aria-expanded") == "true")
    # opening a page inside a folded section opens that section by itself
    page.evaluate("localStorage.setItem('nav-open-sections', JSON.stringify({attendance:false, communication:false, people:false, results:false}))")
    page.goto(f"{BASE}/students"); page.wait_for_selector("aside nav")
    people = page.locator("aside nav button:has-text('People')")
    check("owner: the section holding the current page opens itself", people.get_attribute("aria-expanded") == "true")
    ctx.close()
    ctx, page = session(browser, "teacher@demo.test")
    # The teacher menu has grown past the folding threshold over the course of this initiative (Period
    # Attendance, My Timetable, My Library Loans all added their own self-service items) -- it now folds
    # too, the same mechanism already proven for the owner above, not a "stays short forever" guarantee.
    t_toggles = page.locator("aside nav button[aria-expanded]")
    check("teacher: the now-longer menu folds just like the owner's", t_toggles.count() > 0)
    ctx.close()

    # ---------------------------------------------------------------- phone layout + languages
    ctx, page = session(browser, "parent@demo.test", mobile=True)
    check("parent on a phone: no horizontal overflow", page.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
    tops = page.locator("[data-testid=parent-home] section a, [data-testid=parent-home] section button").evaluate_all("els => els.map(e => Math.round(e.getBoundingClientRect().left))")
    check("parent on a phone: the cards and their buttons share one column", len(set(tops)) <= 2, str(sorted(set(tops))))
    page.screenshot(path=f"{SHOTS}/home_parent_phone.png", full_page=True)
    ctx.close()

    if DB:
        for email in ("parent@demo.test", "teacher@demo.test"):
            for loc in ("am", "om", "ti"):
                con = sqlite3.connect(DB); con.execute("update users set locale=? where email=?", (loc, email)); con.commit(); con.close()
                ctx, page = session(browser, email, mobile=True)
                body = page.inner_text("body")
                raw = re.findall(r"\b(?:home|menu|nav|section|dashboard|ui|wizard|parent)\.[a-z_.]+\b", body)
                who = email.split("@")[0]
                q = page.locator("[data-testid=parent-home] h1").inner_text() if who == "parent" else page.locator("#home-question").inner_text()
                check(f"{who}/{loc}: home is translated (heading not English), no raw keys", q != "What do you want to do today?" and not q.startswith("Hello") and not raw, f"{q!r} {raw[:4]}")
                check(f"{who}/{loc}: no overflow on a phone", page.evaluate("document.documentElement.scrollWidth - window.innerWidth") <= 0)
                if who == "parent" and loc == "am":
                    page.screenshot(path=f"{SHOTS}/home_parent_am.png", full_page=True)
                ctx.close()
            con = sqlite3.connect(DB); con.execute("update users set locale=null where email=?", (email,)); con.commit(); con.close()
    else:
        print("SKIP language pass (set E2E_DB)")
    browser.close()

print(f"\n{sum(results)}/{len(results)} checks passed   (screenshots: {SHOTS})")
sys.exit(0 if all(results) else 1)
