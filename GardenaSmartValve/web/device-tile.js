(function () {
    'use strict';

    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    var DAY_KEYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'so'];
    var DAY_LABELS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    var MAX_SLOTS = parseInt(state.scheduleMaxSlots || 4, 10) || 4;

    if (!Array.isArray(state.scheduleRules)) {
        state.scheduleRules = [];
    }

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

    function defaultRule() {
        return {
            active: true,
            valve: 0,
            start: '06:00',
            end: '06:30',
            mo: true, tu: true, we: true, th: true, fr: true,
            sa: false, so: false
        };
    }

    function cloneRules(rules) {
        return (rules || []).map(function (r) {
            var copy = Object.assign({}, defaultRule(), r);
            DAY_KEYS.forEach(function (k) {
                copy[k] = !!r[k];
            });
            copy.active = r.active !== false;
            return copy;
        });
    }

    function syncDomToState() {
        state.scheduleRules = readRulesFromDom();
    }

    function readRulesFromDom() {
        var rules = [];
        root.querySelectorAll('[data-rule-row]').forEach(function (row) {
            var rule = {
                active: !!(row.querySelector('[data-field="active"]') || {}).checked,
                valve: parseInt((row.querySelector('[data-field="valve"]') || {}).value || '0', 10),
                start: ((row.querySelector('[data-field="start"]') || {}).value || '').trim(),
                end: ((row.querySelector('[data-field="end"]') || {}).value || '').trim()
            };
            DAY_KEYS.forEach(function (k) {
                var cb = row.querySelector('[data-field="' + k + '"]');
                rule[k] = cb ? cb.checked : false;
            });
            rules.push(rule);
        });
        return rules;
    }

    function isValidHm(value) {
        return /^([01]?\d|2[0-3]):[0-5]\d$/.test(String(value || '').trim());
    }

    function validateRules(rules) {
        var active = 0;
        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (!rule.active) continue;
            active++;
            if (active > MAX_SLOTS) {
                return 'Maximal ' + MAX_SLOTS + ' Zeitplan-Einträge möglich';
            }
            if (!isValidHm(rule.start) || !isValidHm(rule.end)) {
                return 'Ungültige Zeitangabe (erwartet HH:MM)';
            }
            var hasDay = DAY_KEYS.some(function (k) { return !!rule[k]; });
            if (!hasDay) {
                return 'Jeder aktive Eintrag braucht mindestens einen Wochentag';
            }
        }
        return '';
    }

    function scheduleMetaText() {
        var parts = [];
        if (state.scheduleLastSavedBy) {
            parts.push('Zuletzt gespeichert von ' + state.scheduleLastSavedBy);
        }
        if (state.scheduleLastSavedAt) {
            parts.push('am ' + state.scheduleLastSavedAt);
        }
        return parts.join(' ');
    }

    function liters(v) {
        var n = Number(v) || 0;
        if (n >= 100) return Math.round(n).toLocaleString('de-DE') + ' L';
        if (n >= 10) return n.toFixed(1).replace('.', ',') + ' L';
        return n.toFixed(2).replace('.', ',') + ' L';
    }

    function renderUsage() {
        var outlets = ((state.usage || {}).outlets || []).filter(function (o) { return o.enabled; });
        if (!outlets.length) {
            return '';
        }
        var html = '<div class="gs-section"><h4>Wasserverbrauch</h4>';
        outlets.forEach(function (o) {
            html += '<div class="gs-usage-line">';
            html += '<strong>' + esc(o.label || ('Ausgang ' + o.side)) + '</strong>';
            html += ' · heute ' + esc(liters(o.today));
            html += ' · Woche ' + esc(liters(o.week));
            html += ' · Gesamt ' + esc(liters(o.total));
            if (o.open) {
                html += ' · Session ' + esc(liters(o.session));
            }
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function renderScheduleEditor() {
        if (!state.scheduleWritable) {
            return '<div class="gs-section"><h4>Geräte-Zeitpläne (read-only)</h4>' +
                '<p class="gs-hint">Gen1-Geräte: Bearbeitung nur in der Gardena-App. IPS zeigt den aktuellen Stand.</p>' +
                '<pre class="gs-sched">' + esc((state.scheduleRules || []).length
                    ? cloneRules(state.scheduleRules).map(formatRuleLine).join('\n')
                    : '(keine)') + '</pre></div>';
        }

        var rules = cloneRules(state.scheduleRules || []);
        var html = '<div class="gs-section gs-sched-editor">';
        html += '<h4>Geräte-Zeitpläne (Master am Gateway)</h4>';
        if (scheduleMetaText()) {
            html += '<p class="gs-hint">' + esc(scheduleMetaText()) + '</p>';
        }
        html += '<p class="gs-hint">Max. ' + MAX_SLOTS + ' Einträge. Hinzufügen, entfernen und ändern — dann speichern.</p>';
        html += '<div class="gs-sched-table-wrap"><table class="gs-sched-table"><thead><tr>';
        html += '<th>Aktiv</th><th>Ventil</th><th>Start</th><th>Ende</th>';
        DAY_LABELS.forEach(function (d) { html += '<th>' + d + '</th>'; });
        html += '<th></th></tr></thead><tbody>';

        if (!rules.length) {
            html += '<tr><td colspan="12" class="gs-empty">Keine Einträge — „Eintrag hinzufügen“ nutzen.</td></tr>';
        }
        rules.forEach(function (rule, idx) {
            html += '<tr data-rule-row="' + idx + '">';
            html += '<td><input type="checkbox" data-field="active"' + (rule.active ? ' checked' : '') + '></td>';
            html += '<td><input type="number" min="0" max="5" data-field="valve" value="' + esc(rule.valve != null ? rule.valve : 0) + '"></td>';
            html += '<td><input type="text" data-field="start" value="' + esc(rule.start || '06:00') + '" placeholder="HH:MM"></td>';
            html += '<td><input type="text" data-field="end" value="' + esc(rule.end || '06:30') + '" placeholder="HH:MM"></td>';
            DAY_KEYS.forEach(function (k) {
                html += '<td><input type="checkbox" data-field="' + k + '"' + (rule[k] ? ' checked' : '') + '></td>';
            });
            html += '<td><button type="button" class="gs-btn danger gs-btn-sm" data-remove-row="' + idx + '">Entfernen</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        html += '<div class="gs-actions">';
        html += '<button type="button" class="gs-btn" data-add-sched="1"' +
            (rules.length >= MAX_SLOTS ? ' disabled' : '') + '>Eintrag hinzufügen</button>';
        html += '<button type="button" class="gs-btn primary" data-save-sched="1">An Gerät speichern</button>';
        html += '</div></div>';
        return html;
    }

    function formatRuleLine(rule) {
        var days = [];
        DAY_KEYS.forEach(function (k, i) {
            if (rule[k]) days.push(DAY_LABELS[i]);
        });
        return 'V' + (rule.valve != null ? rule.valve : '?') +
            ' ' + (rule.start || '?') + '–' + (rule.end || '?') +
            ' (' + (days.length ? days.join(',') : 'keine Tage') + ')';
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

        html += renderUsage();
        html += renderScheduleEditor();
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

        var addBtn = root.querySelector('[data-add-sched]');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                syncDomToState();
                if (state.scheduleRules.length >= MAX_SLOTS) {
                    setMsg('Maximal ' + MAX_SLOTS + ' Einträge möglich', false);
                    return;
                }
                state.scheduleRules.push(defaultRule());
                render();
            });
        }

        root.querySelectorAll('[data-remove-row]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                syncDomToState();
                var idx = parseInt(btn.getAttribute('data-remove-row') || '-1', 10);
                if (idx < 0) return;
                state.scheduleRules.splice(idx, 1);
                render();
            });
        });

        var saveBtn = root.querySelector('[data-save-sched]');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var rules = readRulesFromDom();
                var err = validateRules(rules);
                if (err) {
                    setMsg(err, false);
                    return;
                }
                state.scheduleRules = rules;
                setMsg('Speichere am Gerät…', true);
                request('SaveDeviceSchedules', JSON.stringify(rules)).then(function (res) {
                    state.scheduleLastSavedBy = 'IPS';
                    state.scheduleLastSavedAt = new Date().toISOString();
                    setMsg(typeof res === 'string' ? res : 'OK', true);
                    render();
                }).catch(function (e) {
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        }
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
