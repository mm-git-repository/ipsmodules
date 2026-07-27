(function () {
    'use strict';

    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;
    var pending = false;

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

    function parseMaybeJson(res) {
        if (res && typeof res === 'object') return res;
        if (typeof res !== 'string') return null;
        var s = res.trim();
        try { return JSON.parse(s); } catch (e1) {}
        var start = s.indexOf('{');
        var end = s.lastIndexOf('}');
        if (start >= 0 && end > start) {
            try { return JSON.parse(s.slice(start, end + 1)); } catch (e2) {}
        }
        return null;
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

    function setValveOpen(valveId, open) {
        var list = valves();
        list.forEach(function (v) {
            if (v.id === valveId) v.open = !!open;
        });
        state.valves = list;
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
            '" data-valve="' + esc(v.id) + '"' + (pending ? ' disabled' : '') + '>' +
            label + '</button>' +
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
                if (pending) return;
                var act = btn.getAttribute('data-act');
                var valve = parseInt(btn.getAttribute('data-valve') || '0', 10);
                var wantOpen = act === 'start';
                var prevOpen = false;
                valves().forEach(function (v) {
                    if (v.id === valve) prevOpen = !!v.open;
                });

                // Optimistic UI update immediately
                pending = true;
                setValveOpen(valve, wantOpen);
                render();
                setMsg(wantOpen ? 'Starte…' : 'Stoppe…', true);

                var ident = wantOpen ? 'StartValve' : 'StopValve';
                var value = wantOpen
                    ? JSON.stringify({ valveId: valve, duration: parseInt(state.defaultDuration || 1800, 10) })
                    : JSON.stringify({ valveId: valve });
                request(ident, value).then(function (res) {
                    pending = false;
                    var parsed = parseMaybeJson(res);
                    if (parsed && parsed.ok === false) {
                        setValveOpen(valve, prevOpen);
                        render();
                        setMsg(String(parsed.message || 'Fehler'), false);
                        return;
                    }
                    if (parsed && typeof parsed.open === 'boolean') {
                        setValveOpen(valve, parsed.open);
                    } else {
                        setValveOpen(valve, wantOpen);
                    }
                    render();
                    setMsg((parsed && parsed.message) ? String(parsed.message) : 'OK', true);
                }).catch(function (e) {
                    pending = false;
                    setValveOpen(valve, prevOpen);
                    render();
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        });
    }

    render();
})();
