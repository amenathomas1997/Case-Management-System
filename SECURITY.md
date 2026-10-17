# Reporting a security problem

This system holds people's case records, so please report anything that could expose or change them privately, not in a public issue.

- Use GitHub's private vulnerability reporting: the repository's Security tab, then Report a vulnerability.
- Say what you found, how to reproduce it, and which version (Administration shows it).
- Please use made-up data only. Never include real client information.

You will get an answer within a week. Fixes ship as a signed release that every installation can install from Administration, Software update. The release notes credit you unless you ask otherwise.

## What every installation should do

- Keep up to date: Settings, Updates set to `github` shows new versions on Administration.
- Keep the private folder (configuration, backups, uploads) outside the web folder, as the installer sets it up.
- Set a backup password so the daily off-site copy is encrypted, and store that password somewhere other than the server.
- Use the built-in two-step sign-in for every staff account.
