# Release 3.0.30 — October 15, 2026

Web only; no migration. Production baseline: 3.0.29. Follow-up to a user report that native selects were too chatty in JAWS: a scan of all 188 selects across the 134 rendered pages flagged four.

- track.php / templates.php Area selects (334 options): option labels no longer carry "(N)" counts; the total stays on "All areas (N)".
- track.php template search: changing the Area no longer runs a search (and announces "N shown") on every arrow press; it waits 700 ms after the last change, or until the select loses focus.
- track.php Matching templates (server-rendered and live-rendered): grouped by area with optgroup, so each option reads as the goal alone; the preview keeps the full "Area — goal" text.
- federation.php auto-provision group: plain group names and "Off (recommended)"; the label and help carry the explanation.
- Checked and left alone: the note-format selects (they relabel fields silently), the AI provider select (shows/hides fields), the voice volume select (plays a cue by design).

## Deployment verified

Published October 15, 2026 from 36a3dbe (web only, no migration); baseline 3.0.29 matched; backup deploy-3.0.30-20261015-122829; hashes verified; live pages and version checks passed. Verified before publishing: run_all incl. installer e2e; full_schema 898; audit_regression; security; 134 pages scanned clean.
