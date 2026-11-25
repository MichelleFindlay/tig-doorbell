/* Light / dark mode toggle: cycles Auto → Light → Dark, remembered on this device. */
(() => {
    const KEY = 'tigdoor-theme';
    const ORDER = ['auto', 'light', 'dark'];
    const LABELS = {
        auto:  { icon: '🌓', text: 'Auto',  title: 'Colour theme: Auto (follows your device). Tap for Light.' },
        light: { icon: '☀️', text: 'Light', title: 'Colour theme: Light. Tap for Dark.' },
        dark:  { icon: '🌙', text: 'Dark',  title: 'Colour theme: Dark. Tap for Auto.' },
    };
    const root = document.documentElement;

    const load = () => {
        try {
            const v = localStorage.getItem(KEY);
            return v === 'light' || v === 'dark' ? v : 'auto';
        } catch (_) {
            return 'auto';
        }
    };

    const save = (mode) => {
        try {
            if (mode === 'auto') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, mode);
        } catch (_) { /* private mode — choice lasts for this page only */ }
    };

    function apply(mode) {
        if (mode === 'auto') delete root.dataset.theme;
        else root.dataset.theme = mode;

        // Keep the phone's browser bar in step with the page colours.
        const bar = getComputedStyle(root).getPropertyValue('--ink').trim();
        if (bar) {
            document.querySelectorAll('meta[name="theme-color"]').forEach((m) => {
                m.removeAttribute('media');
                m.setAttribute('content', bar);
            });
        }

        const l = LABELS[mode];
        document.querySelectorAll('.theme-toggle').forEach((btn) => {
            btn.querySelector('.theme-toggle-icon').textContent = l.icon;
            btn.querySelector('.theme-toggle-label').textContent = l.text;
            btn.title = l.title;
            btn.setAttribute('aria-label', l.title);
        });
    }

    let mode = load();
    apply(mode);

    document.querySelectorAll('.theme-toggle').forEach((btn) => btn.addEventListener('click', () => {
        mode = ORDER[(ORDER.indexOf(mode) + 1) % ORDER.length];
        save(mode);
        apply(mode);
    }));

    // In Auto, follow the device if it switches while the page is open.
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
        if (mode === 'auto') apply('auto');
    });
})();
