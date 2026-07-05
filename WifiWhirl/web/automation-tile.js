(function () {
    'use strict';

    var state = {
        enabled: false,
        status: '',
        pump: [],
        heater: [],
        message: '',
        messageOk: true,
    };

    var weekdayOptions = [
        { v: 0, label: 'Mo–Fr' },
        { v: 1, label: 'Mo–So' },
        { v: 2, label: 'Sa–So' },
        { v: 3, label: 'Mo–Sa' },
    ];

    function t(key) {
        if (typeof translate === 'function') {
            return translate(key);
        }
        return key;
    }

    function defaultPumpRow() {
        return { active: true, weekdays: 0, start: '08:00', end: '20:00' };
    }

    function defaultHeaterRow() {
        return { active: true, weekdays: 0, start: '08:00', end: '20:00', targetTemp: 38, pvGated: false };
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function weekdaySelect(name, value) {
        var html = '<select data-field="' + name + '">';
        weekdayOptions.forEach(function (opt) {
            html += '<option value="' + opt.v + '"' + (Number(value) === opt.v ? ' selected' : '') + '>' + esc(t(opt.label)) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function renderRuleRows(type, rows) {
        var isHeater = type === 'heater';
        var html = '';
        rows.forEach(function (row, idx) {
            html += '<tr data-type="' + type + '" data-idx="' + idx + '">';
            html += '<td><input type="checkbox" data-field="active"' + (row.active ? ' checked' : '') + '></td>';
            html += '<td>' + weekdaySelect('weekdays', row.weekdays) + '</td>';
            html += '<td><input type="text" data-field="start" value="' + esc(row.start || '08:00') + '" placeholder="08:00"></td>';
            html += '<td><input type="text" data-field="end" value="' + esc(row.end || '20:00') + '" placeholder="20:00"></td>';
            if (isHeater) {
                html += '<td><input type="number" data-field="targetTemp" min="20" max="40" value="' + esc(row.targetTemp || 38) + '"></td>';
                html += '<td><input type="checkbox" data-field="pvGated"' + (row.pvGated ? ' checked' : '') + '></td>';
            }
            html += '<td><button type="button" class="wwhl-auto-btn wwhl-auto-btn-danger" data-action="delete">' + esc(t('Zeile löschen')) + '</button></td>';
            html += '</tr>';
        });
        return html;
    }

    function readRowsFromDom(type) {
        var rows = [];
        document.querySelectorAll('tr[data-type="' + type + '"]').forEach(function (tr) {
            var row = { active: false, weekdays: 0, start: '08:00', end: '20:00' };
            if (type === 'heater') {
                row.targetTemp = 38;
                row.pvGated = false;
            }
            tr.querySelectorAll('[data-field]').forEach(function (el) {
                var field = el.getAttribute('data-field');
                if (el.type === 'checkbox') {
                    row[field] = el.checked;
                } else if (el.type === 'number') {
                    row[field] = parseInt(el.value, 10) || 38;
                } else if (field === 'weekdays') {
                    row[field] = parseInt(el.value, 10) || 0;
                } else {
                    row[field] = el.value.trim();
                }
            });
            rows.push(row);
        });
        return rows;
    }

    function render() {
        var root = document.getElementById('wwhl-auto-root');
        if (!root) {
            return;
        }

        var pumpHead = ''
            + '<th>' + esc(t('Aktiv')) + '</th>'
            + '<th>' + esc(t('Wochentage')) + '</th>'
            + '<th>' + esc(t('Start')) + '</th>'
            + '<th>' + esc(t('Ende')) + '</th>'
            + '<th></th>';

        var heaterHead = pumpHead.replace('</th><th></th>', '</th><th>' + esc(t('Ziel °C')) + '</th><th>' + esc(t('PV-Freigabe')) + '</th><th></th>');

        root.innerHTML = ''
            + '<div class="wwhl-auto-header">'
            + '  <label class="wwhl-auto-enabled"><input type="checkbox" id="wwhl-enabled"' + (state.enabled ? ' checked' : '') + '> ' + esc(t('Automatisierung aktiv')) + '</label>'
            + '  <div class="wwhl-auto-status" id="wwhl-status">' + esc(state.status || '') + '</div>'
            + '</div>'
            + '<div class="wwhl-auto-section">'
            + '  <h3>' + esc(t('Pumpen-Zeitpläne')) + '</h3>'
            + '  <div class="wwhl-auto-table-wrap"><table><thead><tr>' + pumpHead + '</tr></thead>'
            + '  <tbody id="wwhl-pump-body">' + renderRuleRows('pump', state.pump) + '</tbody></table></div>'
            + '  <div class="wwhl-auto-actions"><button type="button" class="wwhl-auto-btn wwhl-auto-btn-secondary" data-add="pump">' + esc(t('Pumpe hinzufügen')) + '</button></div>'
            + '</div>'
            + '<div class="wwhl-auto-section">'
            + '  <h3>' + esc(t('Heizungs-Zeitpläne')) + '</h3>'
            + '  <div class="wwhl-auto-table-wrap"><table><thead><tr>' + heaterHead + '</tr></thead>'
            + '  <tbody id="wwhl-heater-body">' + renderRuleRows('heater', state.heater) + '</tbody></table></div>'
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
                var tr = btn.closest('tr');
                if (!tr) {
                    return;
                }
                var type = tr.getAttribute('data-type');
                var idx = parseInt(tr.getAttribute('data-idx'), 10);
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

        var reloadBtn = document.getElementById('wwhl-reload');
        if (reloadBtn) {
            reloadBtn.addEventListener('click', function () {
                sendCommand({ cmd: 'load' });
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
            state.pump = data.pumpRules;
        }
        if (Array.isArray(data.heaterRules)) {
            state.heater = data.heaterRules;
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
        applyPayload(data);
    };

    document.addEventListener('DOMContentLoaded', function () {
        render();
        sendCommand({ cmd: 'load' });
    });
})();
