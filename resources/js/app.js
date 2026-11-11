import Alpine from 'alpinejs';
import cueChat from './cuechat';

Alpine.data('cueChat', cueChat);
Alpine.data('siteShell', () => ({
    menuOpen: false,
    theme: document.documentElement.dataset.theme || 'light',
    toggleTheme() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = this.theme;
        try { localStorage.setItem('cuelab-theme', this.theme); } catch (error) {}
    },
}));
window.Alpine = Alpine;
Alpine.start();


// A short, one-time rise as each part of the visit guide enters the viewport.
const cueMotion = matchMedia('(prefers-reduced-motion: reduce)');
const cueChapters = document.querySelectorAll('[data-cue-chapter]');
if (cueChapters.length && !cueMotion.matches && 'IntersectionObserver' in window && 'animate' in Element.prototype) {
    const running = new Set();
    const timings = new Map();
    const observer = new IntersectionObserver((entries) => {
        for (const { target, isIntersecting } of entries) {
            if (!isIntersecting) continue;
            observer.unobserve(target);
            if (cueMotion.matches) continue;

            const { distance, delay, duration } = timings.get(target);
            const animation = target.animate([
                { opacity: 0.84, transform: `translateY(${distance}px)` },
                { opacity: 1, transform: 'translateY(0)' },
            ], { duration, delay, easing: 'cubic-bezier(.22,.68,.24,1)', fill: 'backwards' });
            running.add(animation);
            animation.finished.then(() => running.delete(animation), () => running.delete(animation));
        }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    for (const chapter of cueChapters) {
        const lead = chapter.querySelector('[data-cue-lead]');
        timings.set(lead, { distance: 12, delay: 0, duration: 460 });
        observer.observe(lead);
        chapter.querySelectorAll('[data-cue-support]').forEach((tile, index) => {
            timings.set(tile, { distance: 7, delay: [55, 100, 135][index] ?? 135, duration: 390 });
            observer.observe(tile);
        });
    }

    cueMotion.addEventListener('change', ({ matches }) => {
        if (!matches) return;
        observer.disconnect();
        for (const animation of running) animation.cancel();
    });
}
