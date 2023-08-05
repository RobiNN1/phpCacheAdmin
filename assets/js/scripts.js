const ajax = (endpoint, callback, data = null) => {
    let url = window.location.href;
    url += url.includes('?') ? '&' : '?';
    url += !url.includes('type=') ? 'type=' + document.body.dataset.dashboard + '&' : '';
    url = url + 'ajax&' + endpoint;

    let request = new XMLHttpRequest();
    request.open((data === null ? 'GET' : 'POST'), url, true);

    if (data !== null) {
        request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        data = endpoint + '=' + JSON.stringify(data);
    }

    request.onload = callback;
    request.send(data);
}

const replace_query_param = (param, value) => {
    if (location.href.indexOf('?') === -1) {
        location.href = location.href + '?' + param + '=' + value;
    } else if (location.href.indexOf('&' + param + '=') === -1) {
        location.href = location.href + '&' + param + '=' + value;
    } else {
        location.href = location.href.replace(new RegExp('(' + param + '=)[^\&]+'), param + '=' + value);
    }
}

const select_and_redirect = (id, param) => {
    let select = document.getElementById(id);
    if (select) {
        select.addEventListener('change', e => {
            replace_query_param(param, e.target.value);
        });
    }
}

/**
 * Sidebar toggle
 */
document.getElementById('togglebtn').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('hidden');
    document.getElementById('maincontent').classList.toggle('ml-56');
});

/**
 * Keys
 */
const keys = document.querySelectorAll('[data-key]');
keys.forEach(key => {
    key.querySelector('.delete-key').addEventListener('click', () => {
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
});

let check_all = document.querySelector('.check-all');
if (check_all) {
    check_all.addEventListener('change', () => {
        keys.forEach(key => {
            let checkbox = key.querySelector('[type="checkbox"]');

            checkbox.checked = check_all.checked;
        });
    });
}

let delete_selected = document.getElementById('delete_selected');
if (delete_selected) {
    delete_selected.addEventListener('click', () => {
        if (!window.confirm('Are you sure you want to remove selected items?')) {
            return;
        }

        let selected_keys = [];

        document.querySelectorAll('.checkbox-key:checked').forEach(checkbox => {
            let parent = checkbox.parentElement.parentElement;
            selected_keys.push(parent.dataset.key);
            parent.remove();
        });

        ajax('delete', function (request) {
            if (this.status >= 200 && this.status < 400) {
                document.getElementById('alerts').innerHTML = request.currentTarget.response;
            }
        }, selected_keys);
    });
}

let delete_all = document.getElementById('delete_all');
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

const json_syntax_highlight = (json) => {
    return json.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?|[\[\]{}:,s])/g,
        function (match) {
            if (/^"/.test(match)) {
                if (/"(\w+)":/.test(match)) {
                    return '<span class="json-key text-emerald-500">' + match.replace('":', '"') + '</span><span class="json-colon text-gray-900">:</span>';
                } else {
                    return '<span class="json-string text-amber-500">' + match + '</span>';
                }
            } else if (/[\[\]{}]/.test(match)) {
                return '<span class="json-bracket text-gray-900">' + match + '</span>';
            } else if (/true|false/.test(match)) {
                return '<span class="json-boolean text-blue-500">' + match + '</span>';
            } else if (/null/.test(match)) {
                return '<span class="json-null text-rose-500">' + match + '</span>';
            } else if (/^-?\d+(?:\.\d+)?(?:[eE][+\-]?\d+)?$/.test(match)) {
                return '<span class="json-number text-violet-500">' + match + '</span>';
            } else if (match === ',') {
                return '<span class="json-comma text-gray-900">' + match + '</span>';
            } else {
                return match;
            }
        }
    );
}

document.querySelectorAll('.json-code').forEach(value => {
    value.innerHTML = json_syntax_highlight(value.innerHTML);
});

select_and_redirect('per_page', 'pp');
select_and_redirect('server_select', 'server');
select_and_redirect('db_select', 'db');

/**
 * Import form
 */
let import_btn = document.getElementById('import_btn');
if (import_btn) {
    import_btn.addEventListener('click', () => {
        document.getElementById('import_form').classList.toggle('hidden');
    });
}

/**
 * Search key
 */
let submit_search = document.getElementById('submit_search');
if (submit_search) {
    submit_search.addEventListener('click', () => {
        replace_query_param('s', document.getElementById('search_key').value)
    });
}

let search_key = document.getElementById('search_key');
if (search_key) {
    search_key.addEventListener('keypress', e => {
        if (e.key === 'Enter') {
            submit_search.click();
        }
    });
}

/**
 * Redis form
 */
let redis_type = document.getElementById('redis_type');
if (redis_type) {
    redis_type.addEventListener('change', e => {
        document.getElementById('redis_index').style.display = e.target.value === 'list' ? 'block' : 'none';
        document.getElementById('redis_score').style.display = e.target.value === 'zset' ? 'block' : 'none';
        document.getElementById('score').required = e.target.value === 'zset';
        document.getElementById('redis_hash_key').style.display = e.target.value === 'hash' ? 'block' : 'none';
        document.getElementById('hash_key').required = e.target.value === 'hash';
    });
}
