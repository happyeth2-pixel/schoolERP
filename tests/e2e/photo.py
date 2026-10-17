"""Browser test: staff take or choose the student's photo at registration, and manage it on the profile.

  * the registration wizard has an OPTIONAL photo step: Take Photo (a real fake-camera stream in Chromium: live view ->
    take the picture -> preview -> Retake / Use This Photo) and Choose Photo (a file), a placeholder, a way to skip
  * a big phone-sized photo is shrunk in the browser and saved; a wrong file type or a disguised file is refused in plain words
  * with no camera (or camera refused) the message appears and the workflow continues with Choose Photo
  * the profile: the current photo, take/choose a replacement, remove it (asks first); the old file goes away
  * who may: the registrar yes; a teacher and an auditor no; phone layout; Amharic

Uses the usability-session data and creates a few students named "Phototest ..." (removed at the end, with their
photo files). Only run it against that throwaway database:
    bash scripts/usability/prepare-session.sh --serve
    E2E_DB=<repo>/storage/app/usability/session.sqlite python tests/e2e/photo.py
"""
import io, os, random, re, sqlite3, sys, tempfile, time
sys.stdout.reconfigure(encoding="utf-8")
from PIL import Image
from playwright.sync_api import sync_playwright

BASE = os.environ.get("E2E_BASE", "http://127.0.0.1:8011")
DB = os.environ["E2E_DB"]
REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
PUBLIC = os.path.join(REPO, "storage", "app", "public")
TMP = tempfile.mkdtemp(prefix="photo_e2e_")
results = []
H = "e => Math.round(e.offsetHeight)"
FAKE_CAMERA = ["--use-fake-device-for-media-stream", "--use-fake-ui-for-media-stream"]
NOTICE = "Camera isn't available on this device. You can choose a photo instead."


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
body = lambda pg: pg.locator("body").inner_text()
overflow = lambda pg: pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
wait_text = lambda pg, text, timeout=15000: pg.wait_for_selector(f"text={text}", timeout=timeout)


def make_jpeg(path, w, h, noise=True):
    rnd = random.Random(7)
    img = Image.frombytes("RGB", (w, h), bytes(rnd.getrandbits(8) for _ in range(w * h * 3))) if noise else Image.new("RGB", (w, h), (120, 160, 200))
    img.save(path, "JPEG", quality=95)


BIG = os.path.join(TMP, "phone-photo.jpg")
make_jpeg(BIG, 2400, 1800)                                    # noise: several MB, like an unedited phone photo
SMALL = os.path.join(TMP, "small.png")
Image.new("RGB", (400, 500), (200, 80, 80)).save(SMALL, "PNG")
TEXT = os.path.join(TMP, "notes.txt"); open(TEXT, "w").write("not a photo")
FAKE_PNG = os.path.join(TMP, "face.png"); open(FAKE_PNG, "w").write("<?php echo 1; ?>")
check("the test's big 'phone photo' really is over the 2 MB server limit", os.path.getsize(BIG) > 3 * 1024 * 1024, str(os.path.getsize(BIG)))


def register(page, name, photo_steps, class_name="Grade 4"):
    """Name -> about -> PHOTO (photo_steps(page) does what the test wants) -> class -> parent later -> register."""
    page.goto(f"{BASE}/students/create"); page.wait_for_selector("form h3")
    page.fill("#first_name", name); nxt(page).click()
    wait_text(page, "Step 2 of")
    page.get_by_role("radio", name="Girl").click(); nxt(page).click()
    wait_text(page, "Step 3 of 7")
    photo_steps(page)
    nxt(page).click(); wait_text(page, "Step 4 of 7")
    page.get_by_role("radio", name=class_name, exact=True).click()
    page.get_by_role("radio", name=re.compile(f"{class_name} A")).click(); nxt(page).click()
    wait_text(page, "Step 5 of 7")
    page.get_by_role("radio", name=re.compile("Add the parent later")).click(); nxt(page).click()
    wait_text(page, "Step 6 of 6")


def photo_of(name):
    r = q("select id, photo_path from students where first_name=?", name)
    return r[0] if r else (None, None)


def image_size(path):
    with Image.open(path) as im:
        return im.size


def file_exists(path):
    return bool(path) and os.path.exists(os.path.join(PUBLIC, path))


q("delete from enrollments where student_id in (select id from students where first_name like 'Phototest%')")
q("delete from students where first_name like 'Phototest%'")
created = []

with sync_playwright() as p:
    browser = p.chromium.launch(args=FAKE_CAMERA)
    rctx, rp = login(browser, "registrar@demo.test")

    # ================================================================ 1. the camera
    rp.goto(f"{BASE}/students/create"); rp.wait_for_selector("form h3")
    rp.fill("#first_name", "Phototest Cam"); nxt(rp).click(); wait_text(rp, "Step 2 of")
    rp.get_by_role("radio", name="Girl").click(); nxt(rp).click(); wait_text(rp, "Step 3 of 7")
    check("photo step: 'Student photo', optional (it says you can skip it) and Next is already on", rp.locator("form h3").first.inner_text() == "Student photo" and "skip this" in rp.locator("form").inner_text() and nxt(rp).is_enabled())
    check("photo step: a placeholder is shown, with 'Take Photo' and 'Choose Photo' as large buttons", rp.locator("[data-testid=photo-placeholder]").count() == 1 and rp.locator("[data-testid=take-photo]").evaluate(H) >= 56 and rp.locator("[data-testid=choose-photo]").evaluate(H) >= 56 and rp.locator("[data-testid=remove-photo]").count() == 0)
    rp.locator("[data-testid=take-photo]").click()
    rp.wait_for_selector("[data-testid=camera-video]")
    check("Take Photo: the camera opens in the page and shows a live picture", until(lambda: rp.evaluate("(() => { const v = document.querySelector('[data-testid=camera-video]'); return !!v && v.videoWidth > 0 && v.readyState >= 2; })()")))
    check("Take Photo: the live view offers 'Take the picture' and 'Cancel' (no Retake yet)", rp.locator("[data-testid=snap]").is_enabled() and rp.locator("[data-testid=cancel-camera]").count() == 1 and rp.locator("[data-testid=retake]").count() == 0)
    rp.locator("[data-testid=cancel-camera]").click()
    check("Cancel: the camera closes (the stream is stopped) and nothing was chosen", rp.locator("[data-testid=camera-video]").count() == 0 and rp.locator("[data-testid=photo-placeholder]").count() == 1)
    rp.locator("[data-testid=take-photo]").click(); rp.wait_for_selector("[data-testid=camera-video]")
    until(lambda: rp.evaluate("document.querySelector('[data-testid=camera-video]').videoWidth > 0"))
    rp.locator("[data-testid=snap]").click()
    rp.wait_for_selector("[data-testid=use-photo]")
    check("Preview: the picture just taken with 'Retake' and 'Use This Photo'", rp.locator("[data-testid=photo-preview]").count() == 1 and rp.get_by_role("button", name="Retake").count() == 1 and rp.get_by_role("button", name="Use This Photo").count() == 1 and rp.evaluate("document.querySelector('[data-testid=photo-preview]').naturalWidth") > 0)
    check("Preview: nothing is chosen yet, and the camera is off", rp.evaluate("document.querySelector('[data-testid=photo-field]').dataset.mode") == "review" and rp.evaluate("document.querySelector('video') === null"))
    rp.get_by_role("button", name="Retake").click()
    rp.wait_for_selector("[data-testid=camera-video]")
    check("Retake: goes back to the live camera", until(lambda: rp.evaluate("document.querySelector('[data-testid=camera-video]').videoWidth > 0")))
    rp.locator("[data-testid=snap]").click(); rp.wait_for_selector("[data-testid=use-photo]")
    rp.get_by_role("button", name="Use This Photo").click()
    check("Use This Photo: the photo is kept and shown, with 'Remove photo' now available", until(lambda: rp.evaluate("document.querySelector('[data-testid=photo-field]').dataset.mode") == "idle") and rp.locator("[data-testid=photo-preview]").count() == 1 and rp.locator("[data-testid=remove-photo]").count() == 1)
    nxt(rp).click(); wait_text(rp, "Step 4 of 7")
    rp.get_by_role("radio", name="Grade 4", exact=True).click(); rp.get_by_role("radio", name=re.compile("Grade 4 A")).click(); nxt(rp).click(); wait_text(rp, "Step 5 of 7")
    rp.get_by_role("radio", name=re.compile("Add the parent later")).click(); nxt(rp).click(); wait_text(rp, "Step 6 of 6")
    check("review: says the photo is added", "Photo" in rp.locator("form dl").inner_text() and "Added" in rp.locator("form dl").inner_text())
    check("review: nothing is saved yet (no student, no file)", photo_of("Phototest Cam")[0] is None)
    rp.get_by_role("button", name=re.compile("^Register Phototest")).click()
    check("registered: the student exists with a photo path under student-photos/ and a random name", until(lambda: photo_of("Phototest Cam")[1] is not None) and re.fullmatch(r"student-photos/[A-Za-z0-9]{40}\.jpg", photo_of("Phototest Cam")[1] or "") is not None, str(photo_of("Phototest Cam")))
    cam_id, cam_path = photo_of("Phototest Cam")
    created.append(cam_path)
    with Image.open(os.path.join(PUBLIC, cam_path)) as img:      # closed again: an open handle would stop Windows deleting the file later
        fmt, dims = img.format, img.size
    check("registered: the file is a real JPEG no larger than 800 px on its long side (decoded and re-encoded by the server)", fmt == "JPEG" and max(dims) <= 800, f"{fmt} {dims}")
    check("registered: no student login was made (no new user)", q("select count(*) from users where name like 'Phototest%'")[0][0] == 0)

    # ================================================================ 2. choosing a file
    def choose_big(page):
        page.locator("[data-testid=photo-file]").set_input_files(BIG)
        page.wait_for_selector("[data-testid=use-photo]", timeout=30000)
        check("Choose Photo: a 2400x1800 phone photo (over 2 MB) is accepted and shown in the preview", page.locator("[data-testid=photo-preview]").count() == 1 and page.get_by_role("button", name="Choose a different photo").count() == 1)
        page.get_by_role("button", name="Use This Photo").click()
        until(lambda: page.evaluate("document.querySelector('[data-testid=photo-field]').dataset.mode") == "idle")
    register(rp, "Phototest File", choose_big)
    rp.get_by_role("button", name=re.compile("^Register Phototest")).click()
    fid, fpath = (None, None)
    check("Choose Photo: the big photo was shrunk in the browser and saved", until(lambda: photo_of("Phototest File")[1] is not None))
    fid, fpath = photo_of("Phototest File"); created.append(fpath)
    check("Choose Photo: the saved file is a small JPEG", max(image_size(os.path.join(PUBLIC, fpath))) <= 800 and os.path.getsize(os.path.join(PUBLIC, fpath)) < 500 * 1024, str(os.path.getsize(os.path.join(PUBLIC, fpath))))

    def bad_files(page):
        page.locator("[data-testid=photo-file]").set_input_files(TEXT)
        page.wait_for_selector("[data-testid=photo-notice]")
        check("a text file is refused in plain words, nothing is chosen", "JPG or PNG" in page.locator("[data-testid=photo-notice]").inner_text() and page.locator("[data-testid=photo-preview]").count() == 0 and page.locator("[data-testid=use-photo]").count() == 0)
        page.locator("[data-testid=photo-file]").set_input_files(FAKE_PNG)
        check("a script renamed .png is refused too (it cannot even be read as a picture)", until(lambda: "could not be read" in page.locator("[data-testid=photo-notice]").inner_text()), page.locator("[data-testid=photo-notice]").inner_text() if page.locator("[data-testid=photo-notice]").count() else "")
        check("after refusals the workflow continues: Next is still on and the buttons are still there", nxt(page).is_enabled() and page.locator("[data-testid=choose-photo]").count() == 1)
        page.locator("[data-testid=photo-file]").set_input_files(SMALL)
        page.wait_for_selector("[data-testid=use-photo]")
        page.get_by_role("button", name="Use This Photo").click()
        page.locator("[data-testid=remove-photo]").wait_for()
        page.locator("[data-testid=remove-photo]").click()
        check("in the wizard, 'Remove photo' takes the chosen photo back out (no confirmation needed: nothing is saved yet)", until(lambda: page.locator("[data-testid=photo-placeholder]").count() == 1))
    register(rp, "Phototest None", bad_files)
    rp.get_by_role("button", name=re.compile("^Register Phototest")).click()
    check("skipping / removing the photo: the student is registered without one", until(lambda: photo_of("Phototest None")[0] is not None) and photo_of("Phototest None")[1] is None)

    # ================================================================ 3. the profile
    rp.goto(f"{BASE}/students/{cam_id}/edit"); rp.wait_for_selector("[data-testid=photo-section]")
    check("profile: the section shows the current photo and Take / Choose / Remove", rp.locator("[data-testid=photo-preview]").count() == 1 and rp.locator("[data-testid=take-photo]").count() == 1 and rp.locator("[data-testid=choose-photo]").count() == 1 and rp.locator("[data-testid=remove-photo]").count() == 1)
    check("profile: the saved photo really loads", rp.evaluate("document.querySelector('[data-testid=photo-preview]').complete && document.querySelector('[data-testid=photo-preview]').naturalWidth") > 0)
    check("profile: with a photo the section is folded (one tap away) and opens with its title", rp.evaluate("document.querySelector('[data-testid=photo-section]').open") is False)
    rp.locator("[data-testid=photo-section] summary").click()
    old = cam_path
    rp.locator("[data-testid=take-photo]").click(); rp.wait_for_selector("[data-testid=camera-video]")
    until(lambda: rp.evaluate("document.querySelector('[data-testid=camera-video]').videoWidth > 0"))
    rp.locator("[data-testid=snap]").click(); rp.wait_for_selector("[data-testid=use-photo]")
    rp.get_by_role("button", name="Use This Photo").click()
    swapped = until(lambda: photo_of("Phototest Cam")[1] not in (None, old))
    check("profile: a new photo replaces the old one and the old file is deleted", swapped and until(lambda: not file_exists(old)), f"swapped={swapped} old_exists={file_exists(old)} old={old} now={photo_of('Phototest Cam')[1]}")
    new = photo_of("Phototest Cam")[1]; created.append(new)
    check("profile: the replacement file exists", file_exists(new))
    rp.locator("[data-testid=remove-photo]").click()
    dlg = rp.get_by_role("dialog"); dlg.wait_for()
    check("profile: removing asks first and says the record stays", "Remove this photo?" in dlg.inner_text() and "record stays" in dlg.inner_text())
    dlg.get_by_role("button", name="Cancel").click(); rp.wait_for_selector("[role=dialog]", state="detached")
    check("profile: Cancel keeps the photo", photo_of("Phototest Cam")[1] == new and file_exists(new))
    rp.locator("[data-testid=remove-photo]").click(); rp.get_by_role("dialog").get_by_role("button", name="Remove photo").click()
    unlinked = until(lambda: photo_of("Phototest Cam")[1] is None)
    gone = until(lambda: not file_exists(new), timeout=8)
    # (on Windows the dev server can still hold the just-displayed image open, so the file may outlive the link there: the PHP test pins the deletion itself)
    check("profile: confirming removes the photo link and the file, keeps the student", unlinked and (gone or os.name == "nt") and q("select status from students where id=?", cam_id)[0][0] == "active", f"unlinked={unlinked} file_gone={gone}")
    check("profile: the section goes back to a placeholder with no Remove button", until(lambda: rp.locator("[data-testid=photo-placeholder]").count() == 1 and rp.locator("[data-testid=remove-photo]").count() == 0))
    rp.locator("[data-testid=photo-file]").set_input_files(SMALL)
    rp.wait_for_selector("[data-testid=use-photo]"); rp.get_by_role("button", name="Use This Photo").click()
    check("profile: a photo can be chosen from a file later, for a student who has none", until(lambda: photo_of("Phototest Cam")[1] is not None))
    created.append(photo_of("Phototest Cam")[1])
    check("the changes are in the audit log", q("select count(*) from audit_logs where auditable_id=? and action like 'student.%'", cam_id)[0][0] >= 2)

    # ================================================================ 4. who may
    tctx, tp = login(browser, "teacher@demo.test")
    check("a teacher cannot open the profile or send a photo", status_of(tctx, f"/students/{cam_id}/edit") == 403)
    tctx.close()
    actx, ap = login(browser, "auditor@demo.test")
    ap.goto(f"{BASE}/students/{cam_id}/edit"); ap.wait_for_selector("h1")
    check("an auditor may look at the profile but gets no photo controls", ap.locator("[data-testid=photo-section]").count() == 0 and ap.locator("[data-testid=take-photo]").count() == 0)
    actx.close()

    # ================================================================ 5. phone layout
    mctx, mp = login(browser, "registrar@demo.test", mobile=True)
    mp.goto(f"{BASE}/students/create"); mp.wait_for_selector("form h3")
    mp.fill("#first_name", "Phototest Phone"); nxt(mp).click(); wait_text(mp, "Step 2 of")
    mp.get_by_role("radio", name="Girl").click(); nxt(mp).click(); wait_text(mp, "Step 3 of 7")
    check("phone: the photo step has no sideways scroll and its buttons are large and full width", overflow(mp) <= 0 and mp.locator("[data-testid=take-photo]").evaluate(H) >= 56 and mp.locator("[data-testid=take-photo]").evaluate("e => e.getBoundingClientRect().width") > 250)
    mp.locator("[data-testid=take-photo]").click(); mp.wait_for_selector("[data-testid=camera-video]")
    until(lambda: mp.evaluate("document.querySelector('[data-testid=camera-video]').videoWidth > 0"))
    check("phone: the live camera fits the screen and the shutter button is large", overflow(mp) <= 0 and mp.locator("[data-testid=snap]").evaluate(H) >= 56)
    mp.locator("[data-testid=snap]").click(); mp.wait_for_selector("[data-testid=use-photo]")
    check("phone: the preview with Retake / Use This Photo fits the screen", overflow(mp) <= 0 and mp.locator("[data-testid=use-photo]").evaluate(H) >= 56 and mp.locator("[data-testid=retake]").evaluate(H) >= 56)
    mctx.close()

    # ================================================================ 6. Amharic
    q("update users set locale='am' where email='registrar@demo.test'")
    xctx, xp = login(browser, "registrar@demo.test")
    xp.goto(f"{BASE}/students/create"); xp.wait_for_selector("form h3")
    xp.fill("#first_name", "Phototest Am"); xp.locator("form").get_by_role("button").last.click(); xp.wait_for_selector("[role=radio]")
    xp.locator("[role=radio]").nth(0).click(); xp.locator("form").get_by_role("button").last.click()
    xp.wait_for_selector("[data-testid=take-photo]")
    txt = xp.locator("main").inner_text()
    check("Amharic: the photo step is translated (no raw keys)", bool(re.search(r"[ሀ-፿]", xp.locator("[data-testid=take-photo]").inner_text())) and not re.search(r"\b(?:photo|enroll)\.[a-z_]+\b", txt), txt[:120].replace("\n", " | "))
    xctx.close()
    q("update users set locale=null where email='registrar@demo.test'")
    check("no JavaScript errors", not rp.js_errors, str(rp.js_errors[:2]))
    rctx.close()
    browser.close()

    # ================================================================ 7. no camera / camera refused: the workflow continues
    plain = p.chromium.launch()           # headless without any camera device or permission
    nctx, npg = login(plain, "registrar@demo.test")
    npg.goto(f"{BASE}/students/create"); npg.wait_for_selector("form h3")
    npg.fill("#first_name", "Phototest NoCam"); nxt(npg).click(); wait_text(npg, "Step 2 of")
    npg.get_by_role("radio", name="Girl").click(); nxt(npg).click(); wait_text(npg, "Step 3 of 7")
    npg.locator("[data-testid=take-photo]").click()
    npg.wait_for_selector("[data-testid=camera-notice]")
    check("no camera: the plain sentence appears (Camera isn't available on this device. You can choose a photo instead.)", NOTICE in npg.locator("[data-testid=camera-notice]").inner_text(), npg.locator("[data-testid=camera-notice]").inner_text())
    check("no camera: Choose Photo is still there and the step is not blocked", npg.locator("[data-testid=choose-photo]").count() == 1 and nxt(npg).is_enabled() and npg.locator("[data-testid=camera-video]").count() == 0)
    npg.locator("[data-testid=photo-file]").set_input_files(SMALL)
    npg.wait_for_selector("[data-testid=use-photo]")
    check("no camera: choosing a photo works and clears the message", npg.locator("[data-testid=camera-notice]").count() == 0)
    npg.get_by_role("button", name="Use This Photo").click()
    nxt(npg).click(); wait_text(npg, "Step 4 of 7")
    npg.get_by_role("radio", name="Grade 4", exact=True).click(); npg.get_by_role("radio", name=re.compile("Grade 4 A")).click(); nxt(npg).click(); wait_text(npg, "Step 5 of 7")
    npg.get_by_role("radio", name=re.compile("Add the parent later")).click(); nxt(npg).click(); wait_text(npg, "Step 6 of 6")
    npg.get_by_role("button", name=re.compile("^Register Phototest")).click()
    check("no camera: the registration completes with the chosen photo", until(lambda: photo_of("Phototest NoCam")[1] is not None))
    created.append(photo_of("Phototest NoCam")[1])
    nctx.close()
    plain.close()

# ---- clean up what the test made (students, and every photo file it stored)
for name in ("Phototest Cam", "Phototest File", "Phototest None", "Phototest Phone", "Phototest Am", "Phototest NoCam"):
    r = photo_of(name)
    if r[1]: created.append(r[1])
q("delete from enrollments where student_id in (select id from students where first_name like 'Phototest%')")
q("delete from students where first_name like 'Phototest%'")
for path in set(c for c in created if c):
    try: os.remove(os.path.join(PUBLIC, path))
    except OSError: pass

fails = [i for i, ok in enumerate(results) if not ok]
print(f"\n{len(results) - len(fails)}/{len(results)} checks passed")
sys.exit(1 if fails else 0)
