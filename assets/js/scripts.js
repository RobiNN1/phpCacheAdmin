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
            } else {
                parent.remove();
            }
        });

        update_folder_counts();

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

const check_all = document.querySelector('.check-all');
if (check_all) {
    check_all.addEventListener('change', () => {
        if (delete_selected) {
            delete_selected.disabled = check_all.checked !== true;
        }

        keys.forEach(key => {
            key.querySelector('[type="checkbox"]').checked = check_all.checked;
        });

        // Sync folder checkboxes
        document.querySelectorAll('.check-folder').forEach(checkbox => {
            checkbox.checked = check_all.checked;
        });
    });
}

/**
 * Folder checkbox functionality
 */
const init_folder_checkboxes = () => {
    const treeview = document.querySelector('.treeview');
    if (!treeview) return;

    // Select/deselect all subitems of a folder
    treeview.querySelectorAll('.check-folder').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const folder_wrapper = this.closest('.folder-wrapper');
            if (!folder_wrapper) return;

            const tree_children = folder_wrapper.querySelector('.tree-children');
            if (!tree_children) return;

            // Select all keys within this folder
            tree_children.querySelectorAll('.check-key').forEach(key_checkbox => {
                key_checkbox.checked = this.checked;
            });

            // Select all subfolder checkboxes
            tree_children.querySelectorAll('.check-folder').forEach(folder_checkbox => {
                folder_checkbox.checked = this.checked;
            });

            // Update the state of the delete_selected button
            if (delete_selected) {
                delete_selected.disabled = document.querySelectorAll('.check-key:checked').length < 1;
            }

            // Sync parent checkbox if exists
            sync_parent_folder_checkbox(folder_wrapper);
        });
    });

    // Sync the state of the folder checkbox when child items change
    treeview.querySelectorAll('.check-key').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const folder_wrapper = this.closest('.folder-wrapper');
            if (folder_wrapper) {
                sync_folder_checkbox(folder_wrapper);
            }
        });
    });
};

const sync_folder_checkbox = (folder_wrapper) => {
    const folder_checkbox = folder_wrapper.querySelector(':scope > div > .check-folder');
    if (!folder_checkbox) return;

    const tree_children = folder_wrapper.querySelector('.tree-children');
    if (!tree_children) return;

    const all_key_checkboxes = tree_children.querySelectorAll('.check-key');
    const checked_key_checkboxes = tree_children.querySelectorAll('.check-key:checked');

    // Check if all are selected, uncheck if none, indeterminate if some
    if (checked_key_checkboxes.length === 0) {
        folder_checkbox.checked = false;
        folder_checkbox.indeterminate = false;
    } else if (checked_key_checkboxes.length === all_key_checkboxes.length) {
        folder_checkbox.checked = true;
        folder_checkbox.indeterminate = false;
    } else {
        folder_checkbox.checked = false;
        folder_checkbox.indeterminate = true;
    }

    // Sync parent checkbox if exists
    sync_parent_folder_checkbox(folder_wrapper);
};

const sync_parent_folder_checkbox = (folder_wrapper) => {
    const parent_folder = folder_wrapper.parentElement?.closest('.folder-wrapper');
    if (parent_folder) {
        sync_folder_checkbox(parent_folder);
    }
};

/**
 * Shift-click multi-selection
 */
let last_checked_checkbox = null;

const init_shift_click_selection = () => {
    const treeview = document.querySelector('.treeview');
    if (!treeview) return;

    const all_checkboxes = [...treeview.querySelectorAll('.check-key')];
    if (all_checkboxes.length === 0) return;

    all_checkboxes.forEach(checkbox => {
        checkbox.addEventListener('click', function (e) {
            if (e.shiftKey && last_checked_checkbox && last_checked_checkbox !== this) {
                const start_index = all_checkboxes.indexOf(last_checked_checkbox);
                const end_index = all_checkboxes.indexOf(this);

                const min_index = Math.min(start_index, end_index);
                const max_index = Math.max(start_index, end_index);

                // Apply the same state as the current checkbox
                const new_state = this.checked;
                for (let i = min_index; i <= max_index; i++) {
                    all_checkboxes[i].checked = new_state;

                    // Dispatch change event to sync folder checkboxes
                    all_checkboxes[i].dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            last_checked_checkbox = this;

            // Update the state of the delete_selected button
            if (delete_selected) {
                delete_selected.disabled = document.querySelectorAll('.check-key:checked').length < 1;
            }
        });
    });
};

// Init treeview checkboxes
init_folder_checkboxes();
init_shift_click_selection();

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
 * TTL Countdown - Updates elements with data-ttl-expiry every second
 */
const format_countdown = (expiry_timestamp, granularity) => {
    if (expiry_timestamp <= 0) {
        return "Doesn't expire";
    }

    const now = Math.floor(Date.now() / 1000);
    const remaining = expiry_timestamp - now;

    if (remaining <= 0) {
        return 'Expired';
    }

    const seconds_in_minute = 60;
    const seconds_in_hour = 60 * seconds_in_minute;
    const seconds_in_day = 24 * seconds_in_hour;

    const days = Math.floor(remaining / seconds_in_day);
    const hour_seconds = remaining % seconds_in_day;
    const hours = Math.floor(hour_seconds / seconds_in_hour);
    const minute_seconds = hour_seconds % seconds_in_hour;
    const minutes = Math.floor(minute_seconds / seconds_in_minute);
    const seconds = Math.ceil(minute_seconds % seconds_in_minute);

    const parts = [];

    if (days > 0 && parts.length < granularity) parts.push(`${days} day${days === 1 ? '' : 's'}`);
    if (hours > 0 && parts.length < granularity) parts.push(`${hours} hour${hours === 1 ? '' : 's'}`);
    if (minutes > 0 && parts.length < granularity) parts.push(`${minutes} minute${minutes === 1 ? '' : 's'}`);
    if (seconds > 0 && parts.length < granularity) parts.push(`${seconds} second${seconds === 1 ? '' : 's'}`);

    return parts.length > 0 ? parts.join(' ') : '0 seconds';
};

const update_ttl_countdowns = () => {
    document.querySelectorAll('[data-ttl-expiry]').forEach(element => {
        const expiry = parseInt(element.dataset.ttlExpiry, 10);
        const simpleTTL = format_countdown(expiry, 1);
        if(simpleTTL !== element.textContent) {
            element.textContent = simpleTTL;
        }
        element.title = format_countdown(expiry, 10);
    });
};

// Update TTL countdowns every second
setInterval(update_ttl_countdowns, 1000);

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

function update_folder_counts() {
    const folders = document.querySelectorAll('.tree-toggle[data-path]');

    // Helper function to format bytes
    const format_bytes = (bytes) => {
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + units[i];
    };

    // Recursive function to calculate count and size of a folder
    const calculate_folder_stats = (tree_children) => {
        if (!tree_children) return { count: 0, size: 0 };

        let count = 0;
        let size = 0;

        // Count direct keys
        tree_children.querySelectorAll(':scope > .keywrapper').forEach(wrapper => {
            count++;
            const key_element = wrapper.querySelector('[data-key]');
            if (key_element) {
                // Try to get size from data-size attribute if it exists
                const size_attr = key_element.dataset.size;
                if (size_attr) {
                    size += parseInt(size_attr, 10) || 0;
                }
            }
        });

        // Process subfolders
        tree_children.querySelectorAll(':scope > .folder-wrapper').forEach(subfolder => {
            const subfolder_children = subfolder.querySelector('.tree-children');
            const subfolder_stats = calculate_folder_stats(subfolder_children);
            count += subfolder_stats.count;
            size += subfolder_stats.size;

            // Update the subfolder
            const subfolder_items_count = subfolder.querySelector(':scope > div > .items-count');
            if (subfolder_items_count) {
                subfolder_items_count.dataset.count = subfolder_stats.count;
                subfolder_items_count.dataset.size = subfolder_stats.size;
                subfolder_items_count.textContent = `(${subfolder_stats.count}) ${format_bytes(subfolder_stats.size)}`;
            }
        });

        return { count, size };
    };

    folders.forEach(folder => {
        const path = folder.getAttribute('data-path');
        const tree_children = document.querySelector(`.tree-children[data-path="${path}"]`);
        const items_count = folder.parentElement.querySelector('.items-count');

        if (items_count && tree_children) {
            const stats = calculate_folder_stats(tree_children);
            items_count.dataset.count = stats.count;
            items_count.dataset.size = stats.size;
            items_count.textContent = `(${stats.count} items, ${format_bytes(stats.size)})`;
        }
    });
}

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
