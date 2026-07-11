(function () {
    'use strict';

    var state = {
        enabled: false,
        status: '',
        pump: [],
        heater: [],
        manualPause: false,
        pvGateOpen: false,
        pvSurplus: 0,
        message: '',
        messageOk: true,
    };

    var dayFields = [
        { key: 'mo', label: 'Mo' },
        { key: 'tu', label: 'Di' },
        { key: 'we', label: 'Mi' },
        { key: 'th', label: 'Do' },
        { key: 'fr', label: 'Fr' },
        { key: 'sa', label: 'Sa' },
        { key: 'so', label: 'So' },
    ];

    var tempMin = 7;
    var tempMax = 40;
    var timeStepMinutes = 15;
    var timeOptions = null;

    function pad2(n) {
        return (n < 10 ? '0' : '') + String(n);
    }

    function buildTimeOptions() {
        if (timeOptions) {
            return timeOptions;
        }
        timeOptions = [];
        for (var hour = 0; hour < 24; hour += 1) {
            for (var minute = 0; minute < 60; minute += timeStepMinutes) {
                timeOptions.push(pad2(hour) + ':' + pad2(minute));
            }
        }
        return timeOptions;
    }

    function parseTimeValue(value) {
        var match = String(value || '').trim().match(/^(\d{1,2}):(\d{2})$/);
        if (!match) {
            return null;
        }
        var hour = parseInt(match[1], 10);
        var minute = parseInt(match[2], 10);
        if (hour < 0 || hour > 23 || minute < 0 || minute > 59) {
            return null;
        }
        return pad2(hour) + ':' + pad2(minute);
    }

    function normalizeTimeValue(value, fallback) {
        var parsed = parseTimeValue(value);
        return parsed || fallback;
    }

    function timeSelect(field, value) {
        var selected = normalizeTimeValue(value, field === 'end' ? '20:00' : '08:00');
        var options = buildTimeOptions();
        var inList = options.indexOf(selected) >= 0;
        var html = '<select data-field="' + field + '" class="wwhl-auto-select-time">';
        if (!inList) {
            html += '<option value="' + esc(selected) + '" selected>' + esc(selected) + '</option>';
        }
        options.forEach(function (opt) {
            html += '<option value="' + opt + '"' + (opt === selected ? ' selected' : '') + '>' + opt + '</option>';
        });
        html += '</select>';
        return html;
    }

    function t(key) {
        if (typeof translate === 'function') {
            return translate(key);
        }
        return key;
    }

    function defaultDays() {
        return { mo: true, tu: true, we: true, th: true, fr: true, sa: false, so: false };
    }

    function defaultPumpRow() {
        return Object.assign({ active: true, start: '08:00', end: '20:00' }, defaultDays());
    }

    function defaultHeaterRow() {
        return Object.assign(
            { active: true, start: '08:00', end: '20:00', targetTemp: 38, pvGated: false },
            defaultDays(),
        );
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function clampTemp(value) {
        var n = parseInt(value, 10);
        if (isNaN(n)) {
            return 38;
        }
        return Math.max(tempMin, Math.min(tempMax, n));
    }

    function normalizeRow(row, isHeater) {
        var out = {
            active: !!row.active,
            start: normalizeTimeValue(row.start, '08:00'),
            end: normalizeTimeValue(row.end, '20:00'),
            mo: !!row.mo,
            tu: !!row.tu,
            we: !!row.we,
            th: !!row.th,
            fr: !!row.fr,
            sa: !!row.sa,
            so: !!row.so,
        };
        if (isHeater) {
            out.targetTemp = clampTemp(row.targetTemp);
            out.pvGated = !!row.pvGated;
        }
        return out;
    }

    function targetTempSelect(value) {
        var selected = clampTemp(value);
        var html = '<select data-field="targetTemp" class="wwhl-auto-select-temp">';
        for (var temp = tempMin; temp <= tempMax; temp += 1) {
            html += '<option value="' + temp + '"' + (selected === temp ? ' selected' : '') + '>' + temp + ' °C</option>';
        }
        html += '</select>';
        return html;
    }

    function renderDayRow(row) {
        var html = '<div class="wwhl-auto-row2">';
        dayFields.forEach(function (day) {
            var checked = row[day.key] ? ' checked' : '';
            html += '<label class="wwhl-auto-day">'
                + '<input type="checkbox" data-field="' + day.key + '"' + checked + '>'
                + '<span>' + esc(t(day.label)) + '</span>'
                + '</label>';
        });
        html += '</div>';
        return html;
    }

    function renderInlineField(label, innerHtml, extraClass) {
        return '<label class="wwhl-auto-inline' + (extraClass ? ' ' + extraClass : '') + '">'
            + '<span class="wwhl-auto-inline-label">' + esc(t(label)) + '</span>'
            + innerHtml
            + '</label>';
    }

    function renderRuleCards(type, rows) {
        var isHeater = type === 'heater';
        var html = '<div class="wwhl-auto-rules" data-rules="' + type + '">';
        rows.forEach(function (row, idx) {
            row = normalizeRow(row, isHeater);
            html += '<article class="wwhl-auto-rule" data-type="' + type + '" data-idx="' + idx + '">';
            html += '<label class="wwhl-auto-active-toggle" title="' + esc(t('Aktiv')) + '">'
                + '<input type="checkbox" data-field="active"' + (row.active ? ' checked' : '') + '>'
                + '<span class="wwhl-auto-active-label">' + esc(t('Aktiv')) + '</span>'
                + '</label>';
            html += '<div class="wwhl-auto-rule-body">';
            html += '<div class="wwhl-auto-row1">';
            html += renderInlineField(
                'Start',
                timeSelect('start', row.start),
                'wwhl-auto-inline-time',
            );
            html += renderInlineField(
                'Ende',
                timeSelect('end', row.end),
                'wwhl-auto-inline-time',
            );
            if (isHeater) {
                html += renderInlineField('Ziel °C', targetTempSelect(row.targetTemp), 'wwhl-auto-inline-temp');
                html += renderInlineField(
                    'PV-Freigabe',
                    '<input type="checkbox" data-field="pvGated"' + (row.pvGated ? ' checked' : '') + '>',
                    'wwhl-auto-inline-check',
                );
            }
            html += '</div>';
            html += renderDayRow(row);
            html += '</div>';
            html += '<button type="button" class="wwhl-auto-delete" data-action="delete" title="' + esc(t('Zeile löschen')) + '" aria-label="' + esc(t('Zeile löschen')) + '">×</button>';
            html += '</article>';
        });
        html += '</div>';
        return html;
    }

    function readRowsFromDom(type) {
        var root = document.getElementById('wwhl-auto-root');
        var rows = [];
        if (!root) {
            return rows;
        }
        root.querySelectorAll('.wwhl-auto-rule[data-type="' + type + '"]').forEach(function (card) {
            var row = normalizeRow({}, type === 'heater');
            card.querySelectorAll('[data-field]').forEach(function (el) {
                var field = el.getAttribute('data-field');
                if (el.type === 'checkbox') {
                    row[field] = el.checked;
                } else if (field === 'targetTemp') {
                    row[field] = clampTemp(el.value);
                } else if (field === 'start' || field === 'end') {
                    row[field] = normalizeTimeValue(el.value, field === 'end' ? '20:00' : '08:00');
                } else {
                    row[field] = el.value.trim();
                }
            });
            rows.push(row);
        });
        return rows;
    }

    function formatPvSurplus(watts) {
        var n = parseFloat(watts);
        if (isNaN(n)) {
            return '0';
        }
        return String(Math.round(n));
    }

    function renderPvGateBadge() {
        var open = !!state.pvGateOpen;
        var label = open ? t('Offen') : t('Geschlossen');
        var surplus = formatPvSurplus(state.pvSurplus);
        return '<div class="wwhl-auto-pv-gate' + (open ? ' open' : ' closed') + '" title="' + esc(t('Automatisierung PV-Überschuss')) + '">'
            + '<span class="wwhl-auto-pv-gate-label">' + esc(t('PV-Freigabe')) + '</span>'
            + '<span class="wwhl-auto-pv-gate-state">' + esc(label) + '</span>'
            + '<span class="wwhl-auto-pv-gate-watts">' + esc(surplus) + ' W</span>'
            + '</div>';
    }

    function render() {
        var root = document.getElementById('wwhl-auto-root');
        if (!root) {
            return;
        }

        root.innerHTML = ''
            + '<div class="wwhl-auto-header">'
            + '  <label class="wwhl-auto-enabled"><input type="checkbox" id="wwhl-enabled"' + (state.enabled ? ' checked' : '') + '> ' + esc(t('Automatisierung aktiv')) + '</label>'
            + '</div>'
            + '<div class="wwhl-auto-zeitfenster">'
            + '  <div class="wwhl-auto-status" id="wwhl-status">' + esc(state.status || '') + '</div>'
            + '  <button type="button" class="wwhl-auto-btn wwhl-auto-btn-secondary wwhl-auto-clear-pause" id="wwhl-clear-pause"' + (state.manualPause ? '' : ' disabled') + '>' + esc(t('Manuelle Pause aufheben')) + '</button>'
            + '  ' + renderPvGateBadge()
            + '</div>'
            + '<div class="wwhl-auto-section">'
            + '  <h3>' + esc(t('Pumpen-Zeitpläne')) + '</h3>'
            + renderRuleCards('pump', state.pump)
            + '  <div class="wwhl-auto-actions"><button type="button" class="wwhl-auto-btn wwhl-auto-btn-secondary" data-add="pump">' + esc(t('Pumpe hinzufügen')) + '</button></div>'
            + '</div>'
            + '<div class="wwhl-auto-section">'
            + '  <h3>' + esc(t('Heizungs-Zeitpläne')) + '</h3>'
            + renderRuleCards('heater', state.heater)
            + '  <div class="wwhl-auto-actions"><button type="button" class="wwhl-auto-btn wwhl-auto-btn-secondary" data-add="heater">' + esc(t('Heizung hinzufügen')) + '</button></div>'
            + '</div>'
            + '<div class="wwhl-auto-actions">'
            + '  <button type="button" class="wwhl-auto-btn wwhl-auto-btn-primary" id="wwhl-save">' + esc(t('Speichern')) + '</button>'
            + '  <button type="button" class="wwhl-auto-btn wwhl-auto-btn-secondary" id="wwhl-reload">' + esc(t('Neu laden')) + '</button>'
            + '</div>'
            + '<div class="wwhl-auto-msg' + (state.messageOk ? ' ok' : ' err') + '" id="wwhl-msg">' + esc(state.message || '') + '</div>';

        bindEvents();
    }

    function bindEvents() {
        var root = document.getElementById('wwhl-auto-root');
        if (!root) {
            return;
        }

        root.querySelectorAll('[data-add]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var type = btn.getAttribute('data-add');
                if (type === 'pump') {
                    state.pump.push(defaultPumpRow());
                } else {
                    state.heater.push(defaultHeaterRow());
                }
                render();
            });
        });

        root.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card = btn.closest('.wwhl-auto-rule');
                if (!card) {
                    return;
                }
                var type = card.getAttribute('data-type');
                var idx = parseInt(card.getAttribute('data-idx'), 10);
                if (type === 'pump') {
                    state.pump.splice(idx, 1);
                } else {
                    state.heater.splice(idx, 1);
                }
                render();
            });
        });

        var saveBtn = document.getElementById('wwhl-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                sendCommand({
                    cmd: 'save',
                    enabled: !!document.getElementById('wwhl-enabled').checked,
                    pump: readRowsFromDom('pump'),
                    heater: readRowsFromDom('heater'),
                });
            });
        }

        var enabledCb = document.getElementById('wwhl-enabled');
        if (enabledCb) {
            enabledCb.addEventListener('change', function () {
                sendCommand({ cmd: 'setenabled', enabled: !!enabledCb.checked });
            });
        }

        var reloadBtn = document.getElementById('wwhl-reload');
        if (reloadBtn) {
            reloadBtn.addEventListener('click', function () {
                sendCommand({ cmd: 'load' });
            });
        }

        var clearPauseBtn = document.getElementById('wwhl-clear-pause');
        if (clearPauseBtn) {
            clearPauseBtn.addEventListener('click', function () {
                sendCommand({ cmd: 'clearoverride' });
            });
        }
    }

    function sendCommand(payload) {
        if (typeof requestAction !== 'function') {
            state.message = t('Visualisierung nicht verfügbar');
            state.messageOk = false;
            render();
            return;
        }
        requestAction('AutomationEditor', JSON.stringify(payload));
    }

    function applyPayload(data) {
        if (!data || typeof data !== 'object') {
            return;
        }
        if (typeof data.enabled === 'boolean') {
            state.enabled = data.enabled;
        }
        if (typeof data.status === 'string') {
            state.status = data.status;
        }
        if (Array.isArray(data.pumpRules)) {
            state.pump = data.pumpRules.map(function (row) { return normalizeRow(row, false); });
        }
        if (Array.isArray(data.heaterRules)) {
            state.heater = data.heaterRules.map(function (row) { return normalizeRow(row, true); });
        }
        if (typeof data.manualPause === 'boolean') {
            state.manualPause = data.manualPause;
        }
        if (typeof data.pvGateOpen === 'boolean') {
            state.pvGateOpen = data.pvGateOpen;
        }
        if (typeof data.pvSurplus === 'number') {
            state.pvSurplus = data.pvSurplus;
        }
        if (typeof data.message === 'string') {
            state.message = data.message ? t(data.message) : '';
        }
        if (typeof data.messageOk === 'boolean') {
            state.messageOk = data.messageOk;
        }
        render();
    }

    window.handleMessage = function (data) {
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch (e) {
                return;
            }
        }
        applyPayload(data);
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (window.__WWHL_INITIAL__ && typeof window.__WWHL_INITIAL__ === 'object') {
            applyPayload(window.__WWHL_INITIAL__);
            return;
        }

        render();
        sendCommand({ cmd: 'load' });
    });
})();
