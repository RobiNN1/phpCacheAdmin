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

const replace_query_param = (param, value) => {
    const url = new URL(location.href);
    const params = new URLSearchParams(url.search);

    params.set(param, value);

    url.search = params.toString();
    location.href = url.toString();
}

const select_and_redirect = (id, param) => {
    const select = document.getElementById(id);

    if (select) {
        select.addEventListener('change', e => {
            replace_query_param(param, e.target.value);
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
                    return `<span class="json-key text-emerald-500">${match.replace('":', '"')}</span><span class="json-colon text-gray-900 dark:text-gray-400">:</span>`;
                } else {
                    return `<span class="json-string text-amber-500">${match}</span>`;
                }
            } else if (/[[\]{}]/.test(match)) {
                return `<span class="json-bracket text-gray-900 dark:text-gray-400">${match}</span>`;
            } else if (/true|false/.test(match)) {
                return `<span class="json-boolean text-blue-500">${match}</span>`;
            } else if (/null/.test(match)) {
                return `<span class="json-null text-rose-500">${match}</span>`;
            } else if (/^-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(match)) {
                return `<span class="json-number text-violet-500">${match}</span>`;
            } else if (match === ',') {
                return `<span class="json-comma text-gray-900 dark:text-gray-400">${match}</span>`;
            } else {
                return match;
            }
        }
    );
}

document.querySelectorAll('.json-code').forEach(value => {
    value.innerHTML = json_syntax_highlight(value.innerHTML);
});

/**
 * Redirects
 */
select_and_redirect('per_page', 'pp');
select_and_redirect('server_select', 'server');
select_and_redirect('db_select', 'db');

/**
 * Import form
 */
const import_btn = document.getElementById('import_btn');
if (import_btn) {
    import_btn.addEventListener('click', () => {
        document.getElementById('import_form').classList.toggle('hidden');
    });
}

/**
 * Search form
 */
const search_form = document.getElementById('search_form');
if (search_form) {
    const submit_search = document.getElementById('submit_search');
    submit_search.addEventListener('click', () => {
        replace_query_param('s', document.getElementById('search_key').value)
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
 * Light / Dark mode
 */
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', event => {
    if (localStorage.theme === 'system' && event.matches) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
});

const update_theme = () => {
    if (!('theme' in localStorage)) {
        localStorage.theme = 'system';
    }

    switch (localStorage.theme) {
        case 'system':
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
            break;
        case 'dark':
            document.documentElement.classList.add('dark');
            break;
        case 'light':
            document.documentElement.classList.remove('dark');
            break;
    }
}

update_theme();

const theme_sw = document.querySelectorAll("[data-theme]");

theme_sw.forEach(button => {
    let theme = button.getAttribute('data-theme');

    button.addEventListener('click', () => {
        localStorage.theme = theme;
        update_theme();
        theme_sw.forEach(btn => btn.classList.remove('active'));

        button.classList.add('active');
    });

    if (theme === localStorage.theme) {
        button.classList.add('active');
    }
});
