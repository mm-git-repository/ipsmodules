(function () {
    'use strict';

    var state = window.__GS_INITIAL__ || {};
    var root = document.getElementById('gs-tile-root');
    if (!root) return;

    var DAY_KEYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'so'];
    var DAY_LABELS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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

    function lines() {
        if (Array.isArray(state.scheduleLines) && state.scheduleLines.length) {
            return state.scheduleLines.map(String);
        }
        if (typeof state.scheduleText === 'string' && state.scheduleText.trim() !== '' && state.scheduleText !== '(keine)') {
            return state.scheduleText.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
        }
        var rules = Array.isArray(state.scheduleRules) ? state.scheduleRules : [];
        if (!rules.length) return [];
        return rules.map(formatRuleLine);
    }

    function render() {
        var list = lines();
        var html = '<div class="gs-head"><div class="gs-title">' +
            esc((state.name || 'Ventil') + ' — Zeitplan') + '</div></div>';
        html += '<pre class="gs-sched">' + esc(list.length ? list.join('\n') : '(keine)') + '</pre>';
        html += '<div class="gs-hint">Bearbeitung nur in der Gardena-App</div>';
        root.innerHTML = html;
    }

    render();
})();
