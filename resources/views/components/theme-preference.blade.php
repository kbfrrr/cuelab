<script>
    (() => {
        const key = 'filament:theme:mode';
        let mode = 'system';

        try {
            const saved = localStorage.getItem(key);
            const legacy = localStorage.getItem('cuelab-theme');

            if (['light', 'dark', 'system'].includes(saved)) {
                mode = saved;
            } else if (legacy === 'light' || legacy === 'dark') {
                mode = legacy;
                localStorage.setItem(key, mode);
            }
        } catch {}

        document.documentElement.dataset.theme = mode === 'system'
            ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : mode;
    })();
</script>
