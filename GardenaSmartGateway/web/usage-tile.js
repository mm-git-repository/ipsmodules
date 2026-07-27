(function () {
    'use strict';
    var data = window.__GS_USAGE__ || {};
    var root = document.getElementById('gs-usage-root');
    if (!root) return;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function liters(v) {
        var n = Number(v) || 0;
        if (n >= 100) return Math.round(n).toLocaleString('de-DE') + ' L';
        if (n >= 10) return n.toFixed(1).replace('.', ',') + ' L';
        return n.toFixed(2).replace('.', ',') + ' L';
    }

    function lph(v) {
        var n = Number(v) || 0;
        return String(n).replace('.', ',') + ' l/h';
    }

    function totalBox(label, value) {
        return '<div class="gs-usage-total"><div class="k">' + esc(label) + '</div><div class="v">' +
            esc(liters(value)) + '</div></div>';
    }

    var totals = data.totals || {};
    var devices = data.devices || [];
    var rows = [];
    devices.forEach(function (device) {
        (device.outlets || []).forEach(function (o) {
            rows.push({
                device: device.name || 'Gerät',
                outlet: o
            });
        });
    });

    var html = '';
    html += '<div class="gs-usage-head"><div class="gs-usage-title">Wasserverbrauch</div></div>';
    html += '<div class="gs-usage-totals">';
    html += totalBox('Heute', totals.today);
    html += totalBox('Woche', totals.week);
    html += totalBox('Jahr', totals.year);
    html += totalBox('Gesamt', totals.total);
    html += '</div>';

    html += '<div class="gs-usage-body">';
    if (!rows.length) {
        html += '<div class="gs-usage-empty">Keine aktiven Ausgänge.<br>' +
            'Am Valve ein Durchfluss-Preset wählen und speichern.</div>';
    } else {
        html += '<table class="gs-usage-table">';
        html += '<thead><tr>';
        html += '<th>Gerät</th>';
        html += '<th>Ausgang</th>';
        html += '<th class="st">Status</th>';
        html += '<th class="num">Durchfluss</th>';
        html += '<th class="num">Heute</th>';
        html += '<th class="num">Woche</th>';
        html += '<th class="num">Jahr</th>';
        html += '<th class="num">Gesamt</th>';
        html += '<th class="num">Session</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (row) {
            var o = row.outlet;
            var open = !!o.open;
            var metaParts = [];
            if (o.length) metaParts.push(o.length);
            if (o.pressure) metaParts.push(o.pressure);
            var label = o.label || ('Ausgang ' + (o.side || '?'));
            var valve = o.valveName || ('Ventil ' + (o.side || '?'));

            html += '<tr class="' + (open ? 'live' : '') + '">';
            html += '<td><div class="gs-usage-device">' + esc(row.device) + '</div></td>';
            html += '<td><div class="gs-usage-outlet">' + esc(label) + '</div>';
            html += '<span class="gs-usage-meta">' + esc(valve);
            if (metaParts.length) html += ' · ' + esc(metaParts.join(' · '));
            html += '</span></td>';
            html += '<td class="st"><span class="gs-status"><span class="gs-dot ' +
                (open ? 'on' : 'off') + '"></span>' + (open ? 'offen' : 'zu') + '</span></td>';
            html += '<td class="num">' + esc(lph(o.litersPerHour)) + '</td>';
            html += '<td class="num">' + esc(liters(o.today)) + '</td>';
            html += '<td class="num">' + esc(liters(o.week)) + '</td>';
            html += '<td class="num">' + esc(liters(o.year)) + '</td>';
            html += '<td class="num">' + esc(liters(o.total)) + '</td>';
            html += '<td class="num">' + (open ? esc(liters(o.session)) : '—') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
    }
    html += '</div>';

    root.innerHTML = html;
})();
