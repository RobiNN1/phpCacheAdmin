const ajax = (endpoint, callback, data = null) => {
    let url = window.location.href;
    url += url.includes('?') ? '&' : '?';
    url += !url.includes('dashboard=') ? `dashboard=${document.body.dataset.dashboard}&` : '';

    const request = new XMLHttpRequest();
    request.open((data === null ? 'GET' : 'POST'), `${url}ajax&${endpoint}`, true);

    if (data !== null) {
        request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        data = `${endpoint}=${JSON.stringify(data)}`;
    }

    request.onload = callback;
    request.send(data);
}

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
}

const select_and_redirect = (id, param) => {
    const select = document.getElementById(id);

    if (select) {
        select.addEventListener('change', e => {
            query_params({[param]: e.target.value});
        });
    }
}

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
            parent.remove();
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
    if (delete_selected) {
        key.querySelector('.check-key').addEventListener('change', () => {
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
                    key.remove();
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

                keys.forEach(key => {
                    key.remove();
                });

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
    });
}

/**
 * JSON syntax highlighter
 */
const json_syntax_highlight = (json) => {
    return json.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?|[[\]{}:,s])/g,
        match => {
            if (match.startsWith("\"")) {
                if (/"(\w+)":/.test(match)) {
                    return `<span class="json-key">${match.replace('":', '"')}</span><span class="json-colon">:</span>`;
                } else {
                    return `<span class="json-string">${match}</span>`;
                }
            } else if (/[[\]{}]/.test(match)) {
                return `<span class="json-bracket">${match}</span>`;
            } else if (/true|false/.test(match)) {
                return `<span class="json-boolean">${match}</span>`;
            } else if (/null/.test(match)) {
                return `<span class="json-null">${match}</span>`;
            } else if (/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(match)) {
                return `<span class="json-number">${match}</span>`;
            } else if (match === ',') {
                return `<span class="json-comma">${match}</span>`;
            } else {
                return match;
            }
        }
    );
}

document.querySelectorAll('.json-code').forEach(value => {
    value.innerHTML = json_syntax_highlight(value.textContent);
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

    document.addEventListener('keydown', e => {
        if (e.key === '/') {
            e.preventDefault();
            search_key.focus();
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
            query_params({p: null, sortdir: new_sort_dir, sortcol: sort_col});
        }
    });
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

        this.init();
    }

    init() {
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
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.modal').forEach(modal => new Modal(modal));
});
