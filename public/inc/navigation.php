<?php
declare(strict_types=1);

/** Shared menu labels and permission requirements for navigation and breadcrumbs. */
function cms_navigation_items(): array {
    return [
        ['index.php', 'Dashboard', 'dashboard', null],
        ['tasks.php', 'Task list', 'tasks', null],
        ['workflows.php', 'Daily work & review', 'workflows', null],
        ['at_services.php', 'Assistive technology services', 'at_services', null],
        ['clients.php', 'Client records', 'clients', 'clients'],
        ['referrals.php', 'Incoming referrals', 'referrals', 'referrals'],
        ['portal_requests.php', 'Client portal requests', 'portal_requests', 'clients'],
        ['messages.php', 'Client & counselor messages', 'messages', 'clients'],
        ['pipeline.php', 'Intake progress', 'pipeline', 'clients'],
        ['accommodations.php', 'Accommodation assessments', 'accommodations', 'clients'],
        ['schedule.php', 'Session schedule', 'schedule', 'schedule'],
        ['programs.php', 'Group programs', 'programs', 'programs'],
        ['invoices.php', 'Invoices', 'invoices', 'billing'],
        ['contracts.php', 'Grants & contracts', 'contracts', 'billing'], // own entry: billing-only groups cannot open Compliance, its other link
        ['equipment.php', 'Equipment & loans', 'equipment', 'equipment'],
        ['followups.php', 'Device follow-ups', 'followups', 'clients'],
        ['incidents.php', 'Incident reports', 'incidents', 'clients'],
        ['procurement.php', 'Device purchases', 'procurement', 'procurement'],
        ['compliance.php', 'Authorizations & compliance', 'compliance', 'clients'],
        ['analytics.php', 'Business analytics', 'analytics', 'analytics'],
        ['templates.php', 'Goal & assessment templates', 'templates', 'templates'],
        ['kb.php', 'Staff knowledge base', 'kb', null],
        ['ai_tools.php', 'AI tools', 'ai_tools', null],
        ['account.php', 'My Account', 'account', null],
        ['help.php#staff-guide', 'User guide', 'help', null],
    ];
}

/** Parents come from records already authorized by the page, never browser history. */
function cms_breadcrumb_html(string $title, string $active, array $parents, bool $signedIn): string {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $crumbs = [];
    if ($signedIn && $script !== 'index.php') $crumbs[] = ['index.php', 'Dashboard'];
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
