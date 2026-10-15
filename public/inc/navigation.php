<?php
declare(strict_types=1);

/** Shared menu labels, permission requirements, groups and pause hints for navigation and breadcrumbs.
 *  Each item: [href, label, active key, permission or null, group key, hint, access key or '']. Access keys
 *  (Alt+key in Chrome and Edge, Alt+Shift+key in Firefox, Control+Option+key on a Mac) work in Forms Mode and
 *  browse mode alike. Digits for the daily pages; letters avoid D, E, F (browser menus), L (voice assistant) and
 *  the page keys N and T; see cms_access_keys(). */
function cms_navigation_items(): array {
    return [
        ['index.php', 'Dashboard', 'dashboard', null, 'today', 'Your starting page: what is due today, caseload totals, expiring authorizations and recent activity.', '1'],
        ['tasks.php', 'Task list', 'tasks', null, 'today', 'To-do items with an owner and a due date.', '2'],
        ['workflows.php', 'Daily work & review', 'workflows', null, 'today', 'What needs attention today, documentation review, AI briefings and the referral waitlist.', '3'],
        ['clients.php', 'Client records', 'clients', 'clients', 'clients', 'Find a person and open their notes, assessments, training tracks and documents.', '4'],
        ['referrals.php', 'Incoming referrals', 'referrals', 'referrals', 'clients', 'New requests for services: review, assign or turn into a client record.', 'r'],
        ['pipeline.php', 'Intake progress', 'pipeline', 'clients', 'clients', 'Where each new client is in intake, so stalled work is easy to spot.', 'i'],
        ['portal_requests.php', 'Client portal requests', 'portal_requests', 'clients', 'clients', 'Appointment and help requests clients sent from their portal.', 'q'],
        ['messages.php', 'Client & counselor messages', 'messages', 'clients', 'clients', 'Conversations with clients and their VR counselors.', '6'],
        ['accommodations.php', 'Accommodation assessments', 'accommodations', 'clients', 'clients', 'Worksite and job accommodation assessments and recommendations.', 'c'],
        ['schedule.php', 'Session schedule', 'schedule', 'schedule', 'services', 'Upcoming sessions; mark sessions completed to log their hours.', '5'],
        ['programs.php', 'Group programs', 'programs', 'programs', 'services', 'Group cohorts, membership and attendance.', 'g'],
        ['at_services.php', 'Assistive technology services', 'at_services', null, 'services', 'Device comparisons, setup instructions, outcomes, repairs and subscriptions.', 'h'],
        ['templates.php', 'Goal & assessment templates', 'templates', 'templates', 'services', 'Reusable goal and assessment wording for training tracks.', 'o'],
        ['equipment.php', 'Equipment & loans', 'equipment', 'equipment', 'equipment', 'Device inventory: who has what, loans, returns and service.', '7'],
        ['procurement.php', 'Device purchases', 'procurement', 'procurement', 'equipment', 'Device requests from justification through order, delivery and training.', 'b'],
        ['followups.php', 'Device follow-ups', 'followups', 'clients', 'equipment', 'Check-ins after a loan: is the device still used, is more help needed.', 'v'],
        ['invoices.php', 'Invoices', 'invoices', 'billing', 'money', 'Bill unbilled work, track submitted invoices and record payments.', '8'],
        ['contracts.php', 'Grants & contracts', 'contracts', 'billing', 'money', 'Funding agreements, budgets, deliverables and spending.', 'k'], // own entry: billing-only groups cannot open Compliance, its other link
        ['compliance.php', 'Authorizations & compliance', 'compliance', 'clients', 'money', 'Authorizations at risk, reports due, consents to renew, device follow-ups due and overdue loans.', 'p'],
        ['incidents.php', 'Incident reports', 'incidents', 'clients', 'money', 'Record an incident and follow it to closure.', 'j'],
        ['analytics.php', 'Business analytics', 'analytics', 'analytics', 'money', 'Charts and every report: outcomes, funders, billing, RSA-911 and utilization.', 'y'],
        ['kb.php', 'Staff knowledge base', 'kb', null, 'help', 'Agency how-to articles you can search or ask questions of.', 'w'],
        ['ai_tools.php', 'AI tools', 'ai_tools', null, 'help', 'AI drafting helpers; always check their work before saving.', 'x'],
        ['help.php#staff-guide', 'User guide', 'help', null, 'help', 'How to use this system, including screen reader tips.', 'u'],
        ['account.php', 'My Account', 'account', null, 'help', 'Your password, sign-in security, display settings and calendar feed.', 'a'],
        ['admin.php', 'Administration', 'admin', 'admin', 'help', 'Team, permissions, sign-in, forms, email, data retention and AI settings.', 'z'],
    ];
}

/** Every access key on the shared page shell, so pages adding their own (N = add, T = log a
 *  session) can be checked against them: tests assert no key is used twice on a page. */
function cms_access_keys(): array {
    $keys = ['m' => 'Skip to main content', '0' => 'Go to main menu', '9' => 'Notifications', 's' => 'Site search'];
    foreach (cms_navigation_items() as $item) if (($item[6] ?? '') !== '') $keys[$item[6]] = $item[1];
    return $keys;
}

/** Menu groups in order: key => visible heading. */
function cms_navigation_groups(): array {
    return ['today' => 'Today', 'clients' => 'Clients', 'services' => 'Services', 'equipment' => 'Equipment',
        'money' => 'Billing, compliance & reports', 'help' => 'Help & account'];
}

/** Parents come from records already authorized by the page, never browser history. */
function cms_breadcrumb_html(string $title, string $active, array $parents, bool $signedIn): string {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $crumbs = [];
    // The Dashboard is the root: it has no breadcrumb of its own.
    if ($signedIn && $script !== 'index.php' && $active !== 'dashboard') $crumbs[] = ['index.php', 'Dashboard'];
    if ($signedIn) foreach (cms_navigation_items() as [$href, $label, $key, $permission]) {
        if ($key !== $active || $key === 'dashboard' || ($permission !== null && !can($permission))) continue;
        $section = explode('#', $href, 2)[0];
        if ($script !== $section || $parents !== [] || array_intersect(['id', 'view', 'kind', 'tab'], array_keys($_GET ?? [])) !== []) $crumbs[] = [$href, $label];
        break;
    }
    foreach ($parents as [$href, $label]) {
        // Only internal PHP pages can be parent destinations; output is escaped below.
        if (!preg_match('/^[a-z][a-z0-9_]*\.php(?:[?#][^\s]*)?$/i', $href)) continue;
        if (!in_array([$href, $label], $crumbs, true)) $crumbs[] = [$href, $label];
    }
    // Signed-out visitors and the Dashboard itself have no parents: no empty landmark.
    if ($crumbs === []) return '';
    $out = '<nav class="breadcrumbs" aria-label="' . h(__('Breadcrumb')) . '"><ol role="list">';
    foreach ($crumbs as [$href, $label]) {
        $out .= '<li><a href="' . h($href) . '">' . h(__($label)) . '</a><span class="breadcrumb-separator" aria-hidden="true"> / </span></li>';
    }
    // Plain current-page text cannot reload the form or accidentally discard a draft.
    return $out . '<li><span aria-current="page">' . h(__($title)) . '</span></li></ol></nav>';
}
