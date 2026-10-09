(() => {
    const key = 'mco.sidebar.collapsed';
    const root = document.documentElement;
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('mobileBackdrop');
    const openButton = document.getElementById('openSidebar');
    const closeButton = document.getElementById('closeSidebar');
    const collapseButton = document.getElementById('sidebarCollapse');
    let lastFocused = null;

    const isDesktop = () => window.matchMedia('(min-width: 1024px)').matches;
    const setCollapsed = (collapsed, persist = true) => {
        root.classList.toggle('sidebar-collapsed', collapsed && isDesktop());
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        collapseButton?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseButton?.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        if (persist) localStorage.setItem(key, String(collapsed));
    };
    const openSidebar = () => {
        if (isDesktop()) return;
        lastFocused = document.activeElement;
        sidebar?.classList.remove('-translate-x-full');
        backdrop?.classList.remove('hidden');
        openButton?.setAttribute('aria-expanded', 'true');
        document.body.classList.add('overflow-hidden');
        sidebar?.querySelector('a, button')?.focus();
    };
    const closeSidebar = () => {
        if (isDesktop()) return;
        sidebar?.classList.add('-translate-x-full');
        backdrop?.classList.add('hidden');
        openButton?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('overflow-hidden');
        lastFocused?.focus?.();
    };
    const saved = localStorage.getItem(key) === 'true';
    setCollapsed(saved, false);
    collapseButton?.addEventListener('click', () => setCollapsed(!root.classList.contains('sidebar-collapsed')));
    openButton?.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', closeSidebar);
    backdrop?.addEventListener('click', closeSidebar);
    sidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebar));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeSidebar(); });
    window.addEventListener('resize', () => { if (isDesktop()) { document.body.classList.remove('overflow-hidden'); sidebar?.classList.add('-translate-x-full'); backdrop?.classList.add('hidden'); setCollapsed(localStorage.getItem(key) === 'true', false); } else { root.classList.remove('sidebar-collapsed'); } });
})();