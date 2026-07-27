(function () {
    'use strict';

    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    var DAY_KEYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'so'];
    var DAY_LABELS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    var MAX_SLOTS = parseInt(state.scheduleMaxSlots || 36, 10) || 36;

    if (!Array.isArray(state.scheduleRules)) {
        state.scheduleRules = [];
    }
    if (!Array.isArray(state.valveNames)) {
        state.valveNames = [];
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
            start: state.defaultScheduleStart || '06:00',
            end: state.defaultScheduleEnd || '06:30',
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

    function valveOptions(selected) {
        var html = '';
        var names = state.valveNames || [];
        if (!names.length) {
            html += '<option value="0"' + (selected === 0 ? ' selected' : '') + '>Ventil A</option>';
            html += '<option value="1"' + (selected === 1 ? ' selected' : '') + '>Ventil B</option>';
            return html;
        }
        names.forEach(function (v) {
            var id = parseInt(v.id, 10);
            var label = v.name || ('Ventil ' + (v.side || id));
            html += '<option value="' + id + '"' + (selected === id ? ' selected' : '') + '>' +
                esc(label) + '</option>';
        });
        return html;
    }

    function formatRuleLine(rule) {
        var days = [];
        DAY_KEYS.forEach(function (k, i) {
            if (rule[k]) days.push(DAY_LABELS[i]);
        });
        var valveLabel = 'V' + (rule.valve != null ? rule.valve : '?');
        (state.valveNames || []).forEach(function (v) {
            if (parseInt(v.id, 10) === parseInt(rule.valve, 10)) {
                valveLabel = v.name || valveLabel;
            }
        });
        return valveLabel + ' ' + (rule.start || '?') + '–' + (rule.end || '?') +
            ' (' + (days.length ? days.join(',') : 'keine Tage') + ')';
    }

    function renderEditor() {
        var rules = cloneRules(state.scheduleRules || []);
        var html = '';
        html += '<div class="gs-head"><div class="gs-title">' +
            esc((state.name || 'Ventil') + ' — Zeitplan') + '</div></div>';
        if (scheduleMetaText()) {
            html += '<p class="gs-hint">' + esc(scheduleMetaText()) + '</p>';
        }
        html += '<div class="gs-sched-table-wrap"><table class="gs-sched-table"><thead><tr>';
        html += '<th>Aktiv</th><th>Ventil</th><th>Start</th><th>Ende</th>';
        DAY_LABELS.forEach(function (d) { html += '<th>' + d + '</th>'; });
        html += '<th></th></tr></thead><tbody>';

        if (!rules.length) {
            html += '<tr><td colspan="12" class="gs-empty">Keine Einträge</td></tr>';
        }
        rules.forEach(function (rule, idx) {
            html += '<tr data-rule-row="' + idx + '">';
            html += '<td><input type="checkbox" data-field="active"' + (rule.active ? ' checked' : '') + '></td>';
            html += '<td><select data-field="valve">' +
                valveOptions(parseInt(rule.valve != null ? rule.valve : 0, 10)) +
                '</select></td>';
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
        html += '<button type="button" class="gs-btn" data-pull-sched="1">Vom Gerät laden</button>';
        html += '<button type="button" class="gs-btn primary" data-save-sched="1">An Gerät speichern</button>';
        html += '</div>';
        html += '<div class="gs-msg"></div>';
        return html;
    }

    function renderReadonly() {
        var html = '<div class="gs-head"><div class="gs-title">' +
            esc((state.name || 'Ventil') + ' — Zeitplan') + '</div></div>';
        html += '<pre class="gs-sched">' + esc((state.scheduleRules || []).length
            ? cloneRules(state.scheduleRules).map(formatRuleLine).join('\n')
            : '(keine)') + '</pre>';
        html += '<div class="gs-msg"></div>';
        return html;
    }

    function bind() {
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

        var pullBtn = root.querySelector('[data-pull-sched]');
        if (pullBtn) {
            pullBtn.addEventListener('click', function () {
                setMsg('Lade Zeitplan vom Gerät…', true);
                request('PullDeviceSchedules', true).then(function (res) {
                    var parsed = null;
                    if (typeof res === 'string') {
                        try { parsed = JSON.parse(res); } catch (e) { parsed = null; }
                    } else if (res && typeof res === 'object') {
                        parsed = res;
                    }
                    if (parsed && parsed.ok === false) {
                        setMsg(String(parsed.message || 'Fehler'), false);
                        return;
                    }
                    if (parsed && Array.isArray(parsed.rules)) {
                        state.scheduleRules = cloneRules(parsed.rules);
                    }
                    state.scheduleLastSavedBy = 'Gerät';
                    state.scheduleLastSavedAt = new Date().toISOString();
                    setMsg(parsed && parsed.message ? String(parsed.message) : 'OK', true);
                    render();
                }).catch(function (e) {
                    setMsg(String(e && e.message ? e.message : e), false);
                });
            });
        }

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

    function render() {
        root.innerHTML = state.scheduleWritable ? renderEditor() : renderReadonly();
        bind();
    }

    render();
})();
