const ajax = (endpoint, callback, data = null, send_json = true) => {
    let url = window.location.href;
    url += url.includes('?') ? '&' : '?';
    url += !url.includes('dashboard=') ? `dashboard=${document.body.dataset.dashboard}&` : '';

    const request = new XMLHttpRequest();
    request.open((data === null ? 'GET' : 'POST'), `${url}ajax&${endpoint}`, true);

    if (data !== null) {
        request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

        if (send_json) {
            data = `${endpoint}=${JSON.stringify(data)}`;
        } else {
            data = Object.keys(data).map(key => encodeURIComponent(key) + '=' + encodeURIComponent(data[key])).join('&');
        }
    }

    request.onload = callback;
    request.send(data);
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

        let selected_keys = [];

        document.querySelectorAll('.check-key:checked').forEach(checkbox => {
            let parent = checkbox.parentElement.parentElement;
            selected_keys.push(parent.dataset.key);

            const treeview = document.querySelector('.treeview');
            if (treeview) {
                parent.closest('.keywrapper').remove();
                update_folder_counts();
            } else {
                parent.remove();
            }
        });

        ajax('delete', function (request) {
            if (this.status >= 200 && this.status < 400) {
                document.getElementById('alerts').innerHTML = request.currentTarget.response;
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

            ajax('delete', function (request) {
                if (this.status >= 200 && this.status < 400) {
                    document.getElementById('alerts').innerHTML = request.currentTarget.response;

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

        ajax('deleteall', function (request) {
            if (this.status >= 200 && this.status < 400) {
                document.getElementById('alerts').innerHTML = request.currentTarget.response;

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
        });
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
    ajax('panels', function (request) {
        if (request.currentTarget.status >= 200 && request.currentTarget.status < 400) {
            const data = JSON.parse(request.currentTarget.response);

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
 * Modal
 */
class Modal {
    constructor(element) {
        this.element = element;
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
        this.element.classList.remove('pointer-events-none', 'opacity-0');
        document.addEventListener('keydown', this.escapeHandler);
    }

    close() {
        this.element.classList.add('pointer-events-none', 'opacity-0');
        document.removeEventListener('keydown', this.escapeHandler);
    }

    escapeHandler = (event) => {
        if (event.key === 'Escape') this.close();
    };
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal').forEach(modal => new Modal(modal));
});

/**
 * Charts
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

            metrics_active_filter = parseInt(button.dataset.tab, 10);
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
    ajax('metrics', function (request) {
        if (request.currentTarget.status >= 200 && request.currentTarget.status < 400) {
            const content_type = request.currentTarget.getResponseHeader('content-type');
            const response_text = request.currentTarget.responseText;

            if (content_type && content_type.includes('application/json')) {
                callback(JSON.parse(response_text));
                document.getElementById('alerts').innerHTML = '';
            } else {
                document.getElementById('alerts').innerHTML = response_text;
            }
        } else {
            document.getElementById('alerts').innerHTML = `Server responded with status ${request.status}`;
        }
    }, {points: metrics_active_filter}, false);
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
