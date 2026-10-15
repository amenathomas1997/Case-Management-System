# Release 3.0.25 — October 15, 2026

Quick keys, on top of 3.0.24 (live from 298f796). No database migration; website files only.

- Access keys on the shared shell: 1 Dashboard, 2 Task list, 3 Daily work & review, 4 Client records, 5 Session schedule, 6 Messages, 7 Equipment & loans, 8 Invoices, 9 Notifications, 0 Go to main menu, M Skip to main content, S Site search, U User guide, A My Account (cms_navigation_items() 7th field; cms_access_keys()).
- Page keys: N opens the page's add form (tasks, schedule, client notes, track notes, equipment loan, incidents, contracts, device purchases, each template library); T focuses the Log a session date on a training track.
- navigation.js adds aria-keyshortcuts and a hidden "Shortcut Alt+1." description to every control with an access key, naming the modifier for the visitor's browser (Alt; Alt+Shift in Firefox; Control+Option on a Mac). Read with the control, never as a separate line.
- Staff guide: the quick keys are listed under "Which keyboard and JAWS shortcuts work here?".
- tests/accessibility.php: every access key used once per page; N and T kept free of the shell.
