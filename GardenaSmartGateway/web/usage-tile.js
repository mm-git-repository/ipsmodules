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

    function totalBox(label, value) {
        return '<div class="gs-usage-total"><div class="k">' + esc(label) + '</div><div class="v">' +
            esc(liters(value)) + '</div></div>';
    }

    var totals = data.totals || {};
    var devices = data.devices || [];
    var html = '';
    html += '<div class="gs-usage-head"><div class="gs-usage-title">Wasserverbrauch</div>';
    html += '<div class="gs-usage-totals">';
    html += totalBox('Heute', totals.today);
    html += totalBox('Woche', totals.week);
    html += totalBox('Jahr', totals.year);
    html += totalBox('Gesamt', totals.total);
    html += '</div></div>';

    if (!devices.length) {
        html += '<div class="gs-usage-empty">Keine aktiven Ausgänge konfiguriert. Am Valve „Verbrauch zählen“ aktivieren und l/h setzen.</div>';
    }

    devices.forEach(function (device) {
        html += '<div class="gs-usage-device"><h3>' + esc(device.name || 'Gerät') + '</h3>';
        html += '<div class="gs-usage-outlets">';
        (device.outlets || []).forEach(function (o) {
            var open = !!o.open;
            html += '<div class="gs-usage-card' + (open ? ' live' : '') + '">';
            html += '<div class="name">' + esc(o.label || ('Ausgang ' + (o.side || '?'))) + '</div>';
            html += '<div class="meta"><span class="gs-dot ' + (open ? 'on' : 'off') + '"></span>' +
                (open ? 'offen' : 'zu') +
                ' · ' + esc(o.valveName || ('Ventil ' + (o.side || '?'))) +
                ' · ' + esc(String(o.litersPerHour || 0).replace('.', ',')) + ' l/h';
            if (o.length) html += ' · ' + esc(o.length);
            if (o.pressure) html += ' · ' + esc(o.pressure);
            html += '</div>';
            if (open) {
                html += '<div class="meta">Session: <strong>' + esc(liters(o.session)) + '</strong></div>';
            }
            html += '<div class="stats">';
            html += '<div>Heute<br><strong>' + esc(liters(o.today)) + '</strong></div>';
            html += '<div>Woche<br><strong>' + esc(liters(o.week)) + '</strong></div>';
            html += '<div>Jahr<br><strong>' + esc(liters(o.year)) + '</strong></div>';
            html += '<div>Gesamt<br><strong>' + esc(liters(o.total)) + '</strong></div>';
            html += '</div></div>';
        });
        html += '</div></div>';
    });

    root.innerHTML = html;
})();
