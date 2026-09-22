# LMS changes

A running log of features, changes and bug fixes made to the LMS (IOMAD /
Moodle and the `local_privacient` plugin). Newest first. Each entry says what
changed, why, the files touched, and anything that must be run to deploy it.

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
