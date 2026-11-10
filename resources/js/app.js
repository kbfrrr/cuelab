import Alpine from 'alpinejs';
Alpine.data('siteShell', () => ({
    menuOpen: false,
    chatOpen: false,
    theme: document.documentElement.dataset.theme || 'light',
    toggleTheme() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = this.theme;
        try { localStorage.setItem('cuelab-theme', this.theme); } catch (error) {}
    },
}));
window.Alpine = Alpine;
Alpine.start();
