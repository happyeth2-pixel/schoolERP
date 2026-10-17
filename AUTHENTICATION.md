# Signing in: phone number, temporary passwords, resets

This is the authentication model since "Phase 1: Ethiopian phone-number-based authentication". It changes how people
are identified and how staff accounts are handed out. It does not change authorization, branch scoping, permissions,
audit logging, CSRF or session handling.

## Who signs in with what

- **The sign-in identifier is a phone number**, typed any everyday way: `09XXXXXXXX`, `07XXXXXXXX`, `+2519XXXXXXXX`,
  `+2517XXXXXXXX` (also `9XXXXXXXX`, `2519XXXXXXXX`, `002519XXXXXXXX`, with spaces, dashes or brackets). Every form
  becomes one canonical number, `+251[79]XXXXXXXX` (`App\Support\PhoneNumber::normalizeMobile`). Landlines and foreign
  numbers are not sign-in numbers.
- **Two columns on `users`, on purpose.**
  - `login_phone` - the canonical mobile number the account signs in with. **Unique** (database key) and always
    normalised by the model. This is what makes "the same number typed two ways" one account.
  - `phone` - a contact number (unchanged). Two parents may share one; nothing was thrown away or de-duplicated.
- **Email is optional.** `users.email` is now nullable (still unique when present). It is no longer required to create
  a staff account, to sign in, or on the profile page. Existing email data is untouched.
- **Accounts that only have an email keep signing in with it** (the same box; anything containing `@` is looked up by
  email) until an administrator gives them a phone number. Nobody is locked out by the migration.
- Student accounts are still refused, inactive accounts are refused, and a wrong password, an unknown number and
  something that is not a number all get the same message, so nothing reveals which accounts exist.

## The migration (`2026_09_28_090000_add_phone_login_to_users`)

Adds `login_phone` (unique), `must_change_password`, `password_changed_at`, makes `email` nullable, and back-fills
`login_phone` from `phone` **only** where the stored phone is a valid mobile number that no other account has.
Duplicates, landlines and free text get no `login_phone` and keep whatever they had. It was checked against a scratch
database with shared, legacy-format, landline, free-text and empty numbers. `down()` removes the new columns (it does
not turn `email` back into a required column).

## Staff accounts

1. An administrator (permission `manage-staff`, within their branch and never above their own permissions - all
   unchanged) opens *Add a staff member*, a three-step form:
   **Step 1 Staff member** (name, phone, job title - a label only) -> **Step 2 School access** (role, branch,
   permissions and, for a teacher, an optional "class teacher of" chosen from current-year classes that have none;
   needs `manage-classes`, never replaces an existing class teacher) -> **Step 3 Account** (the phone typed again,
   a temporary password - "Make one for me" by default, or typed by the administrator - an optional email as contact
   information, then *Create Account*). No email is required anywhere.
2. The phone must be a valid Ethiopian mobile number and must not belong to any other account, after normalising, as
   a sign-in number **or** a contact number (`App\Rules\UniqueAccountPhone`; the database key is the last backstop for
   two people adding the same number at once).
3. The temporary password is either made by the server (10 characters, random, no look-alike characters:
   `App\Support\TemporaryPassword`) or typed by the administrator (at least 8 characters, letters and numbers; the
   audit entry only says `chosen_by_administrator`). It is stored hashed, the account is marked `must_change_password`,
   and the administrator gets a success screen - *Account created successfully* - with name, phone, role, branch (and
   class), **Username/login: the phone number**, the temporary password **once**, the warning "Give the temporary
   password to the staff member securely. They will be required to change it when they first log in.", and Copy / Print
   buttons (the print area holds exactly those details). It travels to that page in an encrypted flash value and is
   never written to the audit log (`account.temporary_password_issued`).
4. The person signs in with the phone number and the temporary password and is taken to **"Welcome, {name} - for your
   security, please create a new password"**. Until they do, every other page redirects there
   (`EnsurePasswordIsChanged`); only that page and sign-out work. They must give the current (temporary) password, a new
   password (at least 8 characters, confirmed, different from the temporary one). Then the flag is cleared, the
   temporary password no longer exists (only the new hash does), other sessions of that account are ended (when sessions are
   kept in the database, as they are in production), and `account.password_changed` is written.

Any other way a password changes (Forgot password, the profile page, accepting an invitation) also clears the flag
(`User::booted`), so a temporary password can never outlive a real one.

## Switching an account off and on

*Disable account* on the staff list (`POST /staff/{id}/deactivate`) and *Reactivate account* (`/reactivate`) - never a
delete. Disabling needs `manage-staff` and the same reach as every staff action (same school, branch scope, only an
owner touches an owner), refuses your own account, sets `status = inactive`, rotates the remember-me token and deletes
the person's database sessions (any that remain are refused by `EnsureAccountIsActive`), and writes an
`account_disabled` security event. A disabled account cannot sign in - `Auth::attempt` requires `status = active`,
and the message is the same generic one as for a wrong password. Attendance, marks, payments, audit logs and the class
teacher link all stay attributed to the person (`tests/Feature/StaffLifecycleTest.php` pins it). Reactivating clears any
sign-in wait, writes `account_enabled`, and by default the existing password works again. *Reactivate with a new
password* does the same, plus issues a fresh temporary password in the same step (for when reactivating "as is" isn't
appropriate) - the account still needs the administrator to hand it over, and the person must choose their own again
at their next sign-in. The *Change* dialog edits name, job title, phone and (branch admins) permissions; it no longer
has an on/off switch, so switching off always goes through the confirmation. The list searches name, phone (any way of
writing it), email, job title and role, and shows a disabled card dimmed and labelled *Switched off*, with a **Last
signed in** line (or "Never signed in") and a **View** button that opens the same details read-only - the one action a
read-only holder of the permission (an auditor) gets, since they see the list but none of the change/reset/disable
buttons. Parent accounts (`/parents`, `manage-parents`) show and do all of the same things.

Status is never ambiguous: **Active**, **Switched off**, **Must choose a new password** (a temporary password is
outstanding), and **Locked** (ten wrong tries; a shorter progressive wait after fewer tries shows as *Sign-in paused
for now* instead - `LoginThrottle::status()` already told these apart, only the badge did not, until now).

## Resetting a password

*Reset password* on the staff list (`POST /staff/{id}/password-reset`): asks first, then makes a new temporary
password exactly as above, ends the person's other sessions, and shows the administrator the **new** temporary
password once. Nobody - administrator or not - can ever see an existing password (only hashes exist). Rules: needs
`manage-staff`, the person must be in the administrator's branch, only owners can reset an owner, nobody resets their
own password this way, and the person must have a way to sign in. Audited as `staff.password_reset` (no password).

Changing a staff member's phone (*Change*) changes their sign-in number, with the same validation and an audit entry
(`staff.phone_changed`).

## Where email is still used

- **Parent/guardian accounts made during registration** ("link a new parent") are still created with an email and a one-time "set your password" link (`AccountInvitation`), unchanged. A parent account is always optional -- a family with none is served just as well at the school desk (`StudentDeskController`, "Help a parent").
- **Parent accounts can also be phone-based, exactly like staff.** *Parents > Add a parent account* (`ParentController@store`, needs `manage-parents`) asks for a name, a phone number (confirmed by retyping it, the same rule as staff), and optionally one or more of the school's own students to link now, each with a relationship -- linking is optional and more children can be linked later from a student's own profile. Email stays optional. The temporary password is generated or typed by the administrator, shown once (`TemporaryPassword::handOver`), and the parent must choose their own at their first sign-in, identically to a staff account. `Reset password`, `Disable account` and `Reactivate account` on the *Parents* directory work exactly like their staff equivalents (same security events, same session termination, same generic sign-in failure message for a disabled account). Linking a child at creation is scoped to the actor's own branch (an org-wide account may link any student in the school); the parent account itself is not branch-scoped, since one family can have children in different branches.
- "Forgot password" by email still exists as a route but is no longer linked from the sign-in page, which says to ask
  the school office instead.

## Login abuse protection

Layered, enforced entirely on the server (nothing depends on JavaScript), and every number lives in
`config/login_security.php` (most also as `LOGIN_*` settings in `.env`). The code is `App\Security\LoginThrottle`,
called from `LoginRequest::authenticate()`. The point is to make automated guessing expensive **without** shutting a
school out because many people share one network address.

| Layer | What is counted | What happens | Default |
|---|---|---|---|
| **Account** | failures against one canonical number (or email), from anywhere; the same number typed any way is one counter | the 3rd failure starts a wait of 10 s, then 30 s, 60 s, 5 min, 15 min; the 10th failure without a quiet hour starts a **30-minute lock**; after a lock the next failures start at the longest wait again; failures are forgotten after 60 quiet minutes | `account.*` |
| **Known device** | a browser that has signed in as that person before (a signed, encrypted, HttpOnly `sd` cookie holding a random device id and the accounts that device has used) | judged on **its own** failures with the same steps, so someone guessing at an account from elsewhere cannot lock the owner out of the owner's own device. A stolen cookie is no help without the password: it is throttled like any other | `device.*` |
| **Network address** | failures per address over a 10-minute window, and how many *different* accounts failed from it (credential stuffing) | **never locked.** Watched only: at 25 failures or 8 different accounts the address is flagged (`ip_pressure`, once) and, for 10 minutes, browsers that are *not known to the account* must leave 5 s between tries (30 s at 300 failures). Known devices and other addresses are untouched. Ordinary mistakes from a busy school stay far below these numbers | `ip.*` |
| **Burst** | attempts per number+address per minute | more than 8 in a minute waits, whatever the account's record | `burst.*` |

- **While a wait or lock is running the password is not checked at all**, so guessing during it is pointless.
- **Unknown numbers, disabled accounts, wrong passwords and non-numbers are counted and answered identically**, so
  nothing reveals which accounts exist. The answer is *"The phone number or password is incorrect. Check both and try
  again."* During a wait: *"Too many unsuccessful login attempts. Please wait 45 seconds and try again."* (the time
  left, never the reason, the limits or the number of tries left; `lang/*/auth.php`).
- **Combining the signals.** The account counter is the backbone (it catches distributed guessing from many
  addresses); the device record protects the real owner from being locked out by others; the address record catches
  scripts and credential stuffing and slows only strangers; the burst cap catches fast scripts inside the free tries.
  (A session id would add nothing: attackers simply discard it.)
- **Admin recovery.** Someone with `manage-staff` (same branch scope as every staff action) can **Reset password**
  (new temporary password, must be replaced at sign-in, clears the person's waits/locks and other sessions) or
  **Allow sign-in again** (clears the waits and lock only; the password is untouched). Neither bypasses any control:
  both need the permission, both are recorded, and the person still signs in normally afterwards. The staff list shows
  who is paused.
- **Security log** (`security_events` table, read at *Audit log > Sign-in security* by anyone who may read the audit
  log): `login_failed`, `login_cooldown`, `account_locked`, `login_blocked` (once per stretch, so an attack cannot flood
  it), `login_succeeded_after_failures`, `ip_pressure`, `password_reset_by_admin`, `password_reset_by_email`,
  `password_changed`, `sign_in_unlocked_by_admin`, `account_disabled`, `account_enabled` - each with time, network
  address, browser, the account when there is one and **the identifier masked** (`+2519*****344`). Never a password,
  never a full number. `php artisan security:prune` (scheduled daily) deletes events older than 180 days.
- **State** lives in the cache (`CACHE_STORE`, `database` in production) under a short lock so simultaneous failures
  are all counted; it survives restarts and is shared across servers with the database/redis cache.

Known limits, on purpose: the cache is the source of truth for waits, so wiping the cache forgets them (the
security log stays); a determined attacker with many addresses and many *different* accounts gets a slow trickle rather
than a wall (that is the price of never locking a shared address); and an attacker who can keep an account under
continuous failure will keep it paused for strangers - which is what the known-device rule and admin *Allow sign-in
again* exist for.

## Tests

`tests/Feature/Auth/PhoneLoginTest.php`, `tests/Feature/Auth/LoginThrottleTest.php` (33), `tests/Feature/Auth/AuthenticationTest.php`, the staff tests in
`AdminStaffWorkflowTest`, and the browser suites `tests/e2e/auth_phone.py` and `tests/e2e/admin.py`. Demo accounts
have phone numbers `0911000001` (owner) to `0911000010`, password `password`.
