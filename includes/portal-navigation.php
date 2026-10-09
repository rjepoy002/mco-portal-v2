<?php

function portalShellEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function portalNavigationItems(): array
{
    return [
        'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php?page=dashboard', 'icon' => 'home'],
        'bills' => ['label' => 'My Bills', 'href' => 'dashboard.php?page=bills', 'icon' => 'receipt'],
        'account-settings' => ['label' => 'Account & Settings', 'href' => 'dashboard.php?page=account-settings', 'icon' => 'settings'],
        'consumer-education' => ['label' => 'Consumer Education', 'href' => 'consumer-education.php', 'icon' => 'education'],
    ];
}

function portalNavigationIcon(string $icon): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/><path d="M9 21v-6h6v6"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.04 1.56v.08h-3v-.08A1.7 1.7 0 0 0 10.66 18.7a1.7 1.7 0 0 0-1.88.34l-.06.06L6.6 16.98l.06-.06A1.7 1.7 0 0 0 7 15.04a1.7 1.7 0 0 0-1.56-1.04h-.08v-3h.08A1.7 1.7 0 0 0 7 9.96a1.7 1.7 0 0 0-.34-1.88L6.6 8.02 8.72 5.9l.06.06A1.7 1.7 0 0 0 10.66 6.3a1.7 1.7 0 0 0 1.04-1.56v-.08h3v.08A1.7 1.7 0 0 0 15.74 6.3a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 9.96a1.7 1.7 0 0 0 1.56 1.04h.08v3h-.08A1.7 1.7 0 0 0 19.4 15z"/>',
        'education' => '<path d="m4 6 8-3 8 3-8 3z"/><path d="M6.5 9.5V14c0 1.7 2.5 3 5.5 3s5.5-1.3 5.5-3V9.5"/><path d="M20 7v6"/>',
    ];

    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0">'
        . ($paths[$icon] ?? '') . '</svg>';
}