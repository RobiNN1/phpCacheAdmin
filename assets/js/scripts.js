/**
 * Menu
 */
document.getElementById('toggle-menu').addEventListener('click', () => {
    document.getElementById('menu').classList.toggle('hidden');
});

/**
 * Light / Dark mode
 */
if (!('theme' in localStorage)) {
    localStorage.theme = 'system';
}
const update_theme = () => {
    const theme = localStorage.getItem('theme');
    let current_theme = theme;

    if (theme === 'system') {
        current_theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('color-theme', 'system');
    } else {
        document.documentElement.setAttribute('color-theme', theme);
    }

    document.documentElement.classList.toggle('dark', current_theme === 'dark');

    const theme_colors = {light: '#fff', dark: '#1f2937'};
    document.querySelector("meta[name='theme-color']").content = theme_colors[current_theme];
};

const init_theme_switcher = () => {
    const theme_switchers = document.querySelectorAll("[data-theme]");

    theme_switchers.forEach(button => {
        const theme = button.getAttribute('data-theme');

        button.addEventListener('click', () => {
            localStorage.setItem('theme', theme);
            update_theme();
            theme_switchers.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
        });

        if (theme === localStorage.getItem('theme')) {
            button.classList.add('active');
        }
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (localStorage.getItem('theme') === 'system') {
            update_theme();
        }
    });
};

update_theme();
init_theme_switcher();

/**
 * Tabs
 */
document.addEventListener('DOMContentLoaded', () => {
    const tab_links = document.querySelectorAll('.tab-link');

    tab_links.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const group = btn.getAttribute('data-group');

            const group_buttons = document.querySelectorAll(`.tab-link[data-group="${group}"]`);

            group_buttons.forEach(other_btn => {
                const is_active = other_btn === btn;
                const target_id = other_btn.getAttribute('data-target');
                const content_el = document.getElementById(target_id);

                other_btn.classList.toggle('active', is_active);
                if (content_el) {
                    content_el.classList.toggle('hidden', !is_active);
                }
            });
        });
    });
});
