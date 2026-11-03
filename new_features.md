# LMS changes

A running log of features, changes and bug fixes made to the LMS (IOMAD /
Moodle and the `local_privacient` plugin). Newest first. Each entry says what
changed, why, the files touched, and anything that must be run to deploy it.

---

## 2026-11-03 — Fix: SCORM time spent missing or short in training results

**Type:** Bug fix

**Why:** A learner who finished a SCORM module in one sitting showed no time
spent in the campaign results. One who left part-way and came back showed only
the first sitting. A package reports its time once per session, when it exits
(`LMSFinish` sends `session_time`, and mod_scorm stores the new
`cmi.core.total_time` / `cmi.total_time` in that same save). Moodle fires no
event for that save. Every event the plugin reported on (the SCO opening,
completion changing) happens while the session is still open, so the time it
read was the total before that session. Locally, course 10 had 427s stored in
Moodle and 201s in the console. Completion was saved at 05:28:56 and the
total time at 05:28:59.

**What changed:**
- New `classes/scorm_session.php`, registered on `\core\hook\after_config`.
  For a `/mod/scorm/datamodel.php` request that posts a total time (only
  `LMSFinish` and its unload beacon do), it registers a shutdown function.
  After the save, that function checks that a new total was stored for the
  signed-in learner and calls `observer::scorm_session_closed()`. The time is
  read from Moodle's tables, never from the request. A session that added no
  time sends nothing.
- `observer::scorm_session_closed()` queues the usual progress callback, using
  Moodle's completion state for the status (`scorm_status()`). The console
  never moves a completed module back, and it only ever raises a stored time.
- A learner who leaves early now has their time reported as soon as they
  leave, not the next time they open the module.
- New `scripts/privacient-scorm-time-backfill.php` re-sends the stored totals
  for learners affected before this fix. It does a dry run by default; pass
  `--run` to queue the reports.

**Files:** `local/privacient/classes/scorm_session.php` (new),
`local/privacient/classes/observer.php`, `local/privacient/db/hooks.php`,
`local/privacient/version.php` (2026092403, 1.23.1),
`scripts/privacient-scorm-time-backfill.php` (new).

**Deploy:** version bump, so run the CLI upgrade (`php admin/cli/upgrade.php
--non-interactive`) so Moodle registers the new hook. The console needs no
change. To correct past results, run the backfill dry run, review it, then run
it with `--run`. A learner whom Moodle shows as complete but the console does
not will be completed by the backfill, and their completion email sent.

---

## 2026-11-03 — Fix: portal SAML sign-in signed in the previous person

**Type:** Bug fix (security)

**Why:** After one learner signed in to the portal with SAML, anyone typing a
colleague's address on the same browser was signed straight in, with no
authentication, even after the first learner signed out of the portal. The
portal sign-out only clears the portal cookie; IOMAD's session (and the
SimpleSAMLphp and IdP sessions behind it) stayed, and `portal_sso.php` vouched
for whoever held that session, whatever address had been typed. Both people
are in one company, so the tenant checks did not catch it.

**What changed:**
- The portal's start route now sends `hint`: an HMAC (shared callback secret)
  of the typed address, so the address itself is never in a URL or log.
- `portal_sso.php` compares the hint with the Moodle user it is about to
  vouch for. On a mismatch it ends the Moodle session and restarts the
  hand-off with `ForceAuthn`, so the IdP prompts instead of reusing its
  session. That happens once per hand-off; a second mismatch goes back to
  the portal, which refuses it. An admin's "Log in as" session is never
  vouched for.
- `auth_iomadsaml2\auth::saml_login()` takes an optional `$forceauthn`. When
  it is set, it calls SimpleSAMLphp `login()` with `ForceAuthn` rather than
  `requireAuth()`, which would hand back the cached SP session.
- `saml_sp::identity_hint()` added; `fail_to_portal()` clears the new session
  keys.
- Portal side: the SAML callback refuses any identity that is not the typed
  address. That is the authoritative check; the LMS change is what lets the
  right person sign in.

**Files:** `local/privacient/portal_sso.php`,
`local/privacient/classes/saml_sp.php`, `auth/iomadsaml2/classes/auth.php`.

**Deploy:** no version bump or upgrade. The portal and LMS can ship in either
order: an old LMS ignores `hint` (the portal still refuses the wrong person),
and an old portal sends no hint (the LMS behaves as before).

---

## 2026-11-01 — SCORM player: a close button instead of the back bar, and no ">" toggle

**Type:** Change

**Why:** The "Back to my training" bar across the top of the SCORM player and
Moodle's ">" TOC toggle under it each took a row of height from the package,
and the toggle only reopens a table of contents we deliberately hide.

**What changed** (learner SCORM pages, `hook_callbacks.php`):
- The bar is gone. In its place a round close (×) button sits in the top
  right corner and goes to the portal (`portalurl`), which is where the
  learner came from: the portal opens training in the same tab. Its tooltip
  and accessible name are the existing "Back to my training" string.
- The button has room of its own rather than covering the package: on screens
  600px and wider the package frame is 48px narrower, leaving a column down
  the right; below 600px the player starts 48px down, leaving a strip across
  the top. In the app there is no button (the app closes the player itself)
  and no room is reserved.
- The ">" toggle is hidden when the package has one launchable SCO (every
  package so far). It stays for a package with several, because with the TOC
  hidden mod_scorm also hides its prev/next buttons and the toggle is the only
  way between SCOs; the window fit allows for its row there.
- Quiz pages keep their back link and header; the intro slate and the video
  player keep their "Back to my training" link.

**Files:** `public/local/privacient/classes/hook_callbacks.php`.

**Deploy:** deploy the plugin; no upgrade needed (code-only, no new strings).

**Tested:** headless Chrome as a learner on a published single-SCO package:
1440×654 and 1280×520 (no bar, no toggle, player from the top of the window,
page = window, button beside the frame without overlapping it), 390×700 (button
in the strip above the frame), the app's user agent (no button, no strip),
and the button lands on the portal.

---

## 2026-11-01 — Fix: SCORM player ran off the bottom of the window

**Type:** Bug fix

**Why:** On a laptop-height window the bottom of a SCORM package was cut off
and the learner had to scroll the page to see the whole module. Three things in
core mod_scorm add up: it sizes the player to the viewport but never below
680px (`mod/scorm/module.js`), puts a 50px margin under it
(`mod/scorm/styles.css`), and with the TOC hidden (`hidetoc=1`, as we publish)
its ">" toggle takes a row of its own while the content under it is still 100%
of the player's height. Measured at 1440×654: a 759px page in a 654px window.

**What changed:** the learner SCORM chrome injected by
`hook_callbacks::scorm_chrome_css()` now removes that margin and sizes the
player to exactly the space left in the window: a small script measures it
and sets the height through a CSS variable, taking off whatever still hangs
below. It refits on resize, when mod_scorm builds the player, and when the
TOC is opened or closed (watched with a MutationObserver: the content wrapping
under the toggle moves it without resizing anything). Below 320px it stops
shrinking and the page scrolls. Until the script runs, mod_scorm's own height
stands. Core mod_scorm is untouched, and so is the ">" toggle: with the TOC
hidden mod_scorm also hides its prev/next buttons, so in a multi-SCO package
the toggle is the only way between SCOs. Learners only; staff see Moodle's
player as before. Applies to every existing SCORM activity, no republishing.

**Files:** `public/local/privacient/classes/hook_callbacks.php`.

**Deploy:** deploy the plugin; no upgrade needed (code-only).

**Tested:** headless Chrome as a learner on a published package, 1440×654
(page 654 = window, was 759), 1440×900 and 1280×520 (page = window, no inner
scrollers), TOC opened and closed (refits 575px ↔ 608px, no overflow either
way); the package fills its frame at each size.

---

## 2026-09-24 — Fix: comics were stored as "Other"

**Type:** Bug fix

**Why:** The console's Content Hub offers a **Comic** type, but the plugin's
`add_content` and `update_content` web-service functions had allow-lists without
`comic`, so every comic uploaded (or re-typed via Edit details) was silently
saved as `other`. Comics showed as "Other" on their cards, and the new
collateral branding (console: `lib/collateral-branding.ts`) could not recognise
them.

**What changed:** `comic` added to the allowed kinds in
`classes/external/add_content.php` (and its parameter description) and
`classes/external/update_content.php`.

**Deploy:** deploy the plugin; no upgrade needed (code-only). Comics uploaded
before this fix are still stored as `other` — change each one's type to
**Comic** with *Edit details* in the Content Hub so it is recognised (and
branded).

---

## 2026-09-24 — Resume videos where the learner left off

**Type:** Feature

**Why:** A team member who watched part of a video and left had to start again
from 0:00 — and, with skipping ahead locked, could not even jump back to where
they had been. Worse, completion was judged per visit (time since *this* page
opened), so a video watched in two sittings could never complete.

**What changed:**

- **Resume.** The player now opens where the learner left off, with the progress
  they had ("Watched 60%") and a note: "Welcome back — picking up where you left
  off, at 0:20. You can rewind at any time." The skip-ahead lock starts at how
  far they really got, so they can move freely within what they have watched.
  Positions under 5 s start from the top. Completed videos open at 0:00,
  unlocked, for review (the saved state is cleared on completion).
- **Progress reports (new `watch.php` + `classes/watch_state.php`).** The player
  reports position and furthest point every 5 s while playing (driven by
  `timeupdate`, so a backgrounded tab still reports), on play/pause, and on
  leaving (`navigator.sendBeacon` on `pagehide`). Stored per learner per video
  as a JSON user preference `local_privacient_resume_<cmid>`. Reports for an
  already-completed video are ignored.
- **Server-measured watching.** `watch_state` credits real playing time on the
  server's clock (only between consecutive "playing" reports ≤ 90 s apart —
  paused time and time away never count), and lets the furthest point advance
  no faster than that time + 3 s slack. A forged "I'm at 30 s" report from a
  learner who never pressed play is capped at 3 s.
- **Completion (`progress.php`)** now requires, across all visits: accumulated
  playing time ≥ threshold × duration × 0.9 (and ≥ `minwatchseconds`), and the
  server-policed furthest point ≥ threshold × duration. It folds in the player's
  final position report first. The old per-visit `watchstart` stamp is gone;
  time-spent (`watchtime`) is now the accumulated real watching time.
- Video durations cached per course module (new MUC definition `videoduration`,
  1 h TTL) so frequent reports don't re-read the file.
- `minwatchseconds` setting description updated. New string `resumefrom`.

**Files:** `public/local/privacient/play.php`, `progress.php`, `watch.php` (new),
`classes/watch_state.php` (new), `classes/video.php`, `db/caches.php`,
`db/settings.php`, `lang/en/local_privacient.php`, `version.php` (2026092402,
release 1.23.0).

**Deploy:** deploy the plugin, then `php admin/cli/upgrade.php --non-interactive`
(registers the new cache definition and string). Note: a learner with a player
page already open from *before* the deploy has no progress reports on record, so
that one sitting will not complete — a reload fixes it.

**Tested (headless Chrome, 35 s MP4, real enrolments):** forged 30 s report capped
to 3 s and forged completion refused; visit 1 watched to 20.4 s and left → saved
`pos 20.4, far 20.4, acc 21`; visit 2 opened at 20.4 s with "Watched 60%" and the
welcome-back note, played on from 0:21 after the intro, lock still held beyond
the saved point (seek to 33 s → 21.5 s), free movement within watched range;
completed after a 22 s second visit (a single sitting needs ≥ 30 s) with state
cleared; reopening showed Completed at 0:00, unlocked.

---

## 2026-09-24 — Intro slate wording: "Training for"

**Type:** Change (copy)

**What changed:** the kicker above the company name on the branded intro slate
now reads **"TRAINING FOR"** instead of "SECURITY TRAINING FOR", on both the
video player (`play.php`) and the SCORM intro page (`scorm.php`) — one shared
string, `introkicker`, now `'Training for'` (shown in capitals by CSS).

**Files:** `public/local/privacient/lang/en/local_privacient.php`;
comments in `classes/branding.php`.

**Deploy:** purge caches so the new string is picked up:
`php admin/cli/purge_caches.php` (or Site administration → Development → Purge
caches). No version bump or upgrade needed.

---

## 2026-09-24 — No fast-forwarding in the video player; branded intro for SCORM

**Type:** Feature + bug fix (learners could skip to the end of a video)

**Why:** Team members could drag the seek bar (or use 2× speed) to reach the end
of a training video. Coverage tracking already refused to *count* unplayed
seconds, but the skipping itself was allowed, and the completion endpoint only
enforced a 10-second minimum — so a crafted POST could still complete a video.
Separately, SCORM courses needed the same company-branded intro videos got.

**What changed:**

- **Player (`play.php`)**
  - Tracks the furthest point reached by real playback (advanced against the
    wall clock, so a throttled background tab still counts). Any seek beyond it
    snaps back there, with a notice: "Skipping ahead is turned off until you
    have watched this video. You can rewind at any time." Rewinding is free.
  - Speeds above 1× are refused (`ratechange`), and Chrome's speed menu is
    removed (`controlsList="nodownload noplaybackrate"`).
  - Once the video is completed — on this visit or an earlier one — the lock
    lifts so learners can scrub freely to review.
- **Server (`progress.php` + new `classes/video.php`)**: a completion is now
  refused unless the player has been open for at least the watch threshold's
  share of the video's *real* running time (less 10% slack) — e.g. ≥ 30 s for a
  35 s video at the 95% threshold. The duration is read from the file itself
  (MP4/MOV `moov`→`mvhd`, header reads only, `moov` found wherever the encoder
  put it), never from the browser. Unknown duration (e.g. WebM) falls back to the
  existing `minwatchseconds` floor. This is what makes skipping pointless even
  with the developer console.
- **SCORM intro (new `scorm.php`)**: `launch.php` now routes SCORM modules here
  instead of straight to `mod/scorm/view.php`. It shows the same branded slate
  (logo, "Security training for <Company>", **Start training**); Start plays the
  logo intro, then continues to `mod/scorm/view.php` (skipview → player). Start
  is a real link, so it works without JavaScript; back/forward cache restores
  the slate cleanly. No branding → redirects straight through (old behaviour).
- **Refactor**: slate markup and CSS moved into `branding::slate_html()` /
  `branding::slate_css()`, shared by `play.php` and `scorm.php`.
- Lang strings: `introstarttraining`, `noskipahead`, `nofasterspeed`.

**Files:** `public/local/privacient/play.php`, `progress.php`, `launch.php`,
`scorm.php` (new), `classes/video.php` (new), `classes/branding.php`,
`lang/en/local_privacient.php`, `version.php` (2026092401, release 1.22.0).

**Deploy:** deploy the plugin, then `php admin/cli/upgrade.php --non-interactive`
(registers the new strings). No console change needed beyond the previous entry.

**Tested (headless Chrome, real published video + SCORM package):** forward seek
3 s → 25 s snapped back to 4 s with the notice; rewind allowed; 2× refused; a
forged `watched=100` POST at 12 s into a 35 s video rejected (`too-fast`) — it
would previously have completed; watching through at normal speed completed at
43 s; scrubbing free after completion. SCORM: launch lands on the branded page,
Start plays the intro, then `mod/scorm/player.php` loads and the package finds
the LMS API. Duration parser: 2.9 s clip → 2.9 s, header patched to 600 s →
600 s, non-video → unknown.

---

## 2026-09-24 — Company-branded intro before training videos

**Type:** Feature

**Why:** Clients want their staff to feel the training was prepared for *their*
company. The video player now opens on a slate with the learner's company logo,
"Security training for <Company>" and a Start button; pressing Start plays a
~2.6 s logo intro in the company's brand colour, then the video.

**What changed:**

- **New `local_privacient\branding`** (`classes/branding.php`). Resolves the
  learner's company for the course (a company they belong to that owns the
  course → any of their companies → the course's company) and fetches its name,
  brand colour and logo from the console **server-to-server**, signed like the
  progress callback (HMAC-SHA256 over `{timestamp}.{body}` with
  `callbacksecret`). Logos never get a public URL, so company ids cannot be
  walked to enumerate customers. Cached per company in a new MUC definition
  (`db/caches.php`, `branding`): 10 min for a good answer, 1 min after a failed
  fetch. Console unreachable → falls back to the IOMAD company name (no logo).
  Colour is validated as strict `#rrggbb`; the logo is accepted only as
  webp/png/jpeg, ≤ 1.5 MB, and re-encoded to clean base64 before inlining.
- **`play.php`**: renders the slate over the player (only when branding
  resolves; any failure is swallowed so the video always plays), plus the intro
  sequence. Playback is "unlocked" inside the Start click (muted play + rewind)
  so Safari/iOS allow the later programmatic `play()`; if a browser still
  refuses, the native controls are uncovered for the learner to press play.
  Coverage tracking ignores the intro window, so progress cannot move before
  real playback. Honours `prefers-reduced-motion` (1.2 s, no animation); hidden
  entirely without JavaScript (`<noscript>`); sized for phones.
- **New optional setting `local_privacient/brandingurl`**. Blank (default) =
  derived from `callbackurl` (`…/training/progress` → `…/training/branding/`),
  so an install that already pushes progress needs no new configuration.
- Lang strings: `cachedef_branding`, `introkicker`, `introstart`, `intrologoalt`.
- Console side (privacient-frontend): new signed endpoint
  `POST /api/internal/training/branding/` returning
  `{ companyName, brandColour, logo: { mimeType, data } | null }`; the logo is
  downscaled to a ≤ 480 px WebP rendition (e.g. 21 KB PNG → 7 KB).

**Files:** `public/local/privacient/classes/branding.php` (new),
`public/local/privacient/db/caches.php` (new), `public/local/privacient/play.php`,
`public/local/privacient/db/settings.php`,
`public/local/privacient/lang/en/local_privacient.php`,
`public/local/privacient/version.php` (2026092400, release 1.21.0);
`privacient-frontend/app/api/internal/training/branding/route.ts` (new).

**Deploy:**

1. Deploy the console first (the endpoint must exist), with
   `TRAINING_CALLBACK_SECRET` set in `.env.local`.
2. Deploy the plugin, then run the upgrade (registers the cache definition):
   `php admin/cli/upgrade.php --non-interactive`.
3. Ensure `local_privacient/callbacksecret` equals the console's
   `TRAINING_CALLBACK_SECRET`, and either `callbackurl` is set (branding URL is
   derived) or set `brandingurl` explicitly — with the trailing slash.
4. A company's logo/colour come from the console's Settings → Branding; changes
   show in the player within 10 minutes (or purge caches).

**Tested:** signed endpoint (401 unsigned/bad signature, 404 unknown company,
200 with logo rendition); branding class (fetch 206 ms, cached ~0 ms, fallbacks);
headless Chrome end to end on a real published video — slate with logo and brand
colour, intro, video playing unmuted afterwards, progress held at 0 % during the
intro, completion still recorded after the intro; no-logo fallback; 390 px phone
layout.

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
