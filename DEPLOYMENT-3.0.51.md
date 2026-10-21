# Release 3.0.51 — October 16, 2026

Web 3.0.51. The iPhone app is unchanged (3.0.46, build 45). Apply dist/migrations/mig_3.0.51.sql before updating files. It adds homework_practice.staff_feedback, feedback_by and feedback_at.

From the roadmap section "Keeping clients and counselors informed":
- **Letters** (letters.php; LETTER_TEMPLATES in inc/portal_history.php).
  - The letters: welcome, appointment confirmation, missed appointment, equipment loan agreement, equipment return receipt, referral acknowledgement to the counselor, and training completion.
  - Each is filled in from the client's record: the current plan, or the plan just finished for a completion letter, plus the next session, the last no-show and the newest loan. Missing details read "[not on file]".
  - Staff edit the letter, then do one of these:
    - save it to the client's documents, optionally in the portal
    - keep it as a case note
    - download it as an accessible HTML page
  - Administrators change each letter's wording; leaving it empty restores the built-in wording.
  - The client page has a "Write a letter" button.
- **Report copies in the portal.** "Also put a copy in the client's portal" on the report page saves an accessible copy, with the signature line, as a Resource.
- **Practice feedback.** Under Daily work, Homework support, each practice update has a collapsed feedback box. Saving it closes the practice task, and the client sees the feedback in their portal.
- **Portal history.** The portal shows:
  - the client's practice updates with feedback
  - finished homework
  - a summary of each finished training plan: completion date, hours, and goals achieved
  These include Spanish strings.
- **Letter list:** the maintainer has not said which letters staff write most; the seven common ones were built and every one is editable.
- **Tests:** tests/portal_letters_cases.php is new.

Review: one reviewer found nothing serious. Every finding was fixed:
- the completion letter uses the finished plan
- titles are skipped in first names
- blank values are marked
- a failed save keeps the typed letter
- the merge-field list is outside the label
- a missing instructor is marked
- downloaded file names have a fallback
- the signature is on the portal copy
- the feedback forms are collapsed to one stop each

Verified before commit:
- full_schema 1255, audit_regression, security, staging_scrub, and run_all.
- The migration rehearsal matched.
- Not checked in a browser or with a screen reader.

## Deployed 2026-10-16 15:30 CT
From 3.0.50 with .audit_work/deploy-3.0.51. mig_3.0.51.sql ran, and both checked columns are present.

CI is green. The syntax check passed, every checksum matched, .htaccess was unchanged and every smoke check passed (health reports 3.0.51). Backup: <private dir>/deploy-3.0.51-20261016-153006.
