<?php $portalPageTitle = $portalPageTitle ?? 'MCO Portal'; ?>
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex h-20 max-w-screen-2xl items-center gap-4 px-4 sm:px-6 lg:px-8">
        <button type="button" id="openSidebar" class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-600 shadow-sm focus:outline-none focus:ring-2 focus:ring-paleco-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 lg:hidden" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" title="Open navigation">☰</button>
        <h1 class="min-w-0 flex-1 truncate text-xl font-bold tracking-tight"><?= portalShellEscape($portalPageTitle) ?></h1>
    </div>
</header>