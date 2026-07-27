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
        if (n >= 100) return Math.round(n).toLocaleString('de-DE') + ' l';
        if (n >= 10) return n.toFixed(1).replace('.', ',') + ' l';
        return n.toFixed(2).replace('.', ',') + ' l';
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
    html += '<div class="gs-usage-totals">';
    html += totalBox('Heute', totals.today);
    html += totalBox('Woche', totals.week);
    html += totalBox('Jahr', totals.year);
    html += totalBox('Gesamt', totals.total);
    html += '</div>';

    html += '<div class="gs-usage-body">';
    if (rows.length) {
        html += '<table class="gs-usage-table">';
        html += '<thead><tr>';
        html += '<th>Ventil</th>';
        html += '<th class="num">Heute</th>';
        html += '<th class="num">Gesamt</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (row) {
            var o = row.outlet;
            var valve = o.valveName || o.label || ('Ventil ' + (o.side || '?'));
            html += '<tr>';
            html += '<td><div class="gs-usage-device">' + esc(valve) + '</div></td>';
            html += '<td class="num">' + esc(liters(o.today)) + '</td>';
            html += '<td class="num">' + esc(liters(o.total)) + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
    }
    html += '</div>';

    root.innerHTML = html;
})();
