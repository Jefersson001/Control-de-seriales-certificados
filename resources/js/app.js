let dashboardController;
const sidebarPreferences = new Map();

const initializeDashboard = () => {
    dashboardController?.abort();
    dashboardController = new AbortController();
    const { signal } = dashboardController;
    const themeButtons = document.querySelectorAll('[data-theme-toggle]');

    const updateThemeButtons = () => {
        const isDark = document.documentElement.classList.contains('dark');
        const label = isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro';

        themeButtons.forEach((button) => {
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        });
    };

    themeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch {}
            updateThemeButtons();
        }, { signal });
    });

    updateThemeButtons();

    const sidebar = document.querySelector('[data-sidebar]');
    const sidebarOverlay = document.querySelector('[data-sidebar-overlay]');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const dashboardContent = document.querySelector('[data-dashboard-content]');

    if (!sidebar || !sidebarOverlay || !sidebarToggle || !dashboardContent) {
        return;
    }

    const desktop = window.matchMedia('(min-width: 1024px)');
    const preferenceKey = () => desktop.matches ? 'dashboard.sidebar.desktop' : 'dashboard.sidebar.mobile';
    const readSidebarPreference = () => {
        let preference = sidebarPreferences.get(preferenceKey());
        try {
            preference = localStorage.getItem(preferenceKey()) ?? preference;
        } catch {}

        return preference === undefined || preference === null ? desktop.matches : preference === 'open';
    };
    let isSidebarOpen = readSidebarPreference();

    const updateSidebar = () => {
        document.documentElement.dataset.sidebarState = isSidebarOpen ? 'open' : 'closed';
        sidebar.classList.toggle('-translate-x-full', ! isSidebarOpen);
        sidebar.classList.toggle('lg:translate-x-0', isSidebarOpen);
        dashboardContent.classList.toggle('lg:pl-72', isSidebarOpen);
        sidebarOverlay.classList.toggle('hidden', ! isSidebarOpen);
        sidebarToggle.setAttribute('aria-expanded', String(isSidebarOpen));
    };
    const saveSidebarPreference = () => {
        const preference = isSidebarOpen ? 'open' : 'closed';
        sidebarPreferences.set(preferenceKey(), preference);
        try {
            localStorage.setItem(preferenceKey(), preference);
        } catch {}
        updateSidebar();
    };

    sidebarToggle.addEventListener('click', () => {
        isSidebarOpen = ! isSidebarOpen;
        saveSidebarPreference();
    }, { signal });

    sidebarOverlay.addEventListener('click', () => {
        isSidebarOpen = false;
        saveSidebarPreference();
    }, { signal });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isSidebarOpen) {
            isSidebarOpen = false;
            saveSidebarPreference();
        }
    }, { signal });

    desktop.addEventListener('change', () => {
        isSidebarOpen = readSidebarPreference();
        updateSidebar();
    }, { signal });

    updateSidebar();
};

initializeDashboard();
document.addEventListener('livewire:navigated', initializeDashboard);
