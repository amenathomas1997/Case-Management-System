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
