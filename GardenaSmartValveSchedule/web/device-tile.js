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

    function parseMaybeJson(res) {
        if (res && typeof res === 'object') return res;
        if (typeof res !== 'string') return null;
        var s = res.trim();
        // IPS sometimes wraps echo output or doubles JSON
        try { return JSON.parse(s); } catch (e1) {}
        var start = s.indexOf('{');
        var end = s.lastIndexOf('}');
        if (start >= 0 && end > start) {
            try { return JSON.parse(s.slice(start, end + 1)); } catch (e2) {}
        }
        return null;
    }

    function defaultRule() {
        return {
            active: true,
            valve: 0,
            start: state.defaultScheduleStart || '06:00',
            end: state.defaultScheduleEnd || '06:30',
            // No days preselected — forces explicit choice (avoids accidental Mo–Fr / daily writes)
            mo: false, tu: false, we: false, th: false, fr: false,
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
            copy.valve = parseInt(r.valve != null ? r.valve : 0, 10) || 0;
            copy.start = String(r.start || copy.start);
            copy.end = String(r.end || copy.end);
            return copy;
        });
    }

    function syncDomToState() {
        state.scheduleRules = readRulesFromDom();
    }

    function readRulesFromDom() {
        var rules = [];
        root.querySelectorAll('[data-rule-row]').forEach(function (row) {
            var activeEl = row.querySelector('[data-field="active"]');
            var valveEl = row.querySelector('[data-field="valve"]');
            var startEl = row.querySelector('[data-field="start"]');
            var endEl = row.querySelector('[data-field="end"]');
            var rule = {
                active: !!(activeEl && activeEl.classList.contains('on')),
                valve: parseInt((valveEl && valveEl.value) || '0', 10),
                start: ((startEl && startEl.value) || '').trim(),
                end: ((endEl && endEl.value) || '').trim()
            };
            DAY_KEYS.forEach(function (k) {
                var btn = row.querySelector('[data-day="' + k + '"]');
                rule[k] = !!(btn && btn.classList.contains('on'));
            });
            rules.push(rule);
        });
        return rules;
    }

    function isValidHm(value) {
        return /^([01]?\d|2[0-3]):[0-5]\d$/.test(String(value || '').trim());
    }

    function hmToMin(value) {
        var m = String(value || '').trim().match(/^(\d{1,2}):(\d{2})$/);
        if (!m) return -1;
        return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
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
            if (hmToMin(rule.end) <= hmToMin(rule.start)) {
                return 'Ende muss nach Start liegen';
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

    function toggleBtn(on, attrs, label) {
        return '<button type="button" class="gs-tog' + (on ? ' on' : '') + '" ' + attrs + '>' +
            esc(label) + '</button>';
    }

    function renderEditor() {
        var rules = cloneRules(state.scheduleRules || []);
        var html = '';
        html += '<div class="gs-head"><div class="gs-title">' +
            esc((state.name || 'Ventil') + ' — Zeitplan') + '</div></div>';
        if (scheduleMetaText()) {
            html += '<p class="gs-hint">' + esc(scheduleMetaText()) + '</p>';
        }

        if (!rules.length) {
            html += '<p class="gs-hint">Keine Einträge</p>';
        }

        rules.forEach(function (rule, idx) {
            html += '<div class="gs-rule" data-rule-row="' + idx + '">';
            html += '<div class="gs-rule-top">';
            html += toggleBtn(!!rule.active, 'data-field="active"', 'Aktiv');
            html += '<select data-field="valve">' +
                valveOptions(parseInt(rule.valve != null ? rule.valve : 0, 10)) +
                '</select>';
            html += '<input type="text" inputmode="numeric" data-field="start" value="' +
                esc(rule.start || '06:00') + '" placeholder="Start">';
            html += '<input type="text" inputmode="numeric" data-field="end" value="' +
                esc(rule.end || '06:30') + '" placeholder="Ende">';
            html += '<button type="button" class="gs-btn danger gs-btn-sm" data-remove-row="' +
                idx + '">Entfernen</button>';
            html += '</div>';
            html += '<div class="gs-days">';
            DAY_KEYS.forEach(function (k, i) {
                html += toggleBtn(!!rule[k], 'data-day="' + k + '"', DAY_LABELS[i]);
            });
            html += '</div></div>';
        });

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
        root.querySelectorAll('.gs-tog').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.classList.toggle('on');
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

        var pullBtn = root.querySelector('[data-pull-sched]');
        if (pullBtn) {
            pullBtn.addEventListener('click', function () {
                setMsg('Lade Zeitplan vom Gerät…', true);
                request('PullDeviceSchedules', true).then(function (res) {
                    var parsed = parseMaybeJson(res);
                    if (!parsed) {
                        setMsg(typeof res === 'string' && res ? res : 'Fehler: Ungültige Antwort', false);
                        return;
                    }
                    if (parsed.ok === false) {
                        setMsg(String(parsed.message || 'Fehler'), false);
                        return;
                    }
                    if (Array.isArray(parsed.rules)) {
                        state.scheduleRules = cloneRules(parsed.rules);
                    }
                    state.scheduleLastSavedBy = 'Gerät';
                    state.scheduleLastSavedAt = new Date().toISOString();
                    setMsg(parsed.message ? String(parsed.message) : 'OK', true);
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
                    var parsed = parseMaybeJson(res);
                    var msg = parsed && parsed.message ? parsed.message
                        : (typeof res === 'string' ? res : 'OK');
                    setMsg(msg, !(parsed && parsed.ok === false) && String(msg).indexOf('Fehler') !== 0);
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
