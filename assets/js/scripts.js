const ajax = (endpoint, callback, data = null, send_json = true) => {
    let url = window.location.href;
    url += url.includes('?') ? '&' : '?';
    url += !url.includes('dashboard=') ? `dashboard=${document.body.dataset.dashboard}&` : '';

    const request = new XMLHttpRequest();
    request.open((data === null ? 'GET' : 'POST'), `${url}ajax&${endpoint}`, true);

    if (data !== null) {
        request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        const csrf_meta = document.querySelector('meta[name="csrf-token"]');
        const csrf_token = csrf_meta ? encodeURIComponent(csrf_meta.content) : '';

        if (send_json) {
            data = `${endpoint}=${encodeURIComponent(JSON.stringify(data))}&csrf_token=${csrf_token}`;
        } else {
            data = Object.keys(data).map(key => encodeURIComponent(key) + '=' + encodeURIComponent(data[key])).join('&');
            data += `&csrf_token=${csrf_token}`;
        }
    }

    request.onload = () => callback(request);
    request.send(data);
};

const ajax_ok = (request) => request.status >= 200 && request.status < 400;

const parse_json = (request) => {
    try {
        return JSON.parse(request.response);
    } catch {
        return null;
    }
};

const set_alerts = (html) => {
    document.getElementById('alerts').innerHTML = html;
};

const query_params = (params) => {
    const url = new URL(location.href);
    const search_params = new URLSearchParams(url.search);

    if (typeof params === 'object') {
        Object.entries(params).forEach(([key, value]) => {
            if (value === null) {
                search_params.delete(key);
            } else {
                search_params.set(key, String(value));
            }
        });
    }

    url.search = search_params.toString();
    location.href = url.toString();
};

const select_and_redirect = (id, param) => {
    const select = document.getElementById(id);

    if (select) {
        select.addEventListener('change', e => {
            query_params({[param]: e.target.value});
        });
    }
};

function number_format(number, decimals = 0) {
    let parts = parseFloat(number).toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandssep);
    return parts.join(decimalsep);
}

function format_bytes(bytes, decimals = 2) {
    if (bytes > 1099511627776) {
        return number_format(bytes / 1099511627776, decimals) + 'TB';
    }

    if (bytes > 1073741824) {
        return number_format(bytes / 1073741824, decimals) + 'GB';
    }

    if (bytes > 1048576) {
        return number_format(bytes / 1048576, decimals) + 'MB';
    }

    if (bytes > 1024) {
        return number_format(bytes / 1024, decimals) + 'KB';
    }

    return number_format(bytes, decimals) + 'B';
}

class TreeView {
    constructor(element) {
        this.element = element;
        this.is_expanded = false;
        this.expand_toggle = element.querySelector('.expand-toggle');

        // Folder state is stored per dashboard/server/database.
        const url_params = new URLSearchParams(window.location.search);
        this.storage_key = 'open_folders_' + [
            document.body.dataset.dashboard || '',
            url_params.get('server') || '0',
            url_params.get('db') || '0',
        ].join(':');

        this.expand_toggle.addEventListener('click', () => this.#toggle_all());
        this.element.addEventListener('click', e => this.#handle_toggle_click(e));

        this.#init_expand_state();
        this.update_counts();
    }

    #open_folders() {
        return JSON.parse(localStorage.getItem(this.storage_key) || '[]');
    }

    #save_open_folders(paths) {
        localStorage.setItem(this.storage_key, JSON.stringify(paths));
    }

    #toggle_all() {
        this.is_expanded = !this.is_expanded;
        this.expand_toggle.textContent = this.is_expanded ? 'Collapse all' : 'Expand all';

        const folders = this.element.querySelectorAll('.tree-toggle');
        folders.forEach(button => this.#toggle_folder(button, this.is_expanded));

        const paths = [...folders].map(f => f.dataset.path).filter(Boolean);
        this.#save_open_folders(this.is_expanded ? paths : []);
    }

    #toggle_folder(button, show = null) {
        const children = button.closest('div').parentElement.querySelector('.tree-children');
        if (!children) return false;

        const chevron = button.querySelector('svg');
        const will_show = show !== null ? show : children.classList.contains('hidden');

        children.classList.toggle('hidden', !will_show);
        chevron.style.transform = will_show ? 'rotate(90deg)' : '';

        return will_show;
    }

    #handle_toggle_click(e) {
        const toggle_btn = e.target.closest('.tree-toggle');
        if (!toggle_btn) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        const is_open = this.#toggle_folder(toggle_btn);
        const path = toggle_btn.dataset.path;

        if (path) {
            const open_folders = this.#open_folders();

            if (is_open) {
                if (!open_folders.includes(path)) open_folders.push(path);
            } else {
                const index = open_folders.indexOf(path);
                if (index > -1) open_folders.splice(index, 1);
            }

            this.#save_open_folders(open_folders);
        }
    }

    #init_expand_state() {
        const open_folders = this.#open_folders();

        if (open_folders.length > 0) {
            this.is_expanded = true;
            this.expand_toggle.textContent = 'Collapse all';
        }

        open_folders.forEach(path => {
            const button = this.element.querySelector(`.tree-toggle[data-path="${path}"]`);
            if (button) this.#toggle_folder(button, true);
        });
    }

    update_counts() {
        this.element.querySelectorAll('.tree-toggle').forEach(folder => {
            const children_wrapper = folder.closest('.tree-group').querySelector('.tree-children');

            if (children_wrapper) {
                const total_items = children_wrapper.querySelectorAll('.keywrapper').length;
                let total_bytes = 0;
                children_wrapper.querySelectorAll('.file-size').forEach(el => {
                    total_bytes += parseFloat(el.getAttribute('data-bytes')) || 0;
                });

                const items_count_span = folder.parentElement.querySelector('.items-count');
                if (items_count_span) {
                    items_count_span.textContent = `(${total_items} items, ${format_bytes(total_bytes)})`;
                }
            }
        });
    }
}

class KeyList {
    constructor(tree_view = null) {
        this.tree_view = tree_view;
        this.keys = document.querySelectorAll('[data-key]');
        this.delete_selected = document.getElementById('delete_selected');

        this.#init_delete_selected();
        this.#init_key_rows();
        this.#init_delete_all();
        this.#init_check_all();
        this.#init_shift_select();
        this.#init_search();
        this.#init_sorting();
    }

    #remove_key_element(element) {
        if (this.tree_view) {
            element.closest('.keywrapper').remove();
        } else {
            element.remove();
        }
    }

    #init_delete_selected() {
        if (!this.delete_selected) {
            return;
        }

        this.delete_selected.disabled = true;

        this.delete_selected.addEventListener('click', () => {
            if (!window.confirm('Are you sure you want to remove selected items?')) {
                return;
            }

            const selected_keys = [];

            document.querySelectorAll('.check-key:checked').forEach(checkbox => {
                const parent = checkbox.parentElement.parentElement;
                selected_keys.push(parent.dataset.key);
                this.#remove_key_element(parent);
            });

            this.tree_view?.update_counts();

            ajax('delete', (request) => {
                if (ajax_ok(request)) {
                    set_alerts(request.response);
                }

                this.delete_selected.disabled = true;
            }, selected_keys);
        });
    }

    #init_key_rows() {
        this.keys.forEach(key => {
            const check_key = key.querySelector('.check-key');
            if (check_key && this.delete_selected) {
                check_key.addEventListener('change', () => {
                    this.delete_selected.disabled = document.querySelectorAll('.check-key:checked').length < 1;
                });
            }

            const delete_key = key.querySelector('.delete-key');
            if (delete_key) {
                delete_key.addEventListener('click', () => {
                    if (!window.confirm('Are you sure you want to remove this item?')) {
                        return;
                    }

                    ajax('delete', (request) => {
                        if (ajax_ok(request)) {
                            set_alerts(request.response);
                            this.#remove_key_element(key);
                            this.tree_view?.update_counts();
                        }
                    }, key.dataset.key);
                });
            }
        });
    }

    #init_delete_all() {
        const delete_all = document.getElementById('delete_all');
        if (!delete_all) {
            return;
        }

        delete_all.addEventListener('click', () => {
            if (!window.confirm('Are you sure you want to remove all items?')) {
                return;
            }

            ajax('deleteall', (request) => {
                if (ajax_ok(request)) {
                    set_alerts(request.response);

                    if (this.tree_view) {
                        document.querySelector('.tree-content').remove();
                    } else {
                        this.keys.forEach(key => {
                            key.remove();
                        });
                    }

                    document.getElementById('table-no-keys').classList.remove('hidden');
                }
            }, {});
        });
    }

    // Check all keys in a table or a tree view group (delegated, the per-folder checkboxes are nested).
    #init_check_all() {
        document.addEventListener('change', (e) => {
            if (!e.target.matches('input[type="checkbox"].check-all')) {
                return;
            }

            let scope;

            if (e.target.closest('.tree-group')) {
                const tree_group = e.target.closest('.tree-group');
                const children = tree_group.querySelector(':scope > .tree-children');
                scope = children || tree_group;
            } else {
                scope = e.target.closest('table') || e.target.closest('.treeview');
            }

            if (!scope) {
                return;
            }

            scope.querySelectorAll('input[type="checkbox"]:not(.check-all)').forEach(cb => {
                cb.checked = e.target.checked;
                cb.dispatchEvent(new Event('change', {bubbles: true}));
            });
        });
    }

    // Shift-click multi-select.
    #init_shift_select() {
        let last_checked = null;

        document.addEventListener('click', (e) => {
            if (!e.target.matches('input[type="checkbox"]') || e.target.classList.contains('check-all')) {
                return;
            }

            const tree = e.target.closest('.treeview');
            const table = e.target.closest('table');
            let checkboxes;

            if (tree) {
                checkboxes = Array.from(tree.querySelectorAll('.keywrapper input[type="checkbox"]:not(.check-all)'));
            } else if (table) {
                checkboxes = Array.from(table.querySelectorAll('input[type="checkbox"]:not(.check-all)'));
            } else {
                return;
            }

            if (e.shiftKey && last_checked) {
                const start = checkboxes.indexOf(last_checked);
                const end = checkboxes.indexOf(e.target);

                if (start !== -1 && end !== -1) {
                    for (let i = Math.min(start, end); i <= Math.max(start, end); i++) {
                        checkboxes[i].checked = e.target.checked;
                        checkboxes[i].dispatchEvent(new Event('change', {bubbles: true}));
                    }
                }
            }

            last_checked = e.target;
        });
    }

    #init_search() {
        const search_form = document.getElementById('search_form');
        if (!search_form) {
            return;
        }

        const submit_search = document.getElementById('submit_search');
        submit_search.addEventListener('click', () => {
            query_params({p: null, s: document.getElementById('search_key').value});
        });

        document.getElementById('search_key').addEventListener('keypress', e => {
            if (e.key === 'Enter') {
                submit_search.click();
            }
        });
    }

    #init_sorting() {
        document.querySelectorAll('[data-sortcol]').forEach(element => {
            element.addEventListener('click', () => {
                const sort_col = element.getAttribute('data-sortcol');
                const search_params = new URLSearchParams(window.location.search);
                const current_sort_dir = search_params.get('sortcol') === sort_col ? search_params.get('sortdir') || 'none' : 'none';

                const sort_dir_cycle = ['none', 'asc', 'desc'];
                const current_index = sort_dir_cycle.indexOf(current_sort_dir);
                const new_sort_dir = sort_dir_cycle[(current_index + 1) % sort_dir_cycle.length];
                element.setAttribute('data-sortdir', new_sort_dir);

                if (new_sort_dir === 'none') {
                    query_params({sortdir: null, sortcol: null});
                } else {
                    query_params({sortdir: new_sort_dir, sortcol: sort_col});
                }
            });
        });
    }
}

class Panels {
    constructor(refresh_interval) {
        this.refresh();
        setInterval(() => this.refresh(), refresh_interval);
    }

    refresh() {
        ajax('panels', (request) => {
            const data = ajax_ok(request) ? parse_json(request) : null;

            if (data === null) {
                console.error('Error fetching panel data.');
                return;
            }

            for (const section_key in data) {
                const panel_element = document.getElementById(section_key + '_panel');

                if (panel_element) {
                    const section_data = data[section_key];
                    for (const item_key in section_data) {
                        this.#update_panel(panel_element, item_key, section_data[item_key]);
                    }
                }
            }
        });
    }

    #update_panel(panel_element, key, value) {
        const element = panel_element.querySelector(`[data-value="${key}"]`);

        if (!element) return;

        if (Array.isArray(value)) {
            element.textContent = value[0];
            const progress_element = panel_element.querySelector(`[data-progress="${key}"]`);
            if (progress_element) {
                this.#update_progress_bar(progress_element, value[1]);
            }
        } else {
            element.textContent = value;
        }
    }

    #update_progress_bar(progress_element, percentage) {
        let color_class;

        if (progress_element.dataset.type === 'higher') {
            if (percentage >= 80) {
                color_class = 'bg-green-600';
            } else if (percentage >= 50) {
                color_class = 'bg-orange-600';
            } else {
                color_class = 'bg-red-600';
            }
        } else {
            if (percentage <= 50) {
                color_class = 'bg-green-600';
            } else if (percentage <= 80) {
                color_class = 'bg-orange-600';
            } else {
                color_class = 'bg-red-600';
            }
        }

        progress_element.classList.remove('bg-red-600', 'bg-orange-600', 'bg-green-600');
        progress_element.classList.add(color_class);
        progress_element.style.width = percentage + '%';
    }
}

class ThemeSwitcher {
    constructor() {
        if (!('theme' in localStorage)) {
            localStorage.theme = 'system';
        }

        this.update();
        this.#init_buttons();

        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (localStorage.getItem('theme') === 'system') {
                this.update();
            }
        });
    }

    update() {
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
        const theme_color_meta = document.querySelector("meta[name='theme-color']");
        if (theme_color_meta) {
            theme_color_meta.content = theme_colors[current_theme];
        }
    }

    #init_buttons() {
        const theme_switchers = document.querySelectorAll('[data-theme]');

        theme_switchers.forEach(button => {
            const theme = button.getAttribute('data-theme');

            button.addEventListener('click', () => {
                localStorage.setItem('theme', theme);
                this.update();
                theme_switchers.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
            });

            if (theme === localStorage.getItem('theme')) {
                button.classList.add('active');
            }
        });
    }
}

class Modal {
    static #open_count = 0;

    static #lock_body_scroll() {
        if (Modal.#open_count === 0) {
            const scrollbar_width = window.innerWidth - document.documentElement.clientWidth;
            if (scrollbar_width > 0) {
                document.body.style.paddingRight = scrollbar_width + 'px';
            }
            document.body.classList.add('overflow-hidden');
        }
        Modal.#open_count++;
    }

    static #unlock_body_scroll() {
        Modal.#open_count = Math.max(0, Modal.#open_count - 1);
        if (Modal.#open_count === 0) {
            document.body.classList.remove('overflow-hidden');
            document.body.style.paddingRight = '';
        }
    }

    constructor(element) {
        this.element = element;
        this.is_open = false;
        this.open_buttons = document.querySelectorAll(`[data-modal-target='#${element.id}']`);
        this.close_buttons = element.querySelectorAll('[data-modal-dismiss]');
        this.backdrop = element.querySelector('.modal-backdrop');

        this.open_buttons.forEach(btn => {
            btn.addEventListener('click', () => this.open());
        });

        this.close_buttons.forEach(btn => {
            btn.addEventListener('click', () => this.close());
        });

        this.backdrop.addEventListener('click', (event) => {
            if (event.target === this.backdrop) this.close();
        });
    }

    open() {
        if (this.is_open) {
            return;
        }

        this.is_open = true;
        this.element.classList.remove('pointer-events-none', 'opacity-0');
        Modal.#lock_body_scroll();
        document.addEventListener('keydown', this.escapeHandler);
    }

    close() {
        if (!this.is_open) {
            return;
        }

        this.is_open = false;
        this.element.classList.add('pointer-events-none', 'opacity-0');
        Modal.#unlock_body_scroll();
        document.removeEventListener('keydown', this.escapeHandler);
    }

    escapeHandler = (event) => {
        if (event.key === 'Escape') this.close();
    };
}

/**
 * View key in a modal: loads the key detail over ajax and handles navigation inside the modal.
 */
class ViewKeyModal {
    static instance = null;

    constructor(modal) {
        this.modal = modal;
        this.content = document.getElementById('view-key-modal-content');
        this.title = document.getElementById('view-key-modal-title');
        this.loading_template = document.getElementById('view-key-loading');
        this.error_template = document.getElementById('view-key-error');
        this.current_url = null;

        // Links inside the modal are loaded via ajax instead of navigating away.
        this.content.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) {
                return;
            }

            const params = new URL(link.href, location.href).searchParams;

            if (params.get('view') === 'key' && !params.has('export')) {
                e.preventDefault();
                this.load(link.getAttribute('href'));
            }
        });

        this.content.addEventListener('change', (e) => {
            if (e.target.id === 'per_page' && this.current_url) {
                const url = new URL(this.current_url, location.href);
                url.searchParams.set('pp', e.target.value);
                url.searchParams.delete('p');
                this.load(url.search);
            }
        });

        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-view-key]');
            if (!link) {
                return;
            }

            e.preventDefault();
            this.load(link.getAttribute('href'));
        });

        ViewKeyModal.instance = this;
    }

    load(href) {
        this.current_url = href;
        this.title.textContent = '';
        this.content.innerHTML = this.loading_template.innerHTML;
        this.modal.open();

        fetch(href + (href.includes('?') ? '&' : '?') + 'ajax')
            .then(response => response.text())
            .then(html => {
                this.content.innerHTML = html;

                // Move the key name into the modal header.
                const name = this.content.querySelector('.view-key-name');
                if (name) {
                    this.title.textContent = name.textContent;
                    name.remove();
                }
            })
            .catch(() => {
                this.content.innerHTML = this.error_template.innerHTML;
            });
    }

    contains(element) {
        return this.content.contains(element);
    }
}

/**
 * Sub-item search (view a key array)
 *
 * Delegated so it also works when the key view is loaded into a modal.
 */
const submit_subsearch = () => {
    const form = document.getElementById('subsearch_form');
    const value = document.getElementById('subsearch_key').value;
    const modal = ViewKeyModal.instance;

    if (modal && modal.contains(form)) {
        modal.load(form.dataset.url + (value !== '' ? '&subsearch=' + encodeURIComponent(value) : ''));
    } else {
        query_params({p: null, subsearch: value || null});
    }
};

document.addEventListener('click', e => {
    if (e.target.closest('#submit_subsearch')) {
        submit_subsearch();
    }
});

document.addEventListener('keypress', e => {
    if (e.key === 'Enter' && e.target.id === 'subsearch_key') {
        e.preventDefault();
        submit_subsearch();
    }
});

/**
 * Charts
 *
 * Updating:
 * https://echarts.apache.org/en/builder/echarts.html?charts=line,treemap&components=gridSimple,title,legendScroll,tooltip&svg=true&api=true
 */
const chart = (instance, options, timestamps) => {
    const {title, tooltip = {}, legend, yAxis, series} = options;

    instance.setOption({
        backgroundColor: 'transparent',
        title: {text: title, left: 'left', padding: [0, 5, 5, 5]},
        tooltip: {trigger: 'axis', ...tooltip},
        legend: {data: legend, type: 'scroll', bottom: 0},
        xAxis: {type: 'category', boundaryGap: false, data: timestamps},
        yAxis: yAxis,
        series: series,
        grid: {left: 10, right: 10, top: 80, bottom: 60}
    });
};

class Metrics {
    constructor(render_charts, chart_config) {
        this.render_charts = render_charts;
        this.chart_config = chart_config;
        this.full_data = [];

        this.#init_time_switcher();
        this.#init_resize_and_theme();

        this.update();
        setInterval(() => this.update(), metrics_refresh_interval);
    }

    update() {
        this.#fetch((data) => {
            this.full_data = data;
            this.render_charts(this.full_data);
        });
    }

    #fetch(callback) {
        ajax('metrics', (request) => {
            if (ajax_ok(request)) {
                const content_type = request.getResponseHeader('content-type');
                const data = content_type && content_type.includes('application/json') ? parse_json(request) : null;

                if (data !== null) {
                    callback(data);
                    set_alerts('');
                } else {
                    // Anything that is not JSON is the server sending a rendered alert.
                    set_alerts(request.responseText);
                }
            } else {
                set_alerts(`Server responded with status ${request.status}`);
            }
        }, {filter: metrics_active_filter}, false);
    }

    #init_time_switcher() {
        const time_buttons = document.querySelectorAll('[data-tab]');

        time_buttons.forEach(button => {
            button.addEventListener('click', () => {
                time_buttons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');

                metrics_active_filter = button.dataset.tab;
                this.update();
            });
        });
    }

    #init_resize_and_theme() {
        window.addEventListener('resize', () => {
            for (const chart_instance of Object.values(this.chart_config)) {
                chart_instance.resize();
            }
        });

        // Echarts cannot change its theme in place, so the instances are recreated.
        const theme_observer = new MutationObserver((mutations_list) => {
            for (const mutation of mutations_list) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const theme = document.documentElement.classList.contains('dark') ? 'dark' : null;

                    for (const key of Object.keys(this.chart_config)) {
                        this.chart_config[key].dispose();
                        const chart_element = document.getElementById(`${key}_chart`);
                        this.chart_config[key] = echarts.init(chart_element, theme, {renderer: 'svg'});
                    }

                    if (this.full_data && this.full_data.length > 0) {
                        this.render_charts(this.full_data);
                    }

                    break;
                }
            }
        });

        theme_observer.observe(document.documentElement, {attributes: true});
    }
}

class Console {
    constructor(options) {
        this.output = document.getElementById('console');
        this.input = document.getElementById('console_input');

        if (!this.output || !this.input) {
            return;
        }

        // Keep the input row as the last child so new output appears above it.
        this.input_row = document.getElementById('console_prompt_row');
        this.prompt = this.input_row.querySelector('span').textContent;

        this.hint_typed = document.getElementById('console_hint_typed');
        this.hint_text = document.getElementById('console_hint_text');

        this.history = [];
        this.history_index = 0; // points one past the last entry (i.e., the "new" line)

        this.commands = {};
        this.command_names = [];

        this.tab_matches = [];
        this.tab_index = -1;
        this.tab_last = '';

        this.input.addEventListener('input', () => this.#update_hint());
        this.input.addEventListener('keydown', e => this.#handle_keydown(e));

        // Clicking anywhere in the terminal (but not to select text) focuses the input.
        this.output.addEventListener('click', () => {
            if ((window.getSelection() ?? '').toString() === '') {
                this.input.focus();
            }
        });

        document.getElementById('console_clear').addEventListener('click', () => {
            this.#clear_output();
            this.input.focus();
        });

        this.#load_history();
        this.#load_commands(options.commandsUrl);

        this.input.focus();
    }

    #scroll_bottom() {
        this.output.scrollTop = this.output.scrollHeight;
    }

    // Resolve the command being typed, preferring a two-word one (e.g., CONFIG GET, STATS ITEMS).
    #command_key(value) {
        const tokens = value.trimStart().split(/\s+/);
        const first = (tokens[0] || '').toUpperCase();

        if (tokens.length >= 2) {
            const two = first + ' ' + tokens[1].toUpperCase();

            if (this.commands[two]) {
                return two;
            }
        }

        return this.commands[first] ? first : null;
    }

    #update_hint() {
        const value = this.input.value;
        const key = value.trim() === '' ? null : this.#command_key(value);
        const args = key && this.commands[key] ? this.commands[key].args : null;

        this.hint_typed.textContent = value;
        this.hint_text.textContent = args ? (value.endsWith(' ') ? '' : ' ') + args : '';
    }

    #complete_command() {
        const value = this.input.value;

        if (value.includes(' ')) {
            return; // Only the command name is completed
        }

        if (this.tab_index === -1 || value !== this.tab_last) {
            const prefix = value.toUpperCase();
            this.tab_matches = this.command_names.filter(name => name.startsWith(prefix));
            this.tab_index = -1;
        }

        if (this.tab_matches.length === 0) {
            return;
        }

        this.tab_index = (this.tab_index + 1) % this.tab_matches.length;
        this.input.value = this.tab_matches[this.tab_index].toLowerCase();
        this.tab_last = this.input.value;
        this.#update_hint();
    }

    #append_line(command, result, is_error) {
        const line = document.getElementById('console_line').content.cloneNode(true);
        const [prompt_span, command_span] = line.querySelectorAll('.flex > span');
        const result_div = line.querySelector('div > div:last-child');

        prompt_span.textContent = this.prompt;
        command_span.textContent = command;

        if (result === '') {
            result_div.remove();
        } else {
            result_div.textContent = result;
            result_div.classList.add(...(is_error ? ['text-red-500', 'dark:text-red-400'] : ['text-gray-600', 'dark:text-gray-400']));
        }

        this.output.insertBefore(line, this.input_row);
        this.#scroll_bottom();
    }

    #append_tab_link(tab) {
        const wrapper = document.createElement('div');
        wrapper.className = 'console-entry mt-0.5';

        const link = document.createElement('a');
        link.href = tab.url;
        link.textContent = tab.label + ' →';
        link.className = 'font-semibold text-primary-500 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-500';

        wrapper.appendChild(link);
        this.output.insertBefore(wrapper, this.input_row);
        this.#scroll_bottom();
    }

    #run(command) {
        this.input.value = '';
        this.#update_hint();
        this.input.disabled = true;

        ajax('console', (request) => {
            this.input.disabled = false;
            const data = ajax_ok(request) ? parse_json(request) : null;

            if (data === null) {
                this.#append_line(command, 'An error occurred while running the command.', true);
            } else if (data.error) {
                this.#append_line(command, '(error) ' + data.error, true);
            } else {
                this.#append_line(command, data.output ?? '', false);
            }

            if (data && data.tab) {
                this.#append_tab_link(data.tab);
            }

            this.input.focus();
        }, {command: command}, false);
    }

    #clear_output() {
        this.output.querySelectorAll('.console-entry').forEach(el => el.remove());
        document.getElementById('console_welcome')?.remove();
    }

    #handle_keydown(e) {
        if (e.key === 'Enter') {
            const command = this.input.value.trim();

            if (command === '') {
                return;
            }

            this.history.push(command);
            this.history_index = this.history.length;

            if (command.toLowerCase() === 'clear') {
                this.#clear_output();
                this.input.value = '';
                this.#update_hint();
                return;
            }

            this.#run(command);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            this.#complete_command();
        } else if (e.key === 'ArrowUp') {
            if (this.history_index > 0) {
                this.history_index--;
                this.input.value = this.history[this.history_index];
                this.#update_hint();
                e.preventDefault();
            }
        } else if (e.key === 'ArrowDown') {
            if (this.history_index < this.history.length - 1) {
                this.history_index++;
                this.input.value = this.history[this.history_index];
            } else {
                this.history_index = this.history.length;
                this.input.value = '';
            }
            this.#update_hint();
            e.preventDefault();
        }
    }

    #load_history() {
        ajax('console&history', (request) => {
            const data = ajax_ok(request) ? parse_json(request) : null;

            if (data && Array.isArray(data.history)) {
                this.history.push(...data.history);
                this.history_index = this.history.length;
            }
        });
    }

    #load_commands(url) {
        fetch(url)
            .then(response => response.ok ? response.json() : {})
            .then(data => {
                this.commands = data || {};
                this.command_names = Object.keys(this.commands).filter(name => !name.includes(' '));
                this.#update_hint();
            })
            .catch(() => {
            });
    }
}

/**
 * Bootstrap
 */
new ThemeSwitcher();

document.addEventListener('DOMContentLoaded', () => {
    const treeview_element = document.querySelector('.treeview');
    const tree_view = treeview_element ? new TreeView(treeview_element) : null;

    new KeyList(tree_view);

    select_and_redirect('per_page', 'pp');
    select_and_redirect('server_select', 'server');
    select_and_redirect('db_select', 'db');

    if (ajax_panels) {
        new Panels(panels_refresh_interval);
    }

    const modals = {};
    document.querySelectorAll('.modal').forEach(modal => {
        modals[modal.id] = new Modal(modal);
    });

    if (modals['view-key-modal'] && document.getElementById('view-key-modal-content')) {
        new ViewKeyModal(modals['view-key-modal']);
    }
});
