# LMS changes

A running log of features, changes and bug fixes made to the LMS (IOMAD /
Moodle and the `local_privacient` plugin). Newest first. Each entry says what
changed, why, the files touched, and anything that must be run to deploy it.

---

## 2026-09-22 — Pre-VAPT security hardening (SAML, launch keys, tenant scoping)

**Type:** Bug fix (security)

**Why:** The pre-VAPT review found a cluster of cross-tenant auth issues.

**What changed:**

- **C1 — cross-tenant SAML login (critical).** `auth/iomadsaml2/classes/auth.php`
  `saml_login_complete()`: removed the username/email fallback (a bare `admin`
  can no longer resolve) and added a fail-closed gate before `complete_user_login`
  that requires the asserted user to be a non-suspended plain learner
  (`managertype=0`) of the company whose IdP issued the assertion — never a site
  admin, manager, or another company's user. New predicate
  `learner::is_company_learner()`. `local/privacient/classes/saml_sp.php`
  `allow_roster_sso()` no longer force-enables site-wide `anyauth`.
- **H1 — account adoption.** `learner::ensure()` now refuses to adopt or flip the
  auth of a pre-existing account that is a site admin/guest, belongs to another
  company, holds a managing role, has a web-service token, or has a
  system/category role. Only genuinely new accounts, or existing plain learners
  of this company, are linked.
- **H2 — launch keys.** `external/enrol_learner.php` and `launch.php` refuse to
  mint or honour a launch key for a site admin, a suspended/`nologin` account, or
  a non-member of the launched course's company; `launchttl` default shortened
  from 7 days to 1.
- **M2 — content web-service scoping.** `delete_content`/`set_poster`/
  `update_content` fail closed on a missing/`-1` tenantid; `publish_content`/
  `publish_questionnaire` take an optional `companyid` and verify a reused course
  belongs to it (the console now always passes it — see
  `privacient-frontend/lib/iomad.ts`).
- **M3 — session cookie.** `lib/classes/session/manager.php` reverted to
  `SameSite=Lax` for the core Moodle session cookie (the app-only `None` branch
  and the SimpleSAMLphp SP cookie are untouched).
- **M5 — company rebinding.** `saml_sp.php` binds `?company=` only on the SP
  endpoints and only when nobody is logged in.
- **Lows:** `progress.php` requires a real player-start before accepting a video
  completion; `play.php`/`quiz.php` add `require_capability` view checks;
  `scripts/privacient-ws-setup.php` gains an opt-in `PRIVACIENT_WS_IP`
  IP-restriction for the web-service token.

**Files:** `public/auth/iomadsaml2/classes/auth.php`,
`public/lib/classes/session/manager.php`,
`public/local/privacient/classes/{learner.php,saml_sp.php}`,
`public/local/privacient/classes/external/{enrol_learner,delete_content,set_poster,update_content,publish_content,publish_questionnaire,set_saml_config}.php`,
`public/local/privacient/{launch.php,play.php,quiz.php,progress.php}`,
`public/scripts/privacient-ws-setup.php` (path: `scripts/`).

**Deploy — IMPORTANT:**
- `auth.php` and `manager.php` are NOT served from the `local/privacient`
  directory mount. `auth.php` is a single-file bind mount and `manager.php` is
  baked into the image. On the local stack they were applied by restarting the
  `lms` container (re-binds `auth.php`) and `docker cp`-ing `manager.php` in.
  **Any other environment must recreate/rebuild the LMS image** for these two
  files to take effect (a plain restart is enough for `auth.php` if it is a
  bind mount there too; `manager.php` needs an image rebuild).
- No DB upgrade needed (code only). `php -l` clean on every changed file.

**Applied locally:** yes — container restarted; LMS home + `delete_learner` WS
verified working; C1 gate confirmed present in the running container.

**Deliberately not done:** `set_saml_config.php` still enables the `iomadsaml2`
auth plugin site-wide (Moodle has no per-company auth-plugin enablement; removing
it would disable SAML for everyone — the membership gate is the real control).
Web-service capability trimming was left (each capability is plausibly used;
removal risks breaking provisioning).

---

## 2026-09-22 — Security fix: team-member delete could remove unrelated accounts

**Type:** Bug fix (security)

**Why:** The pre-VAPT review found that `learner::remove()` deleted any
account that had *no* company link at all. It never checked that the account
belonged to the company making the call. That could reach platform staff with
system roles, IOMAD "view all" operators, or the console's own web-service
account (`privacient-ws@…`), which would break the integration. On the
detach path it also unenrolled from whatever `courseids` the caller sent, even
another company's courses.

**What changed:**

- The function does nothing (`notfound`) unless the account is linked to
  `companyid` as a learner (`managertype = 0`), and `companyid` must be positive.
- The account is deleted only when it isn't privileged or shared. It is kept and
  only detached if it is a site admin or guest, is linked to another company or
  holds a managing role, holds a web-service token, or has any system- or
  category-level role assignment.
- On detach, `courseids` are narrowed to courses that belong to this company
  (`local_iomad_company_courses`).

**Files:** `public/local/privacient/classes/learner.php`: `remove()`, new
`is_privileged_or_shared()`.

**Deploy:** No version bump or upgrade needed (code only). It takes effect on the
next request.

**Applied locally:** yes. Verified: calls for an unlinked email, and with
`companyid=0`, both return `notfound`.

---

## 2026-09-22 — Delete team members from the LMS

**Type:** New feature (also fixes a data-retention gap)

**Why:** Deleting a team member in the ItelliPhish console warned that their
data would be removed "from everywhere, including … the Learning Platform", but
nothing ever reached the LMS. The Moodle account, its course enrolments,
grades and completions all stayed behind.

**What changed:**

- New web-service function `local_privacient_delete_learner(email, companyid, courseids[])`,
  called by the console as part of deleting a team member. It returns one of:
  - `deleted`: the account belonged to this company alone and held no
    managing role, so it was removed with Moodle's `delete_user()`. That also
    drops enrolments, grades, completions and sessions.
  - `detached`: the same email is also a learner in another company, or a
    company manager, or a site admin. The account is kept; the learner is
    unenrolled from the given `courseids` (the courses this company enrolled
    them on) and unlinked from this company. Another customer's data is never
    touched.
  - `notfound`: no LMS account for that email; nothing to do.
- New `learner::remove()` holds that logic, next to the existing
  `learner::ensure()`.
- The function is registered on the console's `privacient_training`
  web service, and on `iomadservice` where an older install used that.

**Files:**

- `public/local/privacient/classes/external/delete_learner.php` (new)
- `public/local/privacient/classes/learner.php`: `remove()`
- `public/local/privacient/db/services.php`: function definition
- `public/local/privacient/db/upgrade.php`: step `2026092201` registers the function on the service
- `public/local/privacient/version.php`: `2026091600` → `2026092201`
- `scripts/privacient-ws-setup.php`: function added to the service's list for new installs

**Deploy:**

```sh
docker exec lms sh -c 'cd /var/www/html && php admin/cli/upgrade.php --non-interactive'
```

Needed together with the console change that calls it
(`privacient-frontend/lib/delete-learner.ts`). Until the upgrade has run, the
console's team-member delete fails at the LMS step and keeps the team member
so the delete can be retried.

**Applied locally:** yes (plugin version 2026092201). Verified with a REST call
for a non-existent email, which returned `notfound`.
