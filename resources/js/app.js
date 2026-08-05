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

        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeButtons();
    });
});

updateThemeButtons();

const sidebar = document.querySelector('[data-sidebar]');
const sidebarOverlay = document.querySelector('[data-sidebar-overlay]');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const dashboardContent = document.querySelector('[data-dashboard-content]');

if (sidebar && sidebarOverlay && sidebarToggle && dashboardContent) {
    let isSidebarOpen = window.matchMedia('(min-width: 1024px)').matches;

    const updateSidebar = () => {
        sidebar.classList.toggle('-translate-x-full', ! isSidebarOpen);
        sidebar.classList.toggle('lg:translate-x-0', isSidebarOpen);
        dashboardContent.classList.toggle('lg:pl-72', isSidebarOpen);
        sidebarOverlay.classList.toggle('hidden', ! isSidebarOpen);
        sidebarToggle.setAttribute('aria-expanded', String(isSidebarOpen));
    };

    sidebarToggle.addEventListener('click', () => {
        isSidebarOpen = ! isSidebarOpen;
        updateSidebar();
    });

    sidebarOverlay.addEventListener('click', () => {
        isSidebarOpen = false;
        updateSidebar();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isSidebarOpen) {
            isSidebarOpen = false;
            updateSidebar();
        }
    });

    updateSidebar();
}
