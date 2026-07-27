(function () {
    'use strict';

    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function request(ident, value) {
        if (typeof window.requestAction === 'function') {
            return window.requestAction(ident, value);
        }
        return Promise.reject(new Error('requestAction nicht verfügbar'));
    }

    function setMsg(text, ok) {
        var el = root.querySelector('.gs-msg');
        if (!el) return;
        el.textContent = text || '';
        el.className = 'gs-msg ' + (ok ? 'ok' : 'err');
    }

    function valves() {
        if (Array.isArray(state.valves) && state.valves.length) {
            return state.valves;
        }
        var list = [];
        var count = Math.max(1, parseInt(state.valveCount || 1, 10) || 1);
        if (state.valveA) {
            list.push({
                id: 0,
                side: 'A',
                name: (state.valveA.name || 'Ventil A'),
                open: !!state.valveA.open
            });
        }
        if (count > 1 && state.valveB) {
            list.push({
                id: 1,
                side: 'B',
                name: (state.valveB.name || 'Ventil B'),
                open: !!state.valveB.open
            });
        }
        if (!list.length) {
            list.push({ id: 0, side: 'A', name: 'Ventil A', open: false });
        }
        return list;
    }

    function valveRow(v) {
        var open = !!v.open;
        var title = v.name || ('Ventil ' + (v.side || (v.id + 1)));
        var act = open ? 'stop' : 'start';
        var label = open ? 'Stop' : 'Start';
        var btnClass = open ? 'gs-btn danger' : 'gs-btn primary';
        return '<div class="gs-valve-row">' +
            '<div class="gs-valve-info">' +
            '<span class="gs-valve-name">' + esc(title) + '</span>' +
            '<span class="gs-valve-state">' +
            '<span class="gs-dot ' + (open ? 'on' : 'off') + '"></span>' +
            (open ? 'aktiv' : 'inaktiv') +
            '</span></div>' +
            '<button type="button" class="' + btnClass + '" data-act="' + act +
            '" data-valve="' + esc(v.id) + '">' + label + '</button>' +
            '</div>';
    }

    function render() {
        var list = valves();
        var html = '';
        html += '<div class="gs-head"><div class="gs-title">' +
            esc(state.name || 'Ventil') + '</div></div>';
        html += '<div class="gs-list">';
        list.forEach(function (v) {
            html += valveRow(v);
        });
        html += '</div>';
        html += '<div class="gs-msg"></div>';
        root.innerHTML = html;

        root.querySelectorAll('[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                var valve = parseInt(btn.getAttribute('data-valve') || '0', 10);
                var ident = act === 'start' ? 'StartValve' : 'StopValve';
                var value = act === 'start'
                    ? JSON.stringify({ valveId: valve, duration: parseInt(state.defaultDuration || 1800, 10) })
                    : JSON.stringify({ valveId: valve });
                setMsg('Sende…', true);
                request(ident, value).then(function () {
                    setMsg('OK', true);
                    list.forEach(function (v) {
                        if (v.id === valve) {
                            v.open = act === 'start';
                        }
                    });
                    state.valves = list;
                    render();
                }).catch(function (e) {
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        });
    }

    render();
})();
