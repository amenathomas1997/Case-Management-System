# Release 4.32.1 — October 25, 2026

Web 4.32.1. The phone apps are unchanged and stay at 4.32.0. No database change: the database stays at 4.32.0.

**Not deployed with deploy.sh.** It is published as the GitHub release v4.32.1, and an administrator installs it with Administration, Software update.

**Why.** On 2026-10-25 the maintainer asked for the answers from the "Is my training free?" checker on their own website to carry into the community referral form, so a person doesn't answer the same questions twice.

**What changed**
- **Community referral form:** the agency case and monthly income answers can now arrive in the link, as `role` already could:

  `community_referral.php?lang=en&role=self&vr_case=no&income=below_2000`

  - `vr_case` takes `yes`, `no` or `unsure`, and `income` takes `below_2000` or `above_2000`: the form's own answers. Any other value is ignored.
  - Only a fresh form takes them. When someone has submitted the form, what they submitted is shown, whatever the link says.
  - Nothing is saved from the link; the person still reviews the answers and sends the form.

**Tests**
- Added to tests/referral_request_cases.php (run by full_schema):
  - answers in the link are chosen on a fresh form
  - answers the form doesn't offer are ignored
  - answers someone submitted win over the link
- Checked before commit, each database suite on an empty MariaDB database:
  - full_schema (2837, with the 3 new checks), audit_regression (61), security (69) and staging_scrub (44) pass
  - run_all passes, including the installer end to end and the restore drill

## Published (not deployed)

2026-10-25, after CI passed all three jobs on 51b88a5. The GitHub release v4.32.1 (latest, not a pre-release) carries:
- the install bundle, built from a clean checkout of the tag
- the signed update package: 336 files, verified, and verified again after downloading it back from the release

**Rehearsed:** installed with the 4.32.0 updater's own code and built-in key onto a copy of a 4.32.0 site, made from the v4.32.0 install bundle. The database calls were stood in at version 4.32.0, since this release has no database change.
- The signature verified, the plan showed no problems, and the backup was made first.
- 3 files were replaced (community_referral.php, inc/core.php, inc/release_notes.php), and no migration ran.
- The site's files then matched the v4.32.1 tag exactly.

The live site stays as it is until an administrator installs it with Software update.
