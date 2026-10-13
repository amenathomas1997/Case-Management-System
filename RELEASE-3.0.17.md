# Local release checkpoint — 3.0.17

October 12, 2026: GitHub main was merged into local main in `c6ee710`. Both histories were retained. All 38 preserved local files were restored byte for byte after the merge. The deployment source, tests, migration and release documentation were then prepared as a local release commit.

The deployed application remains 3.0.17. See DEPLOYMENT-3.0.16.md and DEPLOYMENT-3.0.17.md for production verification. The prior 708 integration assertions, referral-language checks and iOS build remain applicable: the merge did not change application source.

Rebuilt `dist/cms_dist.tar.gz` after registering the new source files with Git. Verified all 174 packaged source files against the workspace, including the workflow pages, AT-only referral form, migration, full schema and cron update. Confirmed that site-specific configuration, installer enablement markers and development files are excluded. The archive is an ignored build artifact and is not included in the source commit.

Archive SHA-256: `94995d16b47f7966f3452bbe6c0f3f565a1cae9c86abd832791b900e49d4aefb`

No GitHub push was performed. The existing disabled push URL remains intact because the merged local history contains old secrets. The safety branch and stash remain available. The pre-existing local audit ledger edit and `.codex/` files are outside the release commit. The iOS changes are compiled locally but not distributed through the App Store.
