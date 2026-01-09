/**
 * Utility Functions
 */
const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => document.querySelectorAll(selector);
const $id = (id) => document.getElementById(id);

const format_bytes = (bytes) => {
    if (bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + units[i];
};

const format_number = (num) => new Intl.NumberFormat().format(num);

const confirm_action = (message, callback) => {
    if (window.confirm(message)) callback();
};

const show_alert = (response) => {
    $id('alerts').innerHTML = response;
};

const is_success_status = (status) => status >= 200 && status < 400;

const ajax = (endpoint, callback, data = null, send_json = true) => {
    let url = window.location.href;
    url += url.includes('?') ? '&' : '?';
    url += !url.includes('dashboard=') ? `dashboard=${document.body.dataset.dashboard}&` : '';

    const request = new XMLHttpRequest();
    request.open((data === null ? 'GET' : 'POST'), `${url}ajax&${endpoint}`, true);

    if (data !== null) {
        request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        data = send_json
            ? `${endpoint}=${JSON.stringify(data)}`
            : Object.entries(data).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    }

    request.onload = callback;
    request.send(data);
};

const query_params = (params) => {
    const url = new URL(location.href);
    const search_params = new URLSearchParams(url.search);

    Object.entries(params).forEach(([key, value]) => {
        value === null ? search_params.delete(key) : search_params.set(key, String(value));
    });

    url.search = search_params.toString();
    location.href = url.toString();
};

const select_and_redirect = (id, param) => {
    $id(id)?.addEventListener('change', e => query_params({[param]: e.target.value}));
};

const init_once = (element, callback) => {
    if (element.dataset.initialized) return;
    element.dataset.initialized = 'true';
    callback(element);
};

/**
 * Keys Management
 */
const delete_selected = $id('delete_selected');
const keys = $$('[data-key]');
const treeview = $('.treeview');

const update_delete_button_state = () => {
    if (delete_selected) {
        delete_selected.disabled = $$('.check-key:checked').length < 1;
    }
};

const remove_key_element = (key_element) => {
    if (treeview) {
        key_element.closest('.keywrapper')?.remove();
        update_folder_counts();
    } else {
        key_element.remove();
    }
};

if (delete_selected) {
    delete_selected.disabled = true;

    delete_selected.addEventListener('click', () => {
        confirm_action('Are you sure you want to remove selected items?', () => {
            const selected_keys = [];
            $$('.check-key:checked').forEach(checkbox => {
                const parent = checkbox.parentElement.parentElement;
                selected_keys.push(parent.dataset.key);
                remove_key_element(parent);
            });

            ajax('delete', function (request) {
                if (is_success_status(this.status)) show_alert(request.currentTarget.response);
                delete_selected.disabled = true;
            }, selected_keys);
        });
    });
}

keys.forEach(key => {
    key.querySelector('.check-key')?.addEventListener('change', update_delete_button_state);

    key.querySelector('.delete-key')?.addEventListener('click', () => {
        confirm_action('Are you sure you want to remove this item?', () => {
            ajax('delete', function (request) {
                if (is_success_status(this.status)) {
                    show_alert(request.currentTarget.response);
                    remove_key_element(key);
                }
            }, key.dataset.key);
        });
    });
});

$id('delete_all')?.addEventListener('click', () => {
    confirm_action('Are you sure you want to remove all items?', () => {
        ajax('deleteall', function (request) {
            if (is_success_status(this.status)) {
                show_alert(request.currentTarget.response);
                treeview ? $('.tree-content')?.remove() : keys.forEach(k => k.remove());
                $id('table-no-keys')?.classList.remove('hidden');
            }
        });
    });
});

$('.check-all')?.addEventListener('change', function () {
    if (delete_selected) delete_selected.disabled = !this.checked;
    keys.forEach(key => key.querySelector('[type="checkbox"]').checked = this.checked);
    $$('.check-folder').forEach(cb => cb.checked = this.checked);
});

/**
 * Folder checkbox functionality
 */
const sync_folder_checkbox = (folder_wrapper) => {
    const folder_checkbox = folder_wrapper.querySelector(':scope > div > .check-folder');
    const tree_children = folder_wrapper.querySelector('.tree-children');
    if (!folder_checkbox || !tree_children) return;

    const all = tree_children.querySelectorAll('.check-key');
    const checked = tree_children.querySelectorAll('.check-key:checked');

    folder_checkbox.checked = checked.length === all.length && all.length > 0;
    folder_checkbox.indeterminate = checked.length > 0 && checked.length < all.length;

    // Sync parent recursively
    const parent = folder_wrapper.parentElement?.closest('.folder-wrapper');
    if (parent) sync_folder_checkbox(parent);
};

const init_folder_checkboxes = () => {
    if (!treeview) return;

    // Select/deselect all subitems of a folder
    treeview.querySelectorAll('.check-folder').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const folder = this.closest('.folder-wrapper');
            const children = folder?.querySelector('.tree-children');
            if (!children) return;

            children.querySelectorAll('.check-key, .check-folder').forEach(cb => cb.checked = this.checked);
            update_delete_button_state();
            sync_folder_checkbox(folder);
        });
    });

    // Sync folder checkbox when child items change
    treeview.querySelectorAll('.check-key').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const folder = this.closest('.folder-wrapper');
            if (folder) sync_folder_checkbox(folder);
        });
    });
};

/**
 * Shift-click multi-selection
 */
let last_checked_checkbox = null;

const init_shift_click_selection = () => {
    const all_checkboxes = [...$$('.check-key')];
    if (!all_checkboxes.length) return;

    all_checkboxes.forEach(checkbox => {
        checkbox.addEventListener('click', function (e) {
            if (e.shiftKey && last_checked_checkbox && last_checked_checkbox !== this) {
                const [start, end] = [all_checkboxes.indexOf(last_checked_checkbox), all_checkboxes.indexOf(this)].sort((a, b) => a - b);
                for (let i = start; i <= end; i++) {
                    all_checkboxes[i].checked = this.checked;
                    all_checkboxes[i].dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            last_checked_checkbox = this;
            update_delete_button_state();
        });
    });
};

init_folder_checkboxes();
init_shift_click_selection();

/**
 * Ajax panels
 */
const get_progress_color = (percentage, higher_is_better) => {
    const threshold = higher_is_better ? [80, 50] : [50, 80];
    if (percentage >= threshold[0] === higher_is_better) return 'bg-green-600';
    if (percentage >= threshold[1] === higher_is_better) return 'bg-orange-600';
    return 'bg-red-600';
};

const update_progress_bar = (element, percentage) => {
    const color = get_progress_color(percentage, element.dataset.type === 'higher');
    element.classList.remove('bg-red-600', 'bg-orange-600', 'bg-green-600');
    element.classList.add(color);
    element.style.width = `${percentage}%`;
};

const update_panel_data = (panel, key, value) => {
    const element = panel.querySelector(`[data-value="${key}"]`);
    if (!element) return;

    if (Array.isArray(value)) {
        element.textContent = value[0];
        const progress = panel.querySelector(`[data-progress="${key}"]`);
        if (progress) update_progress_bar(progress, value[1]);
    } else {
        element.textContent = value;
    }
};

const refresh_panels = () => {
    ajax('panels', function (request) {
        if (is_success_status(request.currentTarget.status)) {
            const data = JSON.parse(request.currentTarget.response);
            for (const [section_key, section_data] of Object.entries(data)) {
                const panel = $id(`${section_key}_panel`);
                if (panel) {
                    for (const [item_key, value] of Object.entries(section_data)) {
                        update_panel_data(panel, item_key, value);
                    }
                }
            }
        } else {
            console.error('Error fetching panel data.');
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    if (typeof ajax_panels !== 'undefined' && ajax_panels) {
        refresh_panels();
        setInterval(refresh_panels, panels_refresh_interval);
    }
});

/**
 * TTL Countdown
 */
const format_countdown = (expiry_timestamp, granularity) => {
    if (expiry_timestamp <= 0) return "Doesn't expire";

    const remaining = expiry_timestamp - Math.floor(Date.now() / 1000);
    if (remaining <= 0) return 'Expired';

    const units = [
        { value: Math.floor(remaining / 86400), label: 'day' },
        { value: Math.floor((remaining % 86400) / 3600), label: 'hour' },
        { value: Math.floor((remaining % 3600) / 60), label: 'minute' },
        { value: Math.ceil(remaining % 60), label: 'second' }
    ];

    return units
        .filter(u => u.value > 0)
        .slice(0, granularity)
        .map(u => `${u.value} ${u.label}${u.value === 1 ? '' : 's'}`)
        .join(' ') || '0 seconds';
};

const update_ttl_countdowns = () => {
    $$('[data-ttl-expiry]').forEach(element => {
        const expiry = parseInt(element.dataset.ttlExpiry, 10);
        const simpleTTL = format_countdown(expiry, 1);
        if (simpleTTL !== element.textContent) element.textContent = simpleTTL;
        element.title = format_countdown(expiry, 10);
    });
};

setInterval(update_ttl_countdowns, 1000);

/**
 * Redirects & Search
 */
select_and_redirect('per_page', 'pp');
select_and_redirect('server_select', 'server');
select_and_redirect('db_select', 'db');

const search_form = $id('search_form');
if (search_form) {
    const submit_search = $id('submit_search');
    const search_key = $id('search_key');

    submit_search.addEventListener('click', () => query_params({p: null, s: search_key.value}));
    search_key.addEventListener('keypress', e => { if (e.key === 'Enter') submit_search.click(); });
}

/**
 * Table sorting
 */
$$('[data-sortcol]').forEach(element => {
    element.addEventListener('click', () => {
        const sort_col = element.dataset.sortcol;
        const params = new URLSearchParams(window.location.search);
        const current_dir = params.get('sortcol') === sort_col ? (params.get('sortdir') || 'none') : 'none';
        const cycle = ['none', 'asc', 'desc'];
        const new_dir = cycle[(cycle.indexOf(current_dir) + 1) % 3];

        element.dataset.sortdir = new_dir;
        query_params(new_dir === 'none' ? {sortdir: null, sortcol: null} : {sortdir: new_dir, sortcol: sort_col});
    });
});

/**
 * Tree view
 */
if (treeview) {
    let is_expanded = false;
    const expand_toggle = treeview.querySelector('.expand-toggle');

    const toggle_folder = (button, show = null) => {
        const children = button.closest('div').parentElement.querySelector('.tree-children');
        if (!children) return false;

        const will_show = show ?? children.classList.contains('hidden');
        children.classList.toggle('hidden', !will_show);
        button.querySelector('svg').style.transform = will_show ? 'rotate(90deg)' : '';
        return will_show;
    };

    expand_toggle.addEventListener('click', () => {
        is_expanded = !is_expanded;
        expand_toggle.textContent = is_expanded ? 'Collapse all' : 'Expand all';

        const folders = treeview.querySelectorAll('.tree-toggle');
        folders.forEach(btn => toggle_folder(btn, is_expanded));

        const paths = [...folders].map(f => f.dataset.path).filter(Boolean);
        localStorage.setItem('open_folders', is_expanded ? JSON.stringify(paths) : '[]');
    });

    treeview.addEventListener('click', (e) => {
        const toggle_btn = e.target.closest('.tree-toggle');
        if (!toggle_btn) return;

        e.preventDefault();
        e.stopPropagation();

        const is_open = toggle_folder(toggle_btn);
        const path = toggle_btn.dataset.path;

        if (path) {
            const open_folders = JSON.parse(localStorage.getItem('open_folders') || '[]');
            const index = open_folders.indexOf(path);

            if (is_open && index === -1) open_folders.push(path);
            else if (!is_open && index > -1) open_folders.splice(index, 1);

            localStorage.setItem('open_folders', JSON.stringify(open_folders));
        }
    });

    // Initialize expand state
    const open_folders = JSON.parse(localStorage.getItem('open_folders') || '[]');
    if (open_folders.length) {
        is_expanded = true;
        expand_toggle.textContent = 'Collapse all';
        open_folders.forEach(path => {
            const btn = treeview.querySelector(`.tree-toggle[data-path="${path}"]`);
            if (btn) toggle_folder(btn, true);
        });
    }
}

function update_folder_counts() {
    const calculate_stats = (tree_children) => {
        if (!tree_children) return { count: 0, size: 0 };

        let count = 0, size = 0;

        tree_children.querySelectorAll(':scope > .keywrapper').forEach(wrapper => {
            count++;
            const key_el = wrapper.querySelector('[data-key]');
            if (key_el?.dataset.size) size += parseInt(key_el.dataset.size, 10) || 0;
        });

        tree_children.querySelectorAll(':scope > .folder-wrapper').forEach(subfolder => {
            const stats = calculate_stats(subfolder.querySelector('.tree-children'));
            count += stats.count;
            size += stats.size;

            const items_count = subfolder.querySelector(':scope > div > .items-count');
            if (items_count) {
                Object.assign(items_count.dataset, { count: stats.count, size: stats.size });
                items_count.textContent = `(${stats.count}) ${format_bytes(stats.size)}`;
            }
        });

        return { count, size };
    };

    $$('.tree-toggle[data-path]').forEach(folder => {
        const path = folder.dataset.path;
        const tree_children = $(`.tree-children[data-path="${path}"]`);
        const items_count = folder.parentElement.querySelector('.items-count');

        if (items_count && tree_children) {
            const stats = calculate_stats(tree_children);
            Object.assign(items_count.dataset, { count: stats.count, size: stats.size });
            items_count.textContent = `(${stats.count} items, ${format_bytes(stats.size)})`;
        }
    });
}

/**
 * Light / Dark mode
 */
if (!localStorage.theme) localStorage.theme = 'system';

const update_theme = () => {
    const theme = localStorage.getItem('theme');
    const current = theme === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : theme;

    document.documentElement.setAttribute('color-theme', theme);
    document.documentElement.classList.toggle('dark', current === 'dark');
    $("meta[name='theme-color']").content = current === 'dark' ? '#1f2937' : '#fff';
};

const init_theme_switcher = () => {
    const switchers = $$('[data-theme]');

    switchers.forEach(button => {
        const theme = button.dataset.theme;
        if (theme === localStorage.getItem('theme')) button.classList.add('active');

        button.addEventListener('click', () => {
            localStorage.setItem('theme', theme);
            update_theme();
            switchers.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
        });
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (localStorage.getItem('theme') === 'system') update_theme();
    });
};

update_theme();
init_theme_switcher();

/**
 * Modal
 */
class Modal {
    constructor(element) {
        this.element = element;
        this.backdrop = element.querySelector('.modal-backdrop');

        $$(`[data-modal-target='#${element.id}']`).forEach(btn => btn.addEventListener('click', () => this.open()));
        element.querySelectorAll('[data-modal-dismiss]').forEach(btn => btn.addEventListener('click', () => this.close()));
        this.backdrop?.addEventListener('click', (e) => { if (e.target === this.backdrop) this.close(); });
    }

    open() {
        this.element.classList.remove('pointer-events-none', 'opacity-0');
        document.addEventListener('keydown', this._escapeHandler);
    }

    close() {
        this.element.classList.add('pointer-events-none', 'opacity-0');
        document.removeEventListener('keydown', this._escapeHandler);
    }

    _escapeHandler = (e) => { if (e.key === 'Escape') this.close(); };
}

document.addEventListener('DOMContentLoaded', () => {
    $$('.modal').forEach(modal => new Modal(modal));
});

/**
 * Charts
 */
const chart = (instance, options, timestamps) => {
    const { title, tooltip = {}, legend, yAxis, series } = options;
    instance.setOption({
        backgroundColor: 'transparent',
        title: { text: title, left: 'left', padding: [0, 5, 5, 5] },
        tooltip: { trigger: 'axis', ...tooltip },
        legend: { data: legend, type: 'scroll', bottom: 0 },
        xAxis: { type: 'category', boundaryGap: false, data: timestamps },
        yAxis, series,
        grid: { left: 10, right: 10, top: 80, bottom: 60 }
    });
};

const time_switcher = (callback) => {
    const buttons = $$('[data-tab]');
    buttons.forEach(button => {
        button.addEventListener('click', () => {
            buttons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            metrics_active_filter = parseInt(button.dataset.tab, 10);
            callback();
        });
    });
};

const charts_theme = (chart_config, callback) => {
    window.addEventListener('resize', () => Object.values(chart_config).forEach(c => c.resize()));

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                const theme = document.documentElement.classList.contains('dark') ? 'dark' : null;
                for (const key of Object.keys(chart_config)) {
                    chart_config[key].dispose();
                    chart_config[key] = echarts.init($id(`${key}_chart`), theme, { renderer: 'svg' });
                }
                callback();
                break;
            }
        }
    }).observe(document.documentElement, { attributes: true });
};

const fetch_metrics = (callback) => {
    ajax('metrics', function (request) {
        const { status, responseText } = request.currentTarget;
        const content_type = request.currentTarget.getResponseHeader('content-type');

        if (is_success_status(status)) {
            if (content_type?.includes('application/json')) {
                callback(JSON.parse(responseText));
                $id('alerts').innerHTML = '';
            } else {
                show_alert(responseText);
            }
        } else {
            show_alert(`Server responded with status ${status}`);
        }
    }, { points: metrics_active_filter }, false);
};

const init_metrics = (render_charts, chart_config) => {
    let full_data = [];

    const update_charts = () => {
        fetch_metrics(data => {
            full_data = data;
            render_charts(full_data);
        });
    };

    time_switcher(update_charts);
    charts_theme(chart_config, () => { if (full_data?.length) render_charts(full_data); });
    update_charts();
    setInterval(update_charts, metrics_refresh_interval);
};

/**
 * Namespace View
 */
const init_namespace_view = () => {
    const namespaceview = $('.namespaceview');
    if (!namespaceview) return;

    const CHEVRON_SVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16" width="10" height="10" class="-rotate-90 transition-transform"><path d="M8.015 11.2c-.3 0-.6-.1-.8-.4L3.015 5.5c-.3-.4-.3-.9.1-1.3.4-.3.9-.3 1.3.1l3.6 4.5 3.6-4.5c.3-.4.9-.4 1.3-.1s.4.9.1 1.3l-4.2 5.3c-.2.3-.5.4-.8.4Z"/></svg>`;
    const TRASH_SVG = `<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16" width="16" height="16"><path d="M2.5 1a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1H3v9a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V4h.5a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1H2.5zm3 4a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 .5-.5zM8 5a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-1 0v-7A.5.5 0 0 1 8 5zm3 .5v7a.5.5 0 0 1-1 0v-7a.5.5 0 0 1 1 0z"/></svg>`;
    const SPINNER_SVG = `<svg class="inline-block w-4 h-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;

    const toggle_svg = (toggle, expanded) => {
        const svg = toggle.querySelector('svg');
        svg.classList.toggle('rotate-0', expanded);
        svg.classList.toggle('-rotate-90', !expanded);
    };

    const render_namespace_item = (ns, separator) => {
        const item = document.createElement('div');
        item.className = 'namespace-item border-t border-gray-200 dark:border-gray-700';
        item.dataset.namespace = ns.path;
        item.dataset.hasChildren = ns.has_children ? '1' : '0';

        item.innerHTML = `
            <div class="flex gap-1 items-center py-2 px-6 hover:bg-gray-50 dark:hover:bg-white/5">
                <input type="checkbox" class="check-namespace mr-1 mt-0 w-4 h-4 text-primary-500 rounded border-gray-300 focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 dark:bg-gray-700 dark:border-gray-600">
                <button type="button" class="flex gap-1 items-center namespace-toggle ${ns.has_children ? '' : 'invisible'}" data-namespace="${ns.path}">${CHEVRON_SVG}</button>
                <span class="font-semibold text-primary-500 dark:text-primary-400 flex-1">${ns.name}${separator}*</span>
                <div class="flex items-center gap-1 text-sm">
                    <span class="w-24 text-right" title="Keys count">${format_number(ns.count)} keys</span>
                    <span class="w-24 text-right" title="Total size">${format_bytes(ns.size)}</span>
                    <span class="w-24">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 h-2 bg-gray-200 rounded dark:bg-gray-700 overflow-hidden">
                                <div class="h-full bg-primary-500 rounded" style="width: ${ns.percentage}%"></div>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400 w-12 text-right">${ns.percentage.toFixed(1)}%</span>
                        </div>
                    </span>
                    <button class="inline-flex gap-1 items-center font-semibold text-red-500 hover:text-red-700 delete-namespace" type="button" title="Delete all keys in namespace" data-namespace="${ns.path}">${TRASH_SVG} Delete</button>
                </div>
            </div>
            <div class="hidden namespace-children pl-6" data-namespace="${ns.path}">
                <div class="namespace-loading hidden py-4 text-center text-gray-500 dark:text-gray-400">${SPINNER_SVG} Loading...</div>
                <div class="namespace-children-content"></div>
            </div>`;
        return item;
    };

    const render_direct_keys_info = (count, size, percentage) => {
        if (count <= 0) return null;
        const div = document.createElement('div');
        div.className = 'flex gap-1 items-center py-2 px-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50';
        div.innerHTML = `
            <span class="flex-1 text-sm text-gray-600 dark:text-gray-400">
                <span class="font-medium">${format_number(count)}</span> direct key${count > 1 ? 's' : ''} (not in any namespace)
            </span>
            <div class="flex items-center gap-1 text-sm">
                <span class="w-24 text-right" title="Total size">${format_bytes(size)}</span>
                <span class="w-24">
                    <div class="flex items-center gap-2">
                        <div class="flex-1 h-2 bg-gray-200 rounded dark:bg-gray-700 overflow-hidden">
                            <div class="h-full bg-primary-500 rounded" style="width: ${percentage}%"></div>
                        </div>
                        <span class="text-xs text-gray-500 dark:text-gray-400 w-12 text-right">${percentage.toFixed(1)}%</span>
                    </div>
                </span>
                <span class="w-24"></span>
            </div>`;
        return div;
    };

    const render_child_namespaces = (container, namespaces, separator, direct_keys_count = 0, direct_keys_size = 0, direct_keys_percentage = 0) => {
        container.innerHTML = '';
        const direct_info = render_direct_keys_info(direct_keys_count, direct_keys_size, direct_keys_percentage);
        if (direct_info) container.appendChild(direct_info);
        namespaces.forEach(ns => container.appendChild(render_namespace_item(ns, separator)));
        init_namespace_handlers();
    };

    const load_child_namespaces = (namespace_path, container, loading, content, toggle) => {
        if (content.dataset.loaded === 'true') {
            container.classList.toggle('hidden');
            toggle_svg(toggle, !container.classList.contains('hidden'));
            return;
        }

        loading.classList.remove('hidden');
        container.classList.remove('hidden');
        toggle_svg(toggle, true);

        ajax(`namespaces=${encodeURIComponent(namespace_path)}`, function (request) {
            loading.classList.add('hidden');

            if (is_success_status(this.status)) {
                try {
                    const response = JSON.parse(request.currentTarget.response);
                    const separator = $('.namespaceview')?.dataset.separator || ':';

                    if (response.error) {
                        content.innerHTML = `<div class="py-4 px-6 text-red-500">${response.error}</div>`;
                    } else {
                        const namespaces = response.namespaces || [];
                        const direct_keys_count = response.direct_keys_count || 0;
                        const direct_keys_size = response.direct_keys_size || 0;
                        const direct_keys_percentage = response.direct_keys_percentage || 0;

                        if (!namespaces.length && direct_keys_count === 0) {
                            content.innerHTML = '<div class="py-4 px-6 text-gray-500 dark:text-gray-400">No child namespaces.</div>';
                        } else {
                            render_child_namespaces(content, namespaces, separator, direct_keys_count, direct_keys_size, direct_keys_percentage);
                        }
                    }
                    content.dataset.loaded = 'true';
                } catch (e) {
                    content.innerHTML = `<div class="py-4 px-6 text-red-500">Error parsing response: ${e.message}</div>`;
                }
            } else {
                content.innerHTML = '<div class="py-4 px-6 text-red-500">Error loading namespaces</div>';
            }
        });
    };

    const init_namespace_handlers = () => {
        // Initialize namespace toggles
        $$('.namespace-toggle').forEach(toggle => {
            init_once(toggle, (el) => {
                el.addEventListener('click', function () {
                    const item = this.closest('.namespace-item');
                    const container = item.querySelector('.namespace-children');
                    load_child_namespaces(this.dataset.namespace, container, container.querySelector('.namespace-loading'), container.querySelector('.namespace-children-content'), this);
                });
            });
        });

        // Initialize delete buttons
        $$('.delete-namespace').forEach(button => {
            init_once(button, (el) => {
                el.addEventListener('click', function () {
                    const ns = this.dataset.namespace;
                    confirm_action(`Are you sure you want to delete all keys in namespace "${ns}"?`, () => {
                        ajax(`deletenamespace=${encodeURIComponent(ns)}`, function (request) {
                            if (is_success_status(this.status)) {
                                show_alert(request.currentTarget.response);
                                setTimeout(() => location.reload(), 1000);
                            }
                        });
                    });
                });
            });
        });
    };

    // Expand all button
    const expand_btn = $('.expand-all-namespaces');
    if (expand_btn) {
        let expanded = false;
        expand_btn.addEventListener('click', function () {
            expanded = !expanded;
            this.textContent = expanded ? 'Collapse all' : 'Expand all';

            $$('.namespace-item[data-has-children="1"]').forEach(item => {
                const toggle = item.querySelector('.namespace-toggle');
                const container = item.querySelector('.namespace-children');
                if ((expanded && container.classList.contains('hidden')) || (!expanded && !container.classList.contains('hidden'))) {
                    toggle.click();
                }
            });
        });
    }

    // Store separator as data attribute
    const sep_el = $('[data-separator]');
    if (sep_el) namespaceview.dataset.separator = sep_el.dataset.separator || ':';

    init_namespace_handlers();
};

document.addEventListener('DOMContentLoaded', init_namespace_view);

