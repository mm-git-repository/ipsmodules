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

    function render() {
        var online = !!state.online;
        var count = state.valveCount || 1;
        var html = '';
        html += '<div class="gs-head">';
        html += '<div class="gs-title">' + esc(state.name || 'Ventil') + '</div>';
        html += '<div class="gs-meta"><span class="gs-dot ' + (online ? 'on' : 'off') + '"></span>' +
            (online ? 'online' : 'offline') +
            ' · Batterie ' + esc(state.battery) + '%' +
            (state.temperature != null ? ' · ' + esc(state.temperature) + ' °C' : '') +
            '</div></div>';

        html += '<div class="gs-grid">';
        html += valveCard('A', state.valveA || {}, 0);
        if (count > 1) {
            html += valveCard('B', state.valveB || {}, 1);
        }
        html += '</div>';

        html += '<div class="gs-section"><h4>IPS-Zeitpläne</h4><pre class="gs-sched">' +
            esc((state.ipsSchedules || []).join('\n') || '(keine)') + '</pre></div>';
        html += '<div class="gs-section"><h4>Geräte-App (read-only)</h4><pre class="gs-sched">' +
            esc(state.deviceSchedules || '(keine)') + '</pre></div>';
        html += '<div class="gs-msg"></div>';
        root.innerHTML = html;

        root.querySelectorAll('[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                var valve = parseInt(btn.getAttribute('data-valve') || '0', 10);
                var duration = parseInt(state.defaultDuration || 1800, 10);
                var ident = act === 'start'
                    ? (valve === 0 ? 'StartValveA' : 'StartValveB')
                    : (valve === 0 ? 'StopValveA' : 'StopValveB');
                var value = act === 'start' ? duration : true;
                setMsg('Sende…', true);
                request(ident, value).then(function () {
                    setMsg('OK', true);
                    if (act === 'start') {
                        if (valve === 0) state.valveA = Object.assign({}, state.valveA, { open: true });
                        else state.valveB = Object.assign({}, state.valveB, { open: true });
                    } else {
                        if (valve === 0) state.valveA = Object.assign({}, state.valveA, { open: false });
                        else state.valveB = Object.assign({}, state.valveB, { open: false });
                    }
                    render();
                }).catch(function (e) {
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        });
    }

    function valveCard(label, valve, id) {
        var open = !!valve.open;
        return '<div class="gs-valve">' +
            '<h3>' + esc(valve.name || ('Ventil ' + label)) +
            ' <span class="gs-dot ' + (open ? 'on' : 'off') + '"></span>' +
            (open ? 'offen' : 'zu') + '</h3>' +
            '<div class="gs-actions">' +
            '<button type="button" class="gs-btn primary" data-act="start" data-valve="' + id + '">Start</button>' +
            '<button type="button" class="gs-btn danger" data-act="stop" data-valve="' + id + '">Stop</button>' +
            '</div></div>';
    }

    render();
})();
