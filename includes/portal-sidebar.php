<?php
require_once __DIR__ . '/portal-navigation.php';
$portalActivePage = $portalActivePage ?? 'dashboard';
$portalUserDisplayName = $portalUserDisplayName ?? 'Member';
$portalUserEmail = $portalUserEmail ?? '';
$portalInitial = strtoupper(substr(trim($portalUserDisplayName), 0, 1) ?: 'M');
?>
<div id="mobileBackdrop" class="portal-backdrop fixed inset-0 z-40 hidden bg-slate-950/40 backdrop-blur-sm lg:hidden"></div>
<aside id="sidebar" class="portal-sidebar fixed inset-y-0 left-0 z-50 flex -translate-x-full flex-col border-r border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0" aria-label="Portal sidebar">
    <div class="portal-brand shrink-0 border-b border-slate-100 dark:border-slate-800">
        <div class="portal-brand-top min-w-0">
            <img src="assets/images/logo.png" alt="PALECO" class="portal-logo h-11 w-11 shrink-0 object-contain">
            <button id="closeSidebar" type="button" class="ml-auto rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800 lg:hidden" aria-label="Close navigation" title="Close navigation">×</button>
            <button id="sidebarCollapse" type="button" class="portal-desktop-collapse ml-auto rounded-lg p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-paleco-500 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5"><path d="m15 18-6-6 6-6"/></svg>
            </button>
        </div>
        <div class="portal-expanded-only portal-brand-copy min-w-0">
            <div class="break-words text-sm font-bold leading-tight">Palawan Electric Cooperative</div>
            <div class="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">MCO Portal</div>
        </div>
    </div>    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="Main navigation">
        <?php foreach (portalNavigationItems() as $key => $item): ?>
            <?php $active = $portalActivePage === $key; ?>
            <a href="<?= portalShellEscape($item['href']) ?>" class="portal-nav-link <?= $active ? 'bg-paleco-50 text-paleco-800 dark:bg-paleco-900 dark:text-paleco-100' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white' ?>" <?= $active ? 'aria-current="page"' : '' ?> title="<?= portalShellEscape($item['label']) ?>">
                <?= portalNavigationIcon($item['icon']) ?>
                <span class="portal-expanded-only"><?= portalShellEscape($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="shrink-0 border-t border-slate-100 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-slate-800">
        <button id="themeToggle" type="button" class="portal-theme-toggle w-full" aria-label="Switch to dark mode" title="Switch to dark mode"></button>
        <a href="dashboard.php?page=account-settings" class="portal-profile-link mt-3" title="Account & Settings">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-paleco-100 text-sm font-bold text-paleco-800 dark:bg-paleco-900 dark:text-paleco-100" aria-hidden="true"><?= portalShellEscape($portalInitial) ?></span>
            <span class="portal-expanded-only min-w-0"><span class="block truncate text-sm font-semibold"><?= portalShellEscape($portalUserDisplayName) ?></span><?php if ($portalUserEmail !== ''): ?><span class="block truncate text-xs text-slate-500 dark:text-slate-400"><?= portalShellEscape($portalUserEmail) ?></span><?php endif; ?></span>
        </a>
        <a href="logout.php" class="portal-signout-link mt-3" aria-label="Sign Out" title="Sign Out">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 5h5a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-5"/></svg><span class="portal-expanded-only">Sign Out</span>
        </a>
    </div>
</aside>