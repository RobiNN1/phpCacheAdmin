const ajax = (endpoint, callback) => {
    let request = new XMLHttpRequest();

    request.open('GET', window.location.href + '&ajax&' + endpoint, true);
    request.onload = callback;
    request.send();

    return request;
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

/**
 * Sidebar toggle
 */
document.getElementById('togglebtn').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('hidden');
    document.getElementById('maincontent').classList.toggle('ml-64');
});

/**
 * Ajax panels
 */
document.querySelectorAll('[data-panel]').forEach(panel => {
    ajax('panel=' + panel.dataset.panel, request => {
        let json = JSON.parse(request.currentTarget.response);
        let result = '';

        Object.entries(json).forEach(entry => {
            const [name, value] = entry;

            if (name === 'error') {
                result += `<tr><td colspan="2" class="text-center p-4">${value}</td></tr>`;
            } else {
                result += `<tr class="[&:last-child>*]:border-b-0">
                               <td class="border-b border-gray-100 px-4 py-1 text-sm font-semibold whitespace-nowrap">${name}</td>
                               <td class="border-b border-gray-100 px-4 py-1 text-sm">${value}</td>
                           </tr>`;

                panel.querySelector('.panel-selection').style.display = 'block';
                panel.querySelector('.panel-moreinfo').style.display = 'block';
            }
        });

        panel.querySelector('.panel-content').innerHTML = result;
    });
});

let toggle_panels = document.getElementById('toggle-panels');
if (toggle_panels) {
    let current_dashboard = document.querySelector('[data-dashboard]');
    let panels_state = 'panels_state_' + current_dashboard.dataset.dashboard;

    if (!(panels_state in localStorage)) {
        localStorage.setItem(panels_state, 'open');
    }

    if (localStorage.getItem(panels_state) === 'close') {
        toggle_panels.checked = true;
        document.getElementById('infopanels').style.display = 'none';
    }

    toggle_panels.addEventListener('click', () => {
        if (localStorage.getItem(panels_state) === 'open') {
            toggle_panels.checked = true;
            document.getElementById('infopanels').style.display = 'none';
            localStorage.setItem(panels_state, 'close');
        } else {
            toggle_panels.checked = false;
            document.getElementById('infopanels').style.display = 'block';
            localStorage.setItem(panels_state, 'open');
        }
    });
}

/**
 * DB Select
 */
let db_select = document.getElementById('db_select');
if (db_select) {
    db_select.addEventListener('change', e => {
        replace_query_param('db', e.target.value);
    });
}

/**
 * Keys
 */
const keys = document.querySelectorAll('[data-key]');
keys.forEach(key => {
    key.querySelector('.delete-key').addEventListener('click', () => {
        if (!window.confirm('Are you sure you want to remove this item?')) {
            return;
        }

        ajax('delete=' + key.dataset.key, function (request) {
            if (this.status >= 200 && this.status < 400) {
                document.getElementById('alerts').innerHTML = request.currentTarget.response;
                key.remove();
            }
        });
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

        ajax('delete=' + selected_keys.join(','), function (request) {
            if (this.status >= 200 && this.status < 400) {
                document.getElementById('alerts').innerHTML = request.currentTarget.response;
            }
        });
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
 * Per page select
 */
let per_page = document.getElementById('per_page');
if (per_page) {
    per_page.addEventListener('change', e => {
        replace_query_param('pp', e.target.value);
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
        document.getElementById('redis_hash_key').style.display = e.target.value === 'hash' ? 'block' : 'none';
    });
}

/**
 * OPCache table
 */
const scripts = document.querySelectorAll('[data-key]');
scripts.forEach(script => {
    let show_path = script.querySelector('.show-path');

    if (show_path) {
        show_path.addEventListener('click', () => {
            document.querySelector('[data-path="' + script.dataset.key + '"]').classList.toggle('hidden');
        });
    }
});

/**
 * Import form
 */
let import_btn = document.getElementById('import_btn');
if (import_btn) {
    import_btn.addEventListener('click', () => {
        document.getElementById('import_form').classList.toggle('hidden');
    });
}
