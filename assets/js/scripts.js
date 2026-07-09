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

/**
 * Keys
 */
const delete_selected = document.getElementById('delete_selected');
if (delete_selected) {
    delete_selected.disabled = true;

    delete_selected.addEventListener('click', () => {
        if (!window.confirm('Are you sure you want to remove selected items?')) {
            return;
        }

        const treeview = document.querySelector('.treeview');
        const selected_keys = [];

        document.querySelectorAll('.check-key:checked').forEach(checkbox => {
            const parent = checkbox.parentElement.parentElement;
            selected_keys.push(parent.dataset.key);

            if (treeview) {
                parent.closest('.keywrapper').remove();
            } else {
                parent.remove();
            }
        });

        if (treeview) {
            update_folder_counts();
        }

        ajax('delete', (request) => {
            if (ajax_ok(request)) {
                set_alerts(request.response);
            }

            delete_selected.disabled = true;
        }, selected_keys);
    });
}

const keys = document.querySelectorAll('[data-key]');
keys.forEach(key => {
    const check_key = key.querySelector('.check-key');
    if (check_key && delete_selected) {
        check_key.addEventListener('change', () => {
            delete_selected.disabled = document.querySelectorAll('.check-key:checked').length < 1;
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

                    const treeview = document.querySelector('.treeview');
                    if (treeview) {
                        key.closest('.keywrapper').remove();
                        update_folder_counts();
                    } else {
                        key.remove();
                    }
                }
            }, key.dataset.key);
        });
    }
});

const delete_all = document.getElementById('delete_all');
if (delete_all) {
    delete_all.addEventListener('click', () => {
        if (!window.confirm('Are you sure you want to remove all items?')) {
            return;
        }

        ajax('deleteall', (request) => {
            if (ajax_ok(request)) {
                set_alerts(request.response);

                const treeview = document.querySelector('.treeview');
                if (treeview) {
                    document.querySelector('.tree-content').remove();
                } else {
                    keys.forEach(key => {
                        key.remove();
                    });
                }

                document.getElementById('table-no-keys').classList.remove('hidden');
            }
        }, {});
    });
}

// Check all keys in a table or treeview
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

    const checkboxes = scope.querySelectorAll('input[type="checkbox"]:not(.check-all)');

    checkboxes.forEach(cb => {
        cb.checked = e.target.checked;
        cb.dispatchEvent(new Event('change', {bubbles: true}));
    });
});

// Shift-click multi-select
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

/**
 * Ajax panels
 */
const update_progress_bar = (progress_element, percentage) => {
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
};

const update_panel_data = (panel_element, key, value) => {
    const element = panel_element.querySelector(`[data-value="${key}"]`);

    if (!element) return;

    if (Array.isArray(value)) {
        element.textContent = value[0];
        const progress_element = panel_element.querySelector(`[data-progress="${key}"]`);
        if (progress_element) {
            update_progress_bar(progress_element, value[1]);
        }
    } else {
        element.textContent = value;
    }
};

const refresh_panels = () => {
    ajax('panels', (request) => {
        if (ajax_ok(request)) {
            const data = JSON.parse(request.response);

            for (const section_key in data) {
                const panel_element = document.getElementById(section_key + '_panel');

                if (panel_element) {
                    const section_data = data[section_key];
                    for (const item_key in section_data) {
                        update_panel_data(panel_element, item_key, section_data[item_key]);
                    }
                }
            }
        } else {
            console.error('Error fetching panel data.');
        }
    });
};

document.addEventListener('DOMContentLoaded', function () {
    if (ajax_panels) {
        refresh_panels();
        setInterval(refresh_panels, panels_refresh_interval);
    }
});

/**
 * Redirects
 */
select_and_redirect('per_page', 'pp');
select_and_redirect('server_select', 'server');
select_and_redirect('db_select', 'db');

/**
 * Search form
 */
const search_form = document.getElementById('search_form');
if (search_form) {
    const submit_search = document.getElementById('submit_search');
    submit_search.addEventListener('click', () => {
        query_params({p: null, s: document.getElementById('search_key').value});
    });

    const search_key = document.getElementById('search_key');
    search_key.addEventListener('keypress', e => {
        if (e.key === 'Enter') {
            submit_search.click();
        }
    });
}

/**
 * Sub-item search (view a key array)
 *
 * Delegated so it also works when the key view is loaded into a modal.
 * Inside the modal the content is refreshed via ajax instead of navigating away.
 */
let view_key_loader = null;

const submit_subsearch = (form) => {
    const value = document.getElementById('subsearch_key').value;
    const modal_content = document.getElementById('view-key-modal-content');

    if (view_key_loader && modal_content && modal_content.contains(form)) {
        view_key_loader(form.dataset.url + (value !== '' ? '&subsearch=' + encodeURIComponent(value) : ''));
    } else {
        query_params({p: null, subsearch: value || null});
    }
};

document.addEventListener('click', e => {
    if (e.target.closest('#submit_subsearch')) {
        submit_subsearch(document.getElementById('subsearch_form'));
    }
});

document.addEventListener('keypress', e => {
    if (e.key === 'Enter' && e.target.id === 'subsearch_key') {
        e.preventDefault();
        submit_subsearch(document.getElementById('subsearch_form'));
    }
});

/**
 * Table sorting
 */
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

/**
 * Tree view
 */
const treeview = document.querySelector('.treeview');
if (treeview) {
    let is_expanded = false;
    const expand_toggle = treeview.querySelector('.expand-toggle');

    expand_toggle.addEventListener('click', function () {
        is_expanded = !is_expanded;
        expand_toggle.textContent = is_expanded ? 'Collapse all' : 'Expand all';

        const folders = treeview.querySelectorAll('.tree-toggle');
        folders.forEach(button => toggle_folder(button, is_expanded));

        const paths = [...folders].map(f => f.dataset.path).filter(Boolean);
        localStorage.setItem('open_folders', is_expanded ? JSON.stringify(paths) : '[]');
    });

    function toggle_folder(button, show = null) {
        const children = button.closest('div').parentElement.querySelector('.tree-children');
        if (!children) return false;

        const chevron = button.querySelector('svg');
        const will_show = show !== null ? show : children.classList.contains('hidden');

        children.classList.toggle('hidden', !will_show);
        chevron.style.transform = will_show ? 'rotate(90deg)' : '';

        return will_show;
    }

    treeview.addEventListener('click', function (e) {
        const toggle_btn = e.target.closest('.tree-toggle');
        if (toggle_btn) {
            e.preventDefault();
            e.stopPropagation();

            const is_open = toggle_folder(toggle_btn);
            const path = toggle_btn.dataset.path;

            if (path) {
                const open_folders = JSON.parse(localStorage.getItem('open_folders') || '[]');

                if (is_open) {
                    if (!open_folders.includes(path)) open_folders.push(path);
                } else {
                    const index = open_folders.indexOf(path);
                    if (index > -1) open_folders.splice(index, 1);
                }

                localStorage.setItem('open_folders', JSON.stringify(open_folders));
            }
        }
    });

    function init_expand_state() {
        const open_folders = JSON.parse(localStorage.getItem('open_folders') || '[]');
        if (open_folders.length > 0) {
            is_expanded = true;
            expand_toggle.textContent = 'Collapse all';
        }

        open_folders.forEach(path => {
            const button = treeview.querySelector(`.tree-toggle[data-path="${path}"]`);
            if (button) toggle_folder(button, true);
        });
    }

    init_expand_state();
}

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

function update_folder_counts() {
    document.querySelectorAll('.tree-toggle').forEach(folder => {
        const children_wrapper = folder.closest('.tree-group').querySelector('.tree-children');

        if (children_wrapper) {
            const total_items = children_wrapper.querySelectorAll('.keywrapper').length;
            let total_bytes = 0;
            children_wrapper.querySelectorAll('.file-size').forEach(el => {
                const bytes = parseFloat(el.getAttribute('data-bytes')) || 0;
                total_bytes += bytes;
            });

            const items_count_span = folder.parentElement.querySelector('.items-count');
            if (items_count_span) {
                items_count_span.textContent = `(${total_items} items, ${format_bytes(total_bytes)})`;
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    update_folder_counts();
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
    const theme_color_meta = document.querySelector("meta[name='theme-color']");
    if (theme_color_meta) {
        theme_color_meta.content = theme_colors[current_theme];
    }
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
 * Modal
 */
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

document.addEventListener('DOMContentLoaded', () => {
    const modals = {};
    document.querySelectorAll('.modal').forEach(modal => {
        modals[modal.id] = new Modal(modal);
    });

    /**
     * View key in a modal
     */
    const view_key_modal = modals['view-key-modal'];
    const view_key_content = document.getElementById('view-key-modal-content');

    if (view_key_modal && view_key_content) {
        const view_key_title = document.getElementById('view-key-modal-title');
        const loading_template = document.getElementById('view-key-loading');
        const error_template = document.getElementById('view-key-error');
        let current_modal_url = null;

        const load_key = (href) => {
            current_modal_url = href;
            view_key_title.textContent = '';
            view_key_content.innerHTML = loading_template.innerHTML;
            view_key_modal.open();

            fetch(href + (href.includes('?') ? '&' : '?') + 'ajax')
                .then(response => response.text())
                .then(html => {
                    view_key_content.innerHTML = html;

                    // Move the key name into the modal header.
                    const name = view_key_content.querySelector('.view-key-name');
                    if (name) {
                        view_key_title.textContent = name.textContent;
                        name.remove();
                    }
                })
                .catch(() => {
                    view_key_content.innerHTML = error_template.innerHTML;
                });
        };

        view_key_loader = load_key;

        view_key_content.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) {
                return;
            }

            const params = new URL(link.href, location.href).searchParams;

            if (params.get('view') === 'key' && !params.has('export')) {
                e.preventDefault();
                load_key(link.getAttribute('href'));
            }
        });

        view_key_content.addEventListener('change', (e) => {
            if (e.target.id === 'per_page' && current_modal_url) {
                const url = new URL(current_modal_url, location.href);
                url.searchParams.set('pp', e.target.value);
                url.searchParams.delete('p');
                load_key(url.search);
            }
        });

        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-view-key]');
            if (!link) {
                return;
            }

            e.preventDefault();
            load_key(link.getAttribute('href'));
        });
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

const time_switcher = (callback) => {
    const time_buttons = document.querySelectorAll('[data-tab]');

    time_buttons.forEach(button => {
        button.addEventListener('click', () => {
            time_buttons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            metrics_active_filter = button.dataset.tab;
            callback();
        });
    });
};

const charts_theme = (chart_config, callback) => {
    window.addEventListener('resize', () => {
        for (const chart of Object.values(chart_config)) {
            chart.resize();
        }
    });

    const theme_observer = new MutationObserver((mutations_list) => {
        for (const mutation of mutations_list) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                const theme = document.documentElement.classList.contains('dark') ? 'dark' : null;

                for (const key of Object.keys(chart_config)) {
                    chart_config[key].dispose();
                    const chart_element = document.getElementById(`${key}_chart`);
                    chart_config[key] = echarts.init(chart_element, theme, {renderer: 'svg'});
                }

                callback();
                break;
            }
        }
    });

    theme_observer.observe(document.documentElement, {attributes: true});
};

const fetch_metrics = (callback) => {
    ajax('metrics', (request) => {
        if (ajax_ok(request)) {
            const content_type = request.getResponseHeader('content-type');
            const response_text = request.responseText;

            if (content_type && content_type.includes('application/json')) {
                callback(JSON.parse(response_text));
                set_alerts('');
            } else {
                set_alerts(response_text);
            }
        } else {
            set_alerts(`Server responded with status ${request.status}`);
        }
    }, {filter: metrics_active_filter}, false);
};

const init_metrics = (render_charts, chart_config) => {
    let full_data = [];

    const update_page_charts = () => {
        fetch_metrics((data) => {
            full_data = data;
            render_charts(full_data);
        });
    };

    time_switcher(update_page_charts);

    charts_theme(chart_config, function () {
        if (full_data && full_data.length > 0) {
            render_charts(full_data);
        }
    });

    update_page_charts();

    setInterval(update_page_charts, metrics_refresh_interval);
};

/**
 * Interactive command console.
 */
const pcaConsole = (options) => {
    const output = document.getElementById('console');
    const input = document.getElementById('console_input');

    if (!output || !input) {
        return;
    }

    // Keep the input row as the last child so new output appears above it.
    const input_row = document.getElementById('console_prompt_row');
    const prompt = input_row.querySelector('span').textContent;

    const history = [];
    let history_index = 0; // points one past the last entry (i.e., the "new" line)

    const scroll_bottom = () => output.scrollTop = output.scrollHeight;

    const hint_typed = document.getElementById('console_hint_typed');
    const hint_text = document.getElementById('console_hint_text');

    let commands = {};
    let command_names = [];

    // Resolve the command being typed, preferring a two-word one (e.g., CONFIG GET, STATS ITEMS).
    const command_key = (value) => {
        const tokens = value.trimStart().split(/\s+/);
        const first = (tokens[0] || '').toUpperCase();

        if (tokens.length >= 2) {
            const two = first + ' ' + tokens[1].toUpperCase();

            if (commands[two]) {
                return two;
            }
        }

        return commands[first] ? first : null;
    };

    const update_hint = () => {
        const value = input.value;
        const key = value.trim() === '' ? null : command_key(value);
        const args = key && commands[key] ? commands[key].args : null;

        hint_typed.textContent = value;
        hint_text.textContent = args ? (value.endsWith(' ') ? '' : ' ') + args : '';
    };

    let tab_matches = [];
    let tab_index = -1;
    let tab_last = '';

    const complete_command = () => {
        const value = input.value;

        if (value.includes(' ')) {
            return; // only the command name is completed
        }

        if (tab_index === -1 || value !== tab_last) {
            const prefix = value.toUpperCase();
            tab_matches = command_names.filter(name => name.startsWith(prefix));
            tab_index = -1;
        }

        if (tab_matches.length === 0) {
            return;
        }

        tab_index = (tab_index + 1) % tab_matches.length;
        input.value = tab_matches[tab_index].toLowerCase();
        tab_last = input.value;
        update_hint();
    };

    input.addEventListener('input', update_hint);

    const append_line = (command, result, is_error) => {
        const line = document.getElementById('console_line').content.cloneNode(true);
        const [prompt_span, command_span] = line.querySelectorAll('.flex > span');
        const result_div = line.querySelector('div > div:last-child');

        prompt_span.textContent = prompt;
        command_span.textContent = command;

        if (result === '') {
            result_div.remove();
        } else {
            result_div.textContent = result;
            result_div.classList.add(...(is_error ? ['text-red-500', 'dark:text-red-400'] : ['text-gray-600', 'dark:text-gray-400']));
        }

        output.insertBefore(line, input_row);
        scroll_bottom();
    };

    const parse_json = (request) => {
        try {
            return JSON.parse(request.response);
        } catch {
            return null;
        }
    };

    const run = (command) => {
        input.value = '';
        update_hint();
        input.disabled = true;

        ajax('console', (request) => {
            input.disabled = false;
            const data = ajax_ok(request) ? parse_json(request) : null;

            if (data === null) {
                append_line(command, 'An error occurred while running the command.', true);
            } else if (data.error) {
                append_line(command, '(error) ' + data.error, true);
            } else {
                append_line(command, data.output ?? '', false);
            }

            input.focus();
        }, {command: command}, false);
    };

    const clear_output = () => {
        output.querySelectorAll('.console-entry').forEach(el => el.remove());
        document.getElementById('console_welcome')?.remove();
    };

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const command = input.value.trim();

            if (command === '') {
                return;
            }

            history.push(command);
            history_index = history.length;

            if (command.toLowerCase() === 'clear') {
                clear_output();
                input.value = '';
                update_hint();
                return;
            }

            run(command);
        } else if (e.key === 'Tab') {
            e.preventDefault();
            complete_command();
        } else if (e.key === 'ArrowUp') {
            if (history_index > 0) {
                history_index--;
                input.value = history[history_index];
                update_hint();
                e.preventDefault();
            }
        } else if (e.key === 'ArrowDown') {
            if (history_index < history.length - 1) {
                history_index++;
                input.value = history[history_index];
            } else {
                history_index = history.length;
                input.value = '';
            }
            update_hint();
            e.preventDefault();
        }
    });

    // Clicking anywhere in the terminal (but not to select text) focuses the input.
    output.addEventListener('click', () => {
        if ((window.getSelection() ?? '').toString() === '') {
            input.focus();
        }
    });

    document.getElementById('console_clear').addEventListener('click', () => {
        clear_output();
        input.focus();
    });

    ajax('console&history', (request) => {
        const data = ajax_ok(request) ? parse_json(request) : null;

        if (data && Array.isArray(data.history)) {
            history.push(...data.history);
            history_index = history.length;
        }
    });

    fetch(options.commandsUrl)
        .then(response => response.ok ? response.json() : {})
        .then(data => {
            commands = data || {};
            command_names = Object.keys(commands).filter(name => !name.includes(' '));
            update_hint();
        })
        .catch(() => {
        });

    input.focus();
};
