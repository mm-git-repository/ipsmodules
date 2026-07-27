(function () {
    'use strict';
    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
        var on = !!state.outputOn;
        var html = '';
        html += '<div class="gs-head"><div class="gs-title">' + esc(state.name || 'Power') + '</div>';
        html += '<div class="gs-meta"><span class="gs-dot ' + (online ? 'on' : 'off') + '"></span>' +
            (online ? 'online' : 'offline') + ' · Ausgang ' + (on ? 'EIN' : 'AUS') + '</div></div>';
        html += '<div class="gs-actions">';
        html += '<button type="button" class="gs-btn primary" data-on="1">Einschalten</button>';
        html += '<button type="button" class="gs-btn danger" data-on="0">Ausschalten</button>';
        html += '</div>';
        html += '<div class="gs-section"><h4>Geräte-Zeitpläne (read-only)</h4>';
        if (state.scheduleHint) {
            html += '<p class="gs-hint">' + esc(state.scheduleHint) + '</p>';
        }
        html += '<pre class="gs-sched">' + esc(state.deviceSchedules || '(keine)') + '</pre></div>';
        html += '<div class="gs-msg"></div>';
        root.innerHTML = html;
        root.querySelectorAll('[data-on]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var want = btn.getAttribute('data-on') === '1';
                setMsg('Sende…', true);
                request('SetPower', want).then(function () {
                    state.outputOn = want;
                    setMsg('OK', true);
                    render();
                }).catch(function (e) {
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        });
    }
    render();
})();
